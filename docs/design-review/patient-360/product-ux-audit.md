# Patient 360 — product UX audit and information architecture

2026-10-26. Scope: Patient 360 header and Overview (`PatientHeader.tsx`, `Overview.tsx`, `Show.tsx`), their payload (`Patient360Controller@show`, `Patient360Summary`, `Patient360SectionPolicy`) and the settings they depend on. Inputs: the code, the schema, the two review screenshots (sparse patient as Owner, full QA patient as Billing), the stress screenshots in `qa/stress/after/`, and `functional-gap-audit.md`.

Product decision applied (from the review): **Patient 360 shows no Allergies panel, heading or summary.** Allergy data is not touched (see §6, risk R1).

## 1. Card-by-card data map (before)

Roles: O owner · D doctor · R reception · B billing. "Clinical" = `ClinicAccess::canViewClinical` (O, D); "money" = `canViewBilling` (O, B); "contact" = `PatientDirectoryAudience::canSeeContact` (O, R, D with clinical edit).

| # | Card / panel | Shows | Source | Value | Roles | Verdict |
|---|---|---|---|---|---|---|
| H1 | Identity | name, UHID, age·sex·blood group, lifecycle badge | `patients` via `identity()` | Essential | all | **Keep**; shorter name type and avatar so the header is not tall |
| H2 | Facts row | mobile (masked/reveal), email, assigned doctor, registered (date·branch), last visit (date·branch) | `patients`, `patient_branch_activity`, `patient_visits` | Operational | all; full mobile/email by contact rule | **Keep**; values wrap, never truncate |
| H3 | Allergies panel | every active allergy as a dark-red pill | `patient_clinical_entries` kind `allergy` | Clinically vital, but visually dominant and duplicated as derived `patient_alerts` type `allergy` | all | **Remove from Patient 360** (decision). Data kept; Print keeps them |
| H4 | Alerts panel | `patient_alerts` except type `allergy`, high/critical as dark pills | `patient_alerts` (types `flag` from the clinic's alert catalogue, `manual`, and derived `medical_condition`) | Mixed: true precautions **and** conditions copied in by `syncDerivedAlerts` (a high/critical condition becomes an alert), and in practice preferences stored as low alerts | all | **Simplify** to "Safety alerts": only `flag`/`manual` alerts, most severe first, two shown, colour only for high/critical, rest in View all. Derived `medical_condition` alerts are not shown here (the condition itself is shown under Medical conditions). No data is reclassified |
| H5 | Notes panel | medical-history free text, clamped | `patient_medical_histories.notes` | Clinical, often long | clinical | **Simplify**: one-line preview + View more (full text, last updated) |
| — | Medical conditions | (not in header) diagnosed + reported conditions were in an Overview card | reported: clinical entries kind `condition`; diagnosed: `clinical_diagnoses` | Conditions (diabetes, BP…) are safety-relevant for every treatment decision | clinical | **Move to header** as compact "Medical conditions": the patient's recorded medical history (entries kind `condition`), up to 4 chips + View all + Manage (clinical write) |
| O1 | Care under way | count, first treatment, start date, ring | `TreatmentReadModel::forPatient` | Useful | all (amounts only money roles) | **Merge** with O5 into one "Treatments" card listing up to 3; ring/bar only when a treatment has more than one stage/session (a one-stage item shows its status, not "0%") |
| O2 | Coming up | next appointment, follow-up, link to Appointments | `appointments`, `patient_follow_ups` | Operational | all | **Merge** with O6: next appointment, separately labelled follow-up task, counts, links |
| O3 | Recent activity (summary) | latest consultation | `clinical_encounters` | **Redundant** with O4's first row | clinical | **Remove** |
| O4 | Billing (summary) | outstanding, unpaid count | `patient_billings` | Operational | money | **Merge** with O7 into one "Billing" card |
| O5 | Recent clinical activity | latest consultation, prescription, result, procedure as a timeline | encounters, prescriptions, order items, procedures | Clinical, meaningful | clinical | **Keep** (max 4 entries, dates readable, no side columns) |
| O6 | Conditions and medicines | diagnoses (12 months) + reported conditions + prescribed (30 days) + reported medicines | as above | Mixes medical history with consultation diagnoses | clinical | **Split**: medical history conditions → header; this card becomes **"Diagnoses and medicines"** (consultation diagnoses + current medicines, 3 each, View all) |
| O7 | Treatment summary | same treatments as O1 | same | **Redundant** | all | merged into "Treatments" |
| O8 | Appointment summary | next appointment, follow-up, counts | same as O2 | **Redundant** | appointments | merged into "Coming up" |
| O9 | Financial summary | billed, paid, outstanding, unpaid numbers | `patient_billings` | Detail for O4 | money | merged into "Billing", with a working **View bills** link (Billing register filtered by UHID, only for members the register admits) |
| O10 | Patient information | address, alt. mobile, channel, emergency contact, registered | `patient_profiles` | Operational | all; contact rule | **Keep**; "Registered" removed (already in the header) |
| O11 | Documents and reports | latest 4 the member may open, preview/download | `attached_documents` via `Patient360Sections::documents` | Useful | per Documents section policy | **Keep**; no View all until the Documents section is built (gap G4) |

Missing / long / stale / inaccessible behaviour (after): empty values say what is missing in one quiet line; long text wraps (no single-line truncation); a card the member may not see is not sent and not drawn; stale data is dated (conditions "recorded …", notes "last updated …", diagnoses "noted …").

## 2. Information architecture (after)

```
Header   Identity · actions (permission-aware)                          [Check in] [Edit] [Print] [More]
         Facts: Mobile · Email · Assigned doctor · Registered · Last visit
         Safety strip (one compact bordered row, no colour blocks):
           Medical conditions (clinical)  ·  Safety alerts (everyone)  ·  Notes (clinical)
Tabs     Overview · Visits & History
Overview Row 1  Treatments · Coming up · Billing (money roles)      — 3 compact cards, 2 per row below 1280
         Row 2  Recent clinical activity · Diagnoses and medicines  — clinical roles
         Row 3  Patient information · Documents and reports
```

Cards drop from 11 to 7 (owner), 5 (reception/billing). Per role:

| Role | Header strip | Row 1 | Row 2 | Row 3 |
|---|---|---|---|---|
| Owner | conditions, alerts, notes | Treatments, Coming up, Billing | yes | info, documents |
| Doctor | conditions, alerts, notes | Treatments, Coming up | yes | info, documents |
| Reception | alerts | Treatments, Coming up | — | info, documents |
| Billing | alerts | Treatments, Coming up, Billing | — | info (contact masked) |

## 3. The four kinds of clinical information

| Kind | Stored in | Shown | Edited |
|---|---|---|---|
| Allergies | `patient_clinical_entries` kind `allergy` (+ derived `patient_alerts` type `allergy`) | **not on Patient 360** (decision); Print, intake safety flags, directory | registration/New Case; `PUT …/clinical-entries/allergy` |
| Medical conditions | entries kind `condition` (catalogue key or typed name, severity, source, recorder, date) | header chips, View all with severity, "reported at registration" vs "recorded by clinical staff", date | **new**: Manage conditions dialog → existing `PUT /clinic/patients/{p}/clinical-entries/condition` (`writeClinicalData`, branch, not merged; omitted items are resolved, not deleted; timeline event) |
| Safety alerts | `patient_alerts` types `flag` (clinic alert catalogue) and `manual` | header, most severe first, two + View all; colour only for high/critical | registration (catalogue) — no Patient 360 editor yet (gap G2) |
| Clinical notes | `patient_medical_histories.notes` | header one-line preview + View more | none yet (gap G3) |

Diagnoses from consultations (`clinical_diagnoses`) are a fifth thing: what a clinician concluded at a visit. They stay with the clinical record (Overview row 2), not in the header.

## 4. Clinic configuration (audit result: no new engine needed)

The clinic catalogue already provides it: `ClinicalCatalogue` (kinds `condition`, `allergy`, `alert`, `medication`, …) over `DefaultClinicalCatalogue` defaults (Diabetes, Hypertension, Asthma, heart conditions… with default severities), per-clinic `clinical_catalogue_items` to rename, disable, re-rate or add items, scoped by specialty, edited at Admin → Settings → catalogue (`admin.settings.catalogue.*`, audited). Safety-alert categories are the `alert` kind of the same catalogue. Tenant-safe: defaults are global, overrides are per clinic.

Smallest safe additions (not built in this change): (a) a per-clinic switch "allow typed condition names" — today `PatientClinicalEntries::prepare` accepts a typed name, which bypasses clinic approval; (b) an alert *category* (precaution / communication / preference) on catalogue alert items so preferences stop being stored as alerts. Both are catalogue fields plus a migration that defaults existing rows to their current meaning — nothing is reclassified silently.

## 5. Dental and Physio

The header and Overview use shared concepts only. Module-specific content comes from data the entitlement filter already gates: treatment titles from the clinic's catalogue, "Tooth N" only on a dental procedure, "Start session" for the physio persona (`Patient360SectionPolicy::actions`), Dental/Physio sections only when `fields.dental` / `fields.physio` (specialty products of the plan). Nothing on the Overview is dental-only UI; no change needed.

## 6. Risks and functional gaps

- **R1 Allergies are no longer on screen in Patient 360.** Prescribing has no server-side allergy check (`ClinicalPrescriptionService` reads none). Recommended next slice: allergy check and acknowledgement at prescribing, and an allergy line in the consultation workspace.
- **G1** No Documents section → no "View all documents".
- **G2** No alert editor in Patient 360 (alerts recorded at registration only; `MedicalHistoryService` manual path is legacy).
- **G3** No medical-notes editor; notes have no author.
- **G4** Treatments, Follow-ups, Billing sections are backend-only; the cards link only to built destinations (Visits & History tab, Appointments page, Billing register).
- **G5** Typed condition names bypass clinic approval (config switch above).

## 7. Implemented (2026-10-26) and verified

- Header: compact identity (smaller avatar and name), facts unchanged, the three coloured panels replaced by `SafetyStrip` (conditions · alerts · notes) with View all / View more dialogs and a Manage conditions editor on the existing clinical-entries endpoint. No Allergies panel.
- Overview: 7 cards (owner/doctor), 5 (reception), 3–4 (billing). "Coming up" is not drawn for a member who may not see appointments unless a follow-up exists (it used to say "None booked" to billing staff for a patient with a booking).
- Server: `context.medical_conditions`, `context.medical_notes_updated_on`, alerts without derived copies and sorted by severity, `condition_editor`, `links.bills`; `PatientClinicalEntry::recorder()`. No migrations.
- QA: 3 patients (359 full, 360 typical, 287 sparse) × 4 roles × 1440/1024/768/390/360 → `qa/stress/ux-after/` (before: `qa/stress/after/`), no overflow, clipping or console errors; dialogs checked by keyboard (`dialog-check-1440-owner.png`).
