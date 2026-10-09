# Candidate verification

## Administration preview

Verified on 2026-10-09: 110 tests. SQLite passes 511 assertions and skips the five independent-process database races. PostgreSQL 17 and MySQL 8.4 each pass all 110 tests with 555 assertions. PHPStan 8, Pint, strict Composer validation and Composer audit pass.

The new tests cover authenticated Blade rendering, escaped requester text, CSRF rejection/acceptance, role loss between display/submission, self-approval denial, explicit hierarchy inspection, notification enqueue after commit/absence on rollback, real database notification delivery and retry deduplication, recipient visibility/identity checks, and missing notification storage/async queue diagnostics. Cursor tests include cross-transport behavior, audience/filter/tamper enforcement, bounded hidden scans and a 2,001-request history served through one limited SQL window.

The request detail/decision layout was inspected in a local browser using a rendered static snapshot with inert forms. This checks appearance; real mutation behavior is verified through the application's HTTP kernel tests. Fresh runtime-only consumer and real Passport acceptance flows pass after the administration changes.

## V0.1 RC3 core checkpoint

RC3's 98-test suite passes 435 SQLite assertions (five races skipped) and 479 assertions on each real database. The [RC3 CI](https://github.com/jedenGrosz/laravel-agent-access/actions/runs/37988717678) passes all 12 jobs. The local tag and archive remain that exact core revision. Administration additions are an optional unreleased V0.2 preview; check the [latest CI run](https://github.com/jedenGrosz/laravel-agent-access/actions/workflows/ci.yml) for the current source revision.

## Compatibility

| PHP | Laravel | Coverage |
| --- | --- | --- |
| 8.3, 8.4, 8.5 | Latest compatible 12.x | Full SQLite suite, PHPStan level 8, Pint, strict Composer validation |
| 8.3, 8.4, 8.5 | Latest compatible 13.x | Full SQLite suite, PHPStan level 8, Pint, strict Composer validation |
| 8.3 | Exactly 12.69.0 | Full SQLite suite |
| 8.3 | Exactly 13.30.0 | Full SQLite suite |

The original Laravel 12.41.1 floor could not be installed by Composer because of upstream advisories. The supported framework floor is now 12.69.0 / 13.30.0, matching the patched ranges in [Laravel's advisory](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8). No advisory block was disabled or ignored.

## Authorization and database evidence

- Base IAM allows, explicit missing permission and hard denials remain distinct; adapter failures block the handler.
- Hierarchy traversal/ranking never supplies grant authority. Whole-rule evaluation, current roles, conditions, self-approval prevention and changed fingerprints are tested.
- Exact scalar binding, normalization, UUID/ULID user identities, time boundaries, terminal request states, idempotency and hidden records are covered.
- Public contract tests verify normalized Gate arguments for actions with/without resources, hard parameter denials, custom authentication identifier lookup, inherited/nested-trait fingerprint changes and unavailable condition source failure.
- PostgreSQL/MySQL run separate-process races for idempotent request creation, simultaneous approval, one-use execution, approve/cancel and revoke/admission.
- Failures after admission leave usage consumed; failed admission audit blocks the handler. Checks, catalog discovery, simulation and receipt reads do not consume usage or run a handler.
- Simulation and receipt inspection require current host management authority for another user. MySQL receipt listing preserves case-sensitive identity matching.
- Additive lookup migrations are tested through rollback/reapply with an existing grant. Repeated installation preserves configuration and never duplicates migration publication.

## Real host and transport evidence

- Fresh Laravel 13 with runtime dependencies only: installation, publication, migrations, request, approval, one-use refund and resource-less report export pass without MCP or Spatie. The export verifies normalized arguments and an embargo overriding an existing grant without consuming it or executing the handler. RC2's refund also passed from a copied source archive; each new archive's exact installation evidence is recorded in its release manifest.
- Live Orders Workbench: real Sanctum bearer authentication, MCP structured denial/request, HTTP approval, MCP refund and second-use denial pass.
- Separate Laravel 13 / Passport 13.9.0 / MCP 1.0.1 host: real OAuth discovery, explicit consent, S256 PKCE, rejection of a wrong verifier, bearer-token HTTP/MCP approval/refund and token revocation pass. See [Passport verification](passport-verification.md).
- Shared HTTP/MCP/Artisan tests cover catalog discovery, grant simulation, execution receipts, validation, authenticated identity and management visibility.
- PHPStan/Larastan level 8, Pint, strict Composer validation and Composer audit pass locally. Local installation diagnostics check configuration/schema without writing or disclosing exception details.

Test hosts live in ignored temporary directories or isolated Docker services. These checks do not claim exactly-once effects in external payment providers. Host handlers remain responsible for their transactional business invariants and downstream idempotency.
