# Changelog

## 0.2.0 — administration preview (unreleased)

- Enabled notification adapters validate queue names and event-list shapes at boot and through `agent-access:validate`, preserving custom host events and empty lists.
- Opt-in installation diagnostics report missing notification methods on the configured authenticatable model without attempting delivery.
- Installation diagnostics require essential database queue columns, honoring custom queue connections, table names and prefixes.
- Installation diagnostics isolate notification and queue storage failures, preserving independent results and unique check names.
- Notification retry deduplication follows the recipient's database route, including its connection and recipient scope; unrelated UUID collisions remain delivery failures.
- Installation diagnostics detect incompatible notification identity column types for UUID/ULID hosts and inspect the principal model's notification storage connection.
- Workbench notification recipient selection skips deleted owners before queued delivery, while preserving infrastructure/configuration errors for retry.
- Opt-in Blade approval inbox, request decisions, grant revocation and separately authorized hierarchy/rule viewer.
- Queued database notifications with explicit host recipient selection, delivery-time identity/visibility checks and retry-safe UUIDs.
- Encrypted principal/filter-bound cursor scans with bounded candidate reads through PHP, HTTP, MCP and Artisan.
- Host view publication and notification/queue installation diagnostics; optional adapters remain disabled by default.

## 0.1.0

- Public contract review: normalized arguments reach resource-less Gate abilities, inherited/trait condition sources participate in fingerprints, and an independent native-host export acceptance flow verifies both.
- Principal directory lookups honor the configured model's authentication identifier instead of assuming its primary key.
- Package vendor selected as jedengrosz; public business-condition explanations, installation doctor and runtime-only consumer CI.
- MCP failures retain safe correlation IDs for receipt lookup; MySQL receipt queries preserve case-sensitive identities.
- Execution receipt envelopes and authorized read/list APIs across PHP, HTTP, MCP and Artisan; uncertain outcomes remain read-only.
- Public action discovery, SQL candidate filtering with full final visibility policies and additive lookup indexes.
- Artisan list operations work without an explicit JSON input option.
- Minimum framework constraints raised to Laravel 12.69.0 / 13.30.0 after verifying upstream advisories; dedicated minimum-version CI jobs.
- Real Passport OAuth/PKCE smoke fixture and CI job, strict binding/string identity tests and concurrent idempotent request creation.
- Hierarchy traversal, shortest paths, deterministic grantor ranking and readable DAG presentation.
- Shared grant policy traces, public decision reasons and an authorized read-only grant simulator.
- Current principal refresh before IAM evaluation, including the final admission check.
- Consistent configured default usage limits across PHP, HTTP, MCP and Artisan; shared configuration validation.
- Host integration and local release-candidate instructions.
- Explainable three-state Laravel IAM adapter with explicit missing-permission escalation.
- PHP grant rules, advisory role hierarchy and existing-IAM role resolvers.
- Scoped, expiring and usage-limited grants with selected argument bindings.
- Transactional request/approval lifecycle, idempotency and atomic attempt consumption.
- Database audit and after-commit Laravel events.
- Optional HTTP and MCP adapters, local Artisan administration and Orders Workbench.
- SQLite integration tests and independent-process PostgreSQL/MySQL race tests.
