# Phase P3 — Active / Inactive patient status: audit and implementation plan

Date: 2026-10-24. **Audit only. No code, schema, data or test changed in this run.**
Follows `patient-lifecycle-and-assignment-plan.md` (§1, §6) and P1 (messaging safety, uncommitted in the working tree).

## Environment

| Source | What was read | Production? |
|---|---|---|
| Local MySQL `oralcares_saas` @ 127.0.0.1:3307, `APP_ENV=local` | Aggregate counts and record ids/timestamps (no names, phones or clinical data) | **No** |
| Production | **Not accessible from this session. Not verified.** | — |

Every count below is **local development data** only.

---

## 1. Current status definitions and every write path

`patients.status`: `string`, `default('active')`, cast to `App\PatientStatus`, mass-assignable (`Patient::$fillable`).

| Value | Writers (all of them) | Readers that treat it specially |
|---|---|---|
| `active` | Column default. `App\Services\Patient\PatientRegistrationService:107, :160` (`'status' => 'active'`, via `PatientRegistrar`, the live path). `App\Services\PatientRegistrationService:48` (root namespace; **no callers**). `PatientFactory` default | `?? 'active'` fallbacks (6). `ClinicReports:149` dormant count (`= 'active'` only). `PatientList.tsx:89` hides the badge |
| `inactive` | **None in `app/`.** Only `PatientFactory::inactive()` (no test uses it) and `ClinicalDomainValidationService` (dead; tested only by `ClinicalDomainTest:137-138`) | None. It is in `working()`, so it behaves exactly like `active` everywhere. P1's `PatientMessageEligibility` allows it (`default => null`) |
| `deceased` | **None in `app/`** | In `working()`. P1 blocks messaging |
| `archived` | `Governance\Actions\ArchivePatient:75` | Policy update, Edit redirect, check-in, clinical entries, treatment, directory and search exclusion. P1 blocks messaging |
| `merged` | `PatientMergeService:120` | Patient 360 and Edit redirect, exclusion, duplicate detection. P1 blocks messaging |

**No other writer exists:**
- no request validates `status`, and `UpdatePatientRequest` / `PatientController::update` never touch it;
- `BackfillPatientFoundation` updates only `alternate_phone` and branch activity;
- `CaseNumberRaceCheck` and the browser seeders create patients with the default;
- there is no patient import.

**Other places status is read:**
- Directory filter validation and options (`PatientController:92,126`; `PatientDirectoryController:30`), which offer every case;
- `Patient360Summary:63` (identity status);
- the shared search index (`PatientSearchProvider:36` → `ent_search_documents.metadata.status`, stored as a string), filterable through `GET /api/search?status=` (`SearchRepository:90`);
- reports: `ClinicReports:149` (status) and `:125` (new registrations: `archived_at IS NULL`, which includes merged records, a separate finding);
- no exports.

## 2. Data inventory (LOCAL ONLY)

| Clinic | Status | Count | Ids |
|---|---|---|---|
| 32 (`demo-clinic`) | active | 3 | 9, 10, 12 |
| 32 (`demo-clinic`) | **inactive** | **1** | **11** |
| 32 (`demo-clinic`) | deceased | 1 | 13 |
| 47, 56, 57, 62, 68, 69, 70, 71 | active | 35 | 269–354 range |
| — | archived / merged | 0 | — |

Patients 11 and 13:
- were created in the same second as ids 9–12 (2026-09-25 05:04:35) and never updated;
- have no visits, appointments, bills or notifications;
- belong to `demo-clinic`.

**This is seeded demo data, not workflow output.**

The search index holds 24 patient documents locally (22 active, 1 inactive, 1 deceased). It is out of date against 40 patients, which is unrelated to this phase.

## 3. Does Inactive have a real production use?

**Not in the code.** No writer, no definition, no rule and no UI produces it. Every gate treats it as `active`. It is only declared, used in one factory state, and named in one dead service and its test.

**In production data: unknown.** A production row could only come from outside the application (a manual DB edit, an old import or a seed). That is why the production count must be taken before converting (§5).

## 4. Recommended final behaviour

1. **Keep `active` as the stored value of a current patient.**
   - It is the column default, the value registration writes, and the value every fallback assumes.
   - Renaming it to `current` would touch the default, the writers, six fallbacks, the search index, tests and any external reader, for no behavioural gain.
   - The UI calls the group **"Current patients"** and never shows an "Active" badge.
2. **Convert `inactive` → `active`, recording exactly which rows changed.**
   - This changes no behaviour (both are already "current" in every gate), except that the dormant-patients report would start counting them, which is correct.
   - Retaining `inactive` as a legacy value would keep a status with no meaning and invite new uses.
3. **After conversion, remove `PatientStatus::Inactive`**, along with `PatientFactory::inactive()` and the dead transition service (with test approval).
4. **Introduce `PatientStatus::current()`** = `active` + `deceased` (deceased stays listed with a badge until decision L1). Keep `working()` as a deprecated alias for one release.
5. **Deceased, Archived and Merged are unchanged.** Unrelated entities are out of scope: staff, doctor, branch, membership and specialty-enrolment statuses, and subscriptions, appointments and billing.

## 5. Migration safety

**Selection query (run in each environment first; ids only):**
```sql
SELECT id, clinic_id, created_at, updated_at FROM patients WHERE status = 'inactive' ORDER BY clinic_id, id;
```

**Conversion (proposed Laravel migration, data only, no schema):**
- **Up:**
  - Select the ids with the query above.
  - Write **one append-only `SecurityAudit` entry per clinic** (`patients.status_normalised`) listing those ids, `from: inactive`, `to: active` and a batch key. That is the rollback record, and no new table is needed.
  - Then `DB::table('patients')->whereIn('id', $ids)->where('status','inactive')->update(['status' => 'active'])`.
  - Use raw query-builder calls (no Eloquent enum cast), in chunks of 1,000. `updated_at` is deliberately left unchanged so the record's history isn't disturbed.
- **Down:** read the ids from the audit entries for that batch key and set exactly those rows back to `inactive` where they are still `active`. Down only works while the enum still has `Inactive`, so the case is removed in a *later* release (below).
- **Idempotent:** a re-run finds nothing to convert and writes no audit entry.

**Backup:** take the standard database backup immediately before deployment (the M0 backup plan). The audit entries alone are enough to undo the conversion.

**Release order (prevents enum crashes):**
1. **P3a — code, no data:**
   - add `current()`;
   - Directory status options become *Current patients* (default) and *Archived*, and the filter still **accepts** `inactive` and maps it to the current group for one release (old bookmarks and API calls keep working);
   - badges only for deceased, archived and merged;
   - the dormant report counts `current()` minus deceased and applies its branch filter;
   - P1 tests change their "inactive" case to "a legacy inactive record is still messaged" (kept until conversion).
2. **P3b — the conversion migration above,** then rebuild the search index for patient documents (`ReindexSearchCommand` or `indexModel` for the converted ids).
3. **P3c, after confirming `SELECT COUNT(*) … 'inactive'` = 0 everywhere:**
   - remove `PatientStatus::Inactive`, `PatientFactory::inactive()`, the dead transition service and its test (with approval);
   - stop accepting `?status=inactive` (422);
   - remove `working()`.

**Compatibility:**
- **APIs:** the Directory (page and JSON) gets one release of tolerance. `/api/search?status=inactive` returns no patients once reindexed, which is correct.
- **Search index:** reindex after P3b.
- **Reports:** dormant-count fix in P3a.
- **Queued jobs:** none store patient status. Notifications reference the patient id, and P1's eligibility already treats `inactive` as current.
- **Front end:** types narrowed in P3a. Nothing renders "Active" or "Inactive" after P3a.

## 6. Tests that would prove nothing breaks

- **Registration:** a new patient is stored `active`, listed under Current patients and shown without a badge.
- **Directory (page and JSON):**
  - the default list contains current patients only;
  - `status=archived` lists archived;
  - `status=inactive` (P3a) returns the current group, and (P3c) returns 422;
  - the options are Current patients and Archived.
- **Conversion migration:**
  - converts only `inactive` rows;
  - writes one audit entry per clinic with the exact ids;
  - leaves `updated_at` and every other status untouched;
  - a re-run is a no-op;
  - down restores exactly those ids.
- **Workflow regression:** existing suites for Patients, Reception (check-in), Appointments, Consultations, Treatments, Billing, Follow-ups, Governance (archive and merge), Communication and WhatsApp (P1), and Reports.
- **Report:** "Not seen for 6 months" counts a converted (formerly inactive) patient, excludes deceased, archived and merged, and respects the branch filter.
- **Search:** after reindex no patient document carries `inactive`.

## 7. Remaining risks

- **Production may hold `inactive` rows from outside the app.** The count is unknown until someone runs §5's query there.
- **Removing the enum case before every environment is converted would crash** any read of a leftover row. The P3a → P3b → P3c order prevents this.
- **Deleting `ClinicalDomainTest:137-138`** and the dead service needs your approval.
- **P1's test file includes an `inactive` dataset case.** It must be updated in P3a, not deleted.
- **Unrelated finding (not in P3 scope):** `ClinicReports:125` counts merged duplicates as new registrations (`archived_at IS NULL` doesn't exclude merged).

## 8. Exact next step

1. **You:** run the §5 selection query against production (or share the count), and approve (a) the audit-log-based conversion and (b) deleting `ClinicalDomainTest:137-138` together with the dead `ClinicalDomainValidationService`.
2. **Then implement P3a** (code only, no data): `current()`, the Directory options with temporary `inactive` tolerance, badges, the report fix, the P1 test update, and focused plus regression tests.
