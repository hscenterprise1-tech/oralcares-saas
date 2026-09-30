# OralCares security foundation

Implementation notes for the controls that protect clinic data. Each file is
short and names the code it describes. Nothing here is a certification.
"Implemented" means the code exists and is tested in this repository.

| Topic | File |
| --- | --- |
| Tenant context: web, queued jobs, console | [tenant-context.md](tenant-context.md) |
| Security audit event catalogue | [audit-events.md](audit-events.md) |
| Centralised, tamper-evident security logging | [logging.md](logging.md) |
| Malware scanning of uploads | [malware-scanning.md](malware-scanning.md) |
| Database backup and restore testing | [backups.md](backups.md) |
| Production deployment requirements | [production-deployment.md](production-deployment.md) |
| API rate limits | [rate-limits.md](rate-limits.md) |
| Subscriptions and plan entitlements | [entitlements.md](entitlements.md) |
| Child-model ownership matrix and practitioner scope | [child-model-ownership.md](child-model-ownership.md) |

Tests for all of the above live in `tests/Feature/Clinic/Security/`.
