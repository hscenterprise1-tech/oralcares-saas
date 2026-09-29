# Production deployment requirements

Run before every deploy, and stop the deploy on failure:

```
php artisan security:production-check                  # target: production
php artisan security:production-check --target=staging
```

A check marked for production only is a WARN on a staging target.
Local development is expected to fail most checks.

| Check | Local | Staging | Production |
| --- | --- | --- | --- |
| `APP_ENV` matches target | – | required | required |
| `APP_DEBUG=false` (production also forces it off at boot) | – | required | required |
| `APP_KEY` set | required | required | required |
| `APP_URL` is https | – | required | required |
| `SESSION_SECURE_COOKIE=true`, HttpOnly, SameSite lax/strict | – | required | required |
| Server-side shared sessions (`database`/`redis`) | – | required | required |
| Real mail (`MAIL_MAILER` ≠ log/array) | – | required | required |
| Queue off-request (`QUEUE_CONNECTION` ≠ sync) + a running worker | – | required | required |
| Real malware scanner (`MALWARE_SCANNER=clamav`) answering PING | – | required | required |
| Default disk not `public` | required | required | required |
| Patient documents on s3 (`PRIVATE_FILESYSTEM_DRIVER=s3` + bucket) | – | warn | required |
| Valid `BACKUP_ENCRYPTION_KEY` | – | required | required |
| Backups on s3 in a separate account | – | warn | required |
| `BACKUP_VERIFY_CONNECTION` set | – | warn | required |
| Successful backup < `BACKUP_MAX_AGE_HOURS` (26) | – | warn | required |
| Passing restore test < `BACKUP_MAX_VERIFICATION_AGE_DAYS` (35) | – | warn | required |
| `SECURITY_LOG_DRIVER` syslog/stderr, shipped off-box | – | warn | required |
| `SECURITY_AUDIT_RETENTION_DAYS` ≥ 180 | – | required | required |
| Master-admin seed password ≥ 16 chars, not a default | – | required | required |
| Every active clinic has a subscription | – | warn | required |

## Also required (outside the application)

- TLS certificates and an HTTP→HTTPS redirect at the load balancer.
- Managed MySQL with encryption at rest, automated backups and PITR.
- s3 buckets with public access blocked, SSE, versioning and object lock
  (backups).
- clamd with `freshclam`.
- A log collector and external store.
- A secret manager for `APP_KEY`, `BACKUP_ENCRYPTION_KEY`,
  `SECURITY_AUDIT_CHAIN_KEY`, and database and mail credentials.
- A queue worker and `php artisan schedule:run` every minute.
- No demo clinics or test accounts in production data.
- An independent penetration test before large-scale rollout.
