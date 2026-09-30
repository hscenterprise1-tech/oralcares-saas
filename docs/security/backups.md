# Database backup and restore testing

## Layers

| Layer | Provides | Owner |
| --- | --- | --- |
| Managed MySQL automated backups + binlog point-in-time recovery | RPO ≤ 15 min | **Infrastructure (deployment work)** |
| `backup:run`: encrypted logical dump to an independent bucket | Independent copy, 35 daily + 12 monthly | This application |
| `backup:verify`: restore into a scratch DB and compare | Proof a backup restores | This application |
| Object-storage versioning + object lock on documents and backups | Protection against deletion or ransomware | **Infrastructure** |

Targets: **RPO ≤ 15 minutes** (from managed PITR; a daily dump alone gives
~24 h) and **RTO ≤ 4 hours**.

## `backup:run` (daily 01:30, production and staging)

dump (`mysqldump --single-transaction`, password via `MYSQL_PWD`) → gzip →
AES-256-GCM in 1 MiB chunks (`BackupEncryptor`: per-chunk nonce, index and
final flag authenticated) → SHA-256 → `backups` disk → `backup_runs` row with
a manifest of the row count per table. The work directory is wiped after
every run. With no valid `BACKUP_ENCRYPTION_KEY`, no backup is written.

Then retention runs: keep the newest backup of each of the last
`BACKUP_KEEP_DAILY` (35) days and the first of each of the last
`BACKUP_KEEP_MONTHLY` (12) months, and delete the rest.

## `backup:verify` (weekly, Sunday 04:00)

download → check SHA-256 → decrypt (fails on tampering, a wrong key or
truncation) → gunzip → restore into `BACKUP_VERIFY_CONNECTION` (emptied first)
→ compare every table's row count with the manifest → record `passed` or
`failed`.

It refuses to run against the application connection or the same host and
database.

**A backup is "verified" only after this passes. A file existing is not
enough.** Manifest counts are read just before the dump. On a busy database a
table can differ by a few rows, and the report lists each difference.

`backup:status` shows the newest backup, the newest passing restore test, and
the newest failure.

## Configuration

```
BACKUP_ENCRYPTION_KEY=base64:<32 random bytes>   # php -r "echo 'base64:'.base64_encode(random_bytes(32));"
BACKUP_FILESYSTEM_DRIVER=s3
BACKUP_AWS_BUCKET=… BACKUP_AWS_ACCESS_KEY_ID=… BACKUP_AWS_SECRET_ACCESS_KEY=…   # separate account/bucket
BACKUP_VERIFY_CONNECTION=restore_scratch          # a separate DB defined in config/database.php
BACKUP_MYSQLDUMP_BINARY / BACKUP_MYSQL_BINARY     # if not on PATH
```

Keep the encryption key in the secret manager, **not** in the backup
account. Losing it makes every backup unrecoverable.

## Restore procedure (disaster)

1. Prefer the managed database's point-in-time restore to a new instance.
   Point the app at it, then run `security:verify-audit-chain` and smoke-test
   sign-in.
2. If that is unavailable, take the newest backup that passed
   `backup:verify`. Download it, decrypt it with `BackupEncryptor` (key from
   the secret manager), gunzip it, then load it with
   `mysql --host … target_db < dump.sql`.
3. Restore patient documents from the versioned `private` bucket.
4. Record the incident (see the incident-response process), and the time to
   restore against the RTO.

## Status in this repository

- **Implemented:** the pipeline, encryption, retention, restore test and
  health checks. Tests: `BackupTest` (the dump and restore steps use fakes;
  everything between them is real).
- **Not run:** a real dump or restore. There is no MySQL client, bucket or
  scratch database in the development environment. The first real
  `backup:verify` in staging is the evidence that is still missing.
