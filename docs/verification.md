# V0.1 verification

Verified locally on 2026-10-09.

## Compatibility

The full SQLite suite passes for every PHP/Laravel pair below. Four independent-process race tests are deliberately skipped on SQLite and are exercised on both real database engines.

| PHP runtime | Laravel | Result |
| --- | --- | --- |
| 8.3.35 | 12.69.3 | Passed |
| 8.3.35 | 13.35.0 | Passed |
| 8.4.26 | 12.69.3 | Passed |
| 8.4.26 | 13.35.0 | Passed |
| 8.5.11 | 12.69.3 | Passed |
| 8.5.8 | 13.35.0 | Passed |

## Database and integration evidence

- PostgreSQL 17 and MySQL 8.4: full authorization suite and separate-process approval/use/cancel/revoke races pass.
- PHPStan/Larastan level 8: no errors. Pint and strict Composer validation pass.
- Live Workbench server: real Sanctum bearer authentication; MCP structured denial/request; HTTP approval; MCP refund and second-use denial pass.
- Fresh Laravel 13 consumer with only runtime dependencies: publication, migrations, request, approval and one-use refund pass without MCP or Spatie installed.
- Repeated installation is checked against Laravel's migration-date publication setting; it preserves configuration without force and does not duplicate the package migration.

Test databases/compatibility installations live under ignored temporary directories or isolated Docker services. This verification does not claim exactly-once effects in external payment providers. Host handlers remain responsible for their transactional business invariants and downstream idempotency.
