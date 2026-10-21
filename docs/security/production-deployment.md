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
| Queue on Redis (`QUEUE_CONNECTION=redis`) | – | warn | required |
| `retry_after` above `queue.max_job_timeout` (600 s) | – | required | required |
| Cache, locks and scheduler mutex on Redis (`CACHE_STORE=redis`) | – | warn | required |
| Sessions on Redis (`SESSION_DRIVER=redis`) | – | warn | required |
| Private document disk opens (S3 adapter `league/flysystem-aws-s3-v3` installed) | – | required | required |

## Also required (outside the application)

- TLS certificates and an HTTP→HTTPS redirect at the load balancer.
- Managed MySQL with encryption at rest, automated backups and PITR.
- s3 buckets with public access blocked, SSE, versioning and object lock
  (backups).
- clamd with `freshclam`.
- A log collector and external store.
- A secret manager for `APP_KEY`, `BACKUP_ENCRYPTION_KEY`,
  `SECURITY_AUDIT_CHAIN_KEY`, and database and mail credentials.
- `php artisan schedule:run` every minute on every app server (each task
  takes a Redis lock, `onOneServer()`, so it runs once).
- Queue workers over the named queues (ADR-001 §J), `--timeout` below
  `retry_after`:

  ```
  # interactive pool
  php artisan queue:work redis --queue=critical,default,notifications,provisioning,search --timeout=300 --tries=1
  # long-job pool (imports, reports, maintenance)
  php artisan queue:work redis --queue=imports,reports,maintenance --timeout=600 --tries=1
  ```

  Restart workers on deploy (`php artisan queue:restart`).
- Load balancer readiness probe on `GET /health/ready` (database, cache,
  queue, private storage; 503 when any is down) and liveness on `GET /up`.
- Ship `storage/logs/performance*.log` (slow queries, over-budget requests,
  queue backlog) and `metrics*.log` (counters/timings), or set
  `PERFORMANCE_LOG_DRIVER` / `METRICS_LOG_DRIVER` to a shipped channel.
  Thresholds: `config/observability.php`.
- `DB_READ_HOST` (replicas) and `DB_REPORTING_CONNECTION=reporting` once read
  replicas exist.
- No demo clinics or test accounts in production data.
- An independent penetration test before large-scale rollout.
