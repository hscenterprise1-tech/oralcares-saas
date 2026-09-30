# Child-model ownership and practitioner scope

Clinic-data models without `ClinicScoped` of their own, classified from their
actual columns and foreign keys (2026-09-29).

**A** = safe through its parent today. **B** = must get an explicit
ownership check or scope before any controller exposes it by id.
**C** = has its own `clinic_id`/`branch_id`; add `ClinicScoped` before
exposing it. **D** = not clinic data.

| Model | Ownership path | Class | Exposed by id today? |
| --- | --- | --- | --- |
| ClinicQueueItem | queue → clinic_queues.clinic_id/branch_id | A | yes, guarded by `ReceptionController::assertOwned` |
| ClinicRoom | branch_id → branches.clinic_id | C (branch) | yes, guarded in `RoomController` |
| BranchSetting | branch_id → branches.clinic_id | C (branch) | no |
| CaseNumber, CaseNumberHistory, CaseNumberSequence | clinic_id | C | no |
| ClinicSettingAudit | clinic_id | C | no |
| ClinicalProcedurePrice | clinic_id | C | no |
| DoctorProfile | clinic_id (inherits `ClinicScoped` via `Practitioner`) | scoped | no |
| PatientDoctorAssignment | clinic_id, patient_id | C | no |
| QueueHistory | clinic_id, branch_id | C | no |
| InventoryItem, InventoryVendor, InventoryTransfer | clinic_id | C | no |
| InventoryBatch, InventoryPurchaseOrder, InventoryTransaction | clinic_id, branch_id | C | no |
| LabMaterial, LabProsthetic, LabShade | clinic_id | C | no |
| ClinicalConsultation | encounter → clinical_encounters.clinic_id | A | no |
| ClinicalOrderItem | order → clinical_orders | A | no |
| PrescriptionItem | prescription → prescriptions.clinic_id | A | no |
| PatientAlert, PatientToothStatusHistory, TreatmentRecord | patient → patients.clinic_id | A | no |
| TreatmentMaterial, TreatmentProgressNote | treatment_record → patient | A | no |
| XrayAttachment | xray_record → patient | A | no |
| LabCaseTrial, LabOrderAttachment | lab_order | A | no |
| InventoryReservation | batch → inventory_batches.clinic_id | A | no |
| PatientBillingItem, PatientBillingPayment, PatientBillingRefund | billing → patient_billings.clinic_id | A | no |
| **InvoiceItem** | invoice → invoices.**organization_id** (no clinic) | **B** | no |
| **InventoryPurchaseOrderItem** | **no foreign key at all** | **B** | no |
| StockBatch, StockTransaction (legacy) | item/location | B (slated for removal) | no |
| Payment | Company CRM (lead / quotation) | D | n/a |

## Rules

- `RouteModelOwnershipTest` fails if any `/clinic` route binds a model that
  is neither `ClinicScoped` nor listed with its ownership check. Exposing a
  class B/C model therefore forces a decision.
- An A-class child is loaded through its scoped parent, for example
  `$prescription->items()->findOrFail($id)`, never `PrescriptionItem::find($id)`.
- `InvoiceItem` must not be exposed until clinic invoices carry `clinic_id`
  (the billing-model decision is still open). `InventoryPurchaseOrderItem`
  needs a foreign key before any use.

## Practitioner / data scope: current state

- A patient is readable by anyone in the clinic with `patient.view`
  (`PatientPolicy::view`: clinic + permission). There is **no branch or
  assignment restriction on reading**.
- "My patients" (`current_practitioner_id`, `PatientDoctorAssignment`) is a
  list filter, not an authorization rule.
- The appointment calendar limits which practitioners' columns a user sees
  (`visiblePractitioners`).
- Practitioner identity is split across `clinic_employees` (the person) and
  `doctor_profiles` (clinical profile). `clinical_encounters` references
  both (`doctor_id`, `provider_id`).

## Where practitioner scope will be required

Each is to be decided per clinic setting, not hard-coded:

1. Clinical notes, diagnoses and prescriptions: edit or sign only by their
   author or the treating practitioner.
2. Encounter workspace: open only a patient in the practitioner's queue or
   assignment, unless they hold a broader permission.
3. Patient 360 clinical tab: an optional "treating practitioners only"
   clinic setting.
4. Doctor queue and call-next: already bound to the calling doctor.
5. Reports: a practitioner sees their own figures unless they hold
   `reports.view`.
6. Branch restriction on patient reads for multi-branch clinics that want
   it.

Before any of this, unify the practitioner model (one person record plus a
clinical extension), so a single id carries the scope.
