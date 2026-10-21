# ADR-001 — Canonical Platform Architecture for 100k Clinics

**Status:** Accepted 2026-10-02 (items marked *owner confirmation* need a product decision before the dependent module is built) · **Inputs:** `2026-10-02-platform-scale-audit.md`

This record fixes one answer per concept so new work never creates a parallel model. It is shard-ready by construction and deliberately does **not** introduce physical sharding (§P).

---

## A. Tenant hierarchy

```
Organization  — the customer / legal & commercial entity (contracts, plans, invoices from OralCares)
  └─ Clinic   — THE TENANT: isolation boundary for all clinical, patient, staff and operational data;
  │            owns a subdomain; the future shard key
  └─ Branch   — operational unit of a clinic: queues, rooms, schedules, cash, invoice series,
               and the Spatie permission team
```

Rules:
1. Every clinic-owned row carries `clinic_id` (NOT NULL, FK, leading column of its hot indexes). Branch-operational rows also carry `branch_id`.
2. An organisation never grants clinical access by itself. Two clinics of one organisation are isolated unless a future, explicit, consented sharing feature says otherwise.
3. `organization_id` on clinic data is a denormalised reporting column, never an access boundary.
4. HQ (company users, master admins) sees tenant **usage** (counts, volumes, storage) — never patient or clinical content.

## B. Canonical clinic / branch context

- **One context object:** `App\Services\ClinicContext` (container-scoped, reset per request and per job).
- **Web:** `ResolveClinicContext` → `SetCurrentBranch` (Spatie team) before route-model binding.
- **Jobs:** carry `clinic_id` (and `branch_id`) only, and run inside `ClinicContext::runForClinic()` via `RunsForClinic`. Deliberately cross-tenant jobs wrap their body in `withoutTenancy()` so the intent is greppable.
- **Commands:** `RunsPerClinic` (`--clinic=` / `--all-clinics`), iterating clinics lazily.
- **Organisation for clinic users** is the active clinic's `organization_id` (fixes multi-organisation members); `users.organization_id` applies only to HQ users.
- Branch selection: `requireBranch()` for branch work, `branchScopeOrAbort()` for lists; never fall back to the main branch.

## C. Canonical practitioner model

`users` (login) → `clinic_memberships` (access) → `clinic_employees` (clinical identity; every `practitioner_id` / `doctor_id` FK points here) → `doctor_profiles` via `App\Models\Practitioner` (1:1 profile: registration, qualifications, fee, specialty) → `practitioner_schedules` (per branch, per weekday) + `practitioner_services`.
Retired: `DoctorProfile` (alias only), `doctor_availabilities`, `availabilities`, `provider_id` columns. Registration numbers are stored so HPR (ABDM) identifiers can be attached later.

## D. Canonical patient model

`patients` (clinic-scoped, UHID unique per clinic, issued by `NumberingEngine`). Satellites — `patient_profiles`, `patient_medical_histories`, `patient_alerts`, `consent_records`, `patient_procedures` — carry `clinic_id` derived from the patient (`InheritsClinicOwnership`). Visits: `patient_visits` (clinic + branch). Case numbers: `case_numbers` per clinic. ABHA number is an attribute of the patient, never a key.

## E. Canonical encounter model

`clinical_encounters` (clinic NOT NULL, branch, patient, practitioner = `clinic_employees.id`) is the anchor for every clinical act. Children — diagnoses, notes, prescriptions (+items), orders (+items), procedures, consultations — hang off the encounter and inherit its clinic. **Specialty data is an extension, not a fork:** dental charting (`patient_dental_charts`, tooth histories) and physiotherapy assessments are child records keyed by encounter + patient and enabled by the specialty registry (config), never by `if (dental)` branches in core code.

## F. Canonical appointment model

`appointments` (+ `appointment_series`) — clinic + branch + practitioner (`clinic_employees`) + optional patient (prospects allowed) + service + room. Check-in turns an appointment into a `patient_visit` and a `clinic_queue_items` row. No-show closing is done by `queue:close-day` per branch. Availability derives from `practitioner_schedules`, leave and branch hours.

## G. Canonical invoice / payment model — *owner confirmation*

Clinic billing builds on the **`patient_billings` family** (`patient_billings`, `patient_billing_items`, `patient_billing_payments`, `patient_billing_refunds`): it is clinic- and branch-aware, which GST invoice series require. Before the Billing module ships: items/payments/refunds gain `clinic_id`; numbers come from `NumberingEngine` per branch per financial year; `invoices`/`invoice_items` become read-only legacy and Patient 360 moves to the canonical family. OralCares' own commercial billing (`commercial_invoices`, `commercial_payments`, `payments`) is a separate Company-side domain and never mixes with clinic billing. *This was previously deferred by the owner; it is recorded here as the canonical recommendation and must be confirmed before Billing UI work starts.*

## H. Canonical investigation model

Order → fulfilment → result:
- `clinical_orders` (+ `clinical_order_items`) is the generic investigation order (lab, imaging, external referral), encounter-anchored.
- Fulfilment records are typed: `xray_records` for imaging, `lab_orders` for prosthetic/dental-lab work orders (a work order, not a diagnostic test).
- `diagnostic_results` holds results against the order item.

## I. Canonical inventory model

`inventory_items`, `inventory_batches`, `inventory_transactions` (ledger, append-only), `inventory_purchase_orders`, `inventory_vendors`, `inventory_transfers`, `inventory_reservations`. `stock_*` tables are legacy and receive no new code. Stock is per branch; the ledger is the source of truth and balances are derived.

## J. Queue topology

| Queue | Purpose | Priority |
|---|---|---|
| `critical` | password reset / invitation mail, security notifications | 1 |
| `default` | short domain jobs | 2 |
| `notifications` | patient/staff messages (mail, WhatsApp, SMS) | 3 |
| `provisioning` | tenant provisioning (already in use) | 3 |
| `search` | search index writes | 4 |
| `imports` | CSV/lead imports (long) | 5 |
| `reports` | queued report generation, exports | 5 |
| `maintenance` | sweeps, snapshots, pruning | 6 |

Rules: Redis in production (database driver only for dev); `retry_after` greater than the longest job `timeout` on its connection; `after_commit = true`; every job declares `tries` + `backoff`; payloads carry ids, never models with PHI and never secrets (`ShouldBeEncrypted` where a bearer link is unavoidable); idempotency via `ShouldBeUnique`, unique idempotency keys or natural-key upserts; per-tenant work runs in `runForClinic`; large fan-out uses chunked dispatch, never one process looping 100k tenants. Queue names live in `config/queue.php` (`queues` map) so they can be re-pointed without code edits.

## K. Cache key strategy

- Tenant keys: `t:{clinic_id}:{domain}:{key}` built only through `App\Support\TenantCache`, which refuses to build a key without a clinic.
- Global keys: `g:{domain}:{key}` (geo, plans, specialty registry).
- Invalidation: per-tenant version counter (`t:{clinic_id}:v`) folded into keys — bumping it invalidates a tenant without tags.
- Locks: `lock:t:{clinic_id}:{purpose}`; scheduler locks via `onOneServer()`.
- Production store: Redis (cache, sessions, locks, Spatie permission cache).

## L. Search strategy

`SearchRepository` is the only reader of the cross-entity index, and it scopes by construction: clinic users → active clinic + clinic document types + permission; HQ → HQ document types only. Patient lookup is tenant-bounded: `clinic_id` first, then word-prefix name match or prefix phone/UHID/case number, so its cost depends on the clinic's size, never the platform's. A platform-wide FULLTEXT index is never used for tenant search (measured: its cost grows with every tenant's rows). Next step (P2/P3): an external engine (OpenSearch/Meilisearch) behind the same interface, with `clinic_id` as a mandatory filter and separate HQ and clinical indexes.

## M. File-storage strategy

All patient and staff files on the `private` disk (S3-compatible object storage in production; requires `league/flysystem-aws-s3-v3`, *owner approval*). Paths start with the owning clinic (`clinic_{id}/patients/{patient_id}/documents/…` for private files, `clinics/{id}/…` for public logos), so a tenant's files can be listed, exported, moved or purged by prefix. Uploads land in quarantine, are malware-scanned, then promoted. Downloads only through authorized controllers or short-lived signed URLs. Nothing patient-related on the `public` disk.

## N. Reporting strategy

- In-clinic operational figures: bounded date ranges on tenant-leading indexes.
- Heavy reports/exports: queued on `reports`, executed on the `reporting` connection (defaults to the primary; points at a replica when `DB_REPORTING_HOST` is set), delivered as files.
- Trends: daily pre-aggregates (`analytics_snapshots`, `usage_metrics`) written by maintenance jobs.
- HQ reporting reads usage aggregates only.

## O. Audit strategy

`security_audit_logs` (append-only, HMAC hash chain, request id) for access, identity, clinical and financial changes; shipped to a remote log channel; ≥180 days hot retention; monthly partitioning once volume warrants. Clinical rows are never hard-deleted by users; account closure is deactivation; FKs from clinical history to `users` are `restrict`, not `cascade`.

## P. Future shard / database routing

- Shard key: `clinic_id`. Prerequisites (enforced now): every clinic row carries `clinic_id`; no cross-clinic joins in clinic code; jobs carry `clinic_id`.
- `App\Contracts\TenantConnectionResolver` returns the connection for a clinic; the default implementation returns the primary connection for every clinic. Central tables (`users`, `organizations`, `clinics`, plans, HQ CRM) stay on the central connection.
- **Do not shard yet.** Re-evaluate when any of these holds on measured production data: primary write QPS sustained > 60% of instance capacity after vertical scaling and read replicas; a single table > ~2 TB or > 5 B rows; backup/restore time beyond the RTO; or a data-residency requirement.
