# V0.1 verification

Latest IAM/Passport follow-up: 70 tests; SQLite passes 269 assertions and skips five races. PostgreSQL 17 and MySQL 8.4 each pass all 70 tests with 313 assertions. Added graph navigation/ranking, policy traces and simulator authorization tests, UUID/ULID and strict binding tests, and concurrent idempotent request creation.

The separate Laravel 13 / Passport 13.9.0 / MCP 1.0.1 fixture passes real HTTP OAuth discovery, explicit consent, S256 PKCE including rejection of a wrong verifier, user bearer tokens, cross-transport approval/refund and token revocation. See [Passport verification](passport-verification.md). Historical RC1 results below describe the earlier 44-test checkpoint.

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

## Release-candidate follow-up

- Full suite: 44 tests; SQLite reports 184 assertions and skips the four database races.
- PostgreSQL 17 and MySQL 8.4 each pass all 44 tests with 220 assertions.
- Eight additional scenarios verify consistent configured defaults across PHP/HTTP/MCP/Artisan, explicit unlimited-use authorization, idempotency of omitted versus explicit defaults, no silent usage-limit reduction, current principal refresh, suspension between preview and admission, approval-time hard denial, and shared command validation.
- Admission refusals after transaction rollback are durably audited without running the handler or consuming a grant.
- Fresh runtime-only consumer and authenticated HTTP/MCP refund smoke checks pass again after these changes.
- Passport integration instructions are checked against the stable Laravel MCP 1.0.1 registrar and official documentation. The OAuth consent/discovery flow itself is not claimed as locally tested.

Test databases/compatibility installations live under ignored temporary directories or isolated Docker services. This verification does not claim exactly-once effects in external payment providers. Host handlers remain responsible for their transactional business invariants and downstream idempotency.
