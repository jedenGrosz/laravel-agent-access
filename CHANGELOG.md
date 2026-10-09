# Changelog

## 0.1.0

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
