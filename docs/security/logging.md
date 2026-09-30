# Centralised, tamper-evident security logging

```
security event ─► SecurityAudit::record()
                   ├─► security_audit_logs   (database, append-only, HMAC hash chain)
                   └─► Log::channel('security') ─► syslog / stderr ─► collector ─► external log store
```

## Local copy: `security_audit_logs`

- The application refuses to update or delete a row (`SecurityAuditLog`
  throws).
- **Hash chain:** `entry_hash = HMAC-SHA256(content + prev_hash)`, keyed with
  `SECURITY_AUDIT_CHAIN_KEY` (falls back to `APP_KEY`). A row edited, deleted
  or inserted directly in the database breaks the chain.
  `php artisan security:verify-audit-chain` reports the first broken entry.
  JSON key order is normalised, because MySQL reorders it.
- No foreign keys on actor, clinic or branch, so deleting those records never
  cascades into the trail.
- Chain appends take a row lock on the newest entry. Writes are serialised.
  This is fine at current volume; revisit if audit writes become a
  bottleneck.

## Off-box copy: the `security` log channel

Every entry, including its `entry_hash`, is also written to the `security`
channel. It is written even if the database write fails.

| `SECURITY_LOG_DRIVER` | Use |
| --- | --- |
| `daily` (default) | Local development: `storage/logs/security.log` |
| `syslog` | Production. Facility `auth`, ident `oralcares-security`. Forward with rsyslog / journald. |
| `stderr` | Production on containers. JSON lines, collected by the platform. |

**Deployment work (not done here):** forward these to a store the app
servers cannot rewrite, for example CloudWatch Logs with a restricted IAM
role, Loki or Elastic with append-only credentials, or an S3 bucket with
object lock. Retain them, alert on `auth.lockout` spikes, `auth.refused`
bursts and `document.malware_blocked`, and correlate by `request_id`.

## Retention

`SECURITY_AUDIT_RETENTION_DAYS` defaults to 180. This is the working target
from the CERT-In directions (April 2022). Counsel must confirm the actual
legal requirement before it is represented as one. The external store must
enforce its own retention.

The production check fails when the channel is not `syslog`/`stderr` or when
retention is below 180 days.
