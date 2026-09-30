# Rate limits

Limits are per minute. Numbers are in `config/security.php`
(`RATE_LIMIT_*`) and the limiters in `AppServiceProvider`.

| Limiter | Routes | Key | Limit |
| --- | --- | --- | --- |
| `ClinicLoginRequest` | `POST /clinic/login`: failed passwords | email + IP | 5, then locked 60 s |
| `clinic-login` | `POST /clinic/login` | IP | 30 |
| `clinic-password` | password setup / forgot / reset POSTs | IP | 10 |
| forgot-password emails | `POST /clinic/password/forgot` | email | 3 per 15 min |
| `clinic-patient-lookup` | `/clinic/search/patients`, `/clinic/patients/check-duplicate`, `/clinic/reception/check-in/search` | user | 60 |
| `api-global-search` | `/api/search` | user | 60 |
| `api-notifications` | `/api/notifications/*` | user | 120 |
| `api-geo` | `/api/v1/geo/*` | user | 120 |

The API and lookup limits are keyed per user, never per clinic or globally.
A busy reception desk in one clinic cannot throttle another clinic, and one
group's budget does not use up another's. Authentication runs before any
budget is spent. A throttled request gets `429` with `Retry-After`.

Tests: `ApiRateLimitTest`, `ClinicAuthTest`.
