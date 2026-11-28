# Traffic migrations

Run `php tools/migrate-traffic.php --site-root=/path/to/site --check` to inspect pending work.
The release workflow uses `--apply` before switching application code and fails on errors.
The runner loads the target site's `config.php` directly, without web sessions, mail,
transcription or SEO jobs. No additional credentials belong in migration files.

No index migration is currently selected. Existing plans and small staging timings
do not yet justify building a ranking index on production. Request-local batching,
bounded lists and bounded search do not require a schema change.

New files must be named `YYYYMMDDHHMMSS_description.php`. Each returns an array with
`backward_compatible => true`, `up(mysqli $db)` and `verify(mysqli $db)` callables.
The `up` operation must be idempotent and safe to resume. Validate its build time,
lock behavior and query plan on an isolated representative fixture before release.
Use online DDL and a short metadata-lock timeout where supported. Do not include
application bootstrap, secrets, destructive schema changes or customer data.

The ledger records checksums and completion. Edited applied migrations fail closed.
DDL can commit implicitly, so application rollback never pretends to undo a schema
change. The previous code must continue to work with each migration; destructive
cleanup requires a separate reviewed release after rollback compatibility expires.
