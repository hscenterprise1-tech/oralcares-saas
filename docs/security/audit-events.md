# Security audit events

Written by `App\Services\Security\SecurityAudit::record()` to
`security_audit_logs` (append-only, hash-chained) and to the `security` log
channel (see [logging.md](logging.md)).

Every entry records: event, actor user, clinic, branch, subject type and id,
IP, user agent, request id (`X-Request-Id`), context, and time.

**Never recorded:** passwords, reset or setup tokens, invitation secrets, API
keys, session data. Keys matching password, token, secret, otp, pin, hash,
key, credential, authorization or cookie are removed from context at any
depth. **Clinical content is never copied into the log.** Personal and
clinical fields are recorded by name only. Status and money fields keep
their before and after values.

## Catalogue

| Area | Events |
| --- | --- |
| Authentication | `auth.login`, `auth.logout`, `auth.failed` (email tried), `auth.lockout`, `auth.refused` (reason), `auth.password_link_issued`, `auth.password_set`, `auth.password_reset_requested` |
| Invitations | `invitation.created`, `invitation.revoked`, `invitation.accepted` |
| Access control | `access.roles_changed` (per branch, before/after), `access.branches_changed`, `membership.status_changed`, `clinic.status_changed` |
| Patients | `patient.created`, `patient.updated` (`personal_fields_changed`), `patient.deleted`, `patient.viewed`, `patient.clinical_viewed`, `patient.billing_viewed` |
| Encounters | `encounter.created`, `encounter.updated`, `encounter.completed`, `encounter.amended` (change after completion), `encounter.deleted` |
| Prescriptions | `prescription.created`, `prescription.updated`, `prescription.finalized`, `prescription.deleted` |
| Billing (clinic) | `billing.invoice.created/updated/deleted`, `billing.invoice.cancelled`, `billing.invoice.refunded`, `billing.invoice.adjusted` (discount), `billing.payment.created`, `billing.refund.created` |
| Consent | `consent.created`, `consent.updated`, `consent.approved`, `consent.revoked` |
| Documents | `document.uploaded`, `document.downloaded`, `document.malware_blocked`, `document.scan_failed` |
| Backups | `backup.succeeded`, `backup.failed`, `backup.verified`, `backup.verification_failed`, `backup.pruned` |
| Subscriptions | `subscription.backfilled` |

**Reserved, not yet emitted.** No such feature exists yet, so these are
listed rather than faked:
- `encounter.signed`: there is no sign-off column yet.
- `export.patients`, `export.report`, `export.bulk`: there are no export
  features yet.

Each of these must call `SecurityAudit::record()` when its feature ships.

Clinical and financial events come from `ClinicalAuditObserver`
(registered in `AppServiceProvider`). Clinic Company CRM `payments` are
deliberately not part of this catalogue.

Tests: `ClinicalAuditTest`, `SecurityControlsTest`, `AuditTrailIntegrityTest`.
