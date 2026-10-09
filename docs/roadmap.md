# Laravel Agent Access roadmap

## Implemented for the V0.1 candidate

- Three-state existing-IAM adapter; authenticated user identity and shared execution context.
- Complete PHP grant rules, recipient/grantor conditions, exact argument binding and rule fingerprints.
- Advisory DAG hierarchy with traversal, shortest paths, ranked grantor roles and CLI presentation.
- Shared policy decision traces, optional public business-condition reasons and authorized simulation.
- Durable request/approval/grant lifecycle, idempotency, atomic consumption and allowlisted audit.
- PHP, HTTP, MCP and Artisan adapters; action catalog and durable execution receipts.
- SQL candidate filtering before full visibility evaluation, additive lookup indexes and local installation doctor.
- Orders Workbench with Sanctum/Spatie and an independent real Passport OAuth/PKCE fixture.
- SQLite tests, separate-process PostgreSQL/MySQL races, PHP/Laravel matrix and exact framework-floor CI.

## Close the first public release

1. Verify the final commit's GitHub CI and assemble an updated local source archive/manifest.
2. Review the public contracts and the candidate in a real host application.
3. The final Composer name is selected: jedengrosz/laravel-agent-access. Verify consumer installation under that name.
4. Tag and publish the selected version/release, then submit it to Packagist as a separate distribution step.

The supported framework floor was raised to Laravel 12.69.0 / 13.30.0 after confirming upstream advisories. The core still has no mandatory MCP, Sanctum, Spatie or Passport dependency.

## Suggested V0.2: administration and operations

- Optional approval inbox and hierarchy/policy viewer in a host UI.
- Queued notifications built on existing after-commit events; explicit application integration.
- Cursor pagination or host visibility query adapters for large candidate sets.
- Audit search/export, retention and operational monitoring with separate management authorization.
- Broader real-host acceptance tests and a second business domain beyond Orders.

## Later IAM extensions requiring an explicit design decision

- Trusted tenant/organization scope in identity, resource references and every request/grant/use. Multi-tenant deployments must not assume that equal user/resource IDs in different databases are isolated by V0.1; the current model assumes one identity realm with stable IDs.
- Persistent policy/hierarchy editing with versioned revisions and explicit administrative authority. Existing application roles remain the host IAM's responsibility.
- Multi-person approval with independent voters and a transactionally enforced quorum.
- Composite action requirements, several resources and richer typed constraints.
- Optional resumable workflows only after designing explicit consent, input/state revalidation and handler idempotency.

Autonomous-agent accounts, an own OAuth/RBAC server, a policy language and automatic retries are outside the current candidate. These extensions should preserve hard IAM denials, one complete rule per grant and the distinction between use authority and grant authority.
