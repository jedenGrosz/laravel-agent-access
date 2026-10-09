# Action discovery and efficient visibility queries

`ActionCatalog::list(context)` returns public metadata for the registered allowlist: action key, description, permission, resource type/argument, input field names and bindable field names. It also lists role-matching candidate grant rules and hierarchy hints.

The catalog does not resolve resources, inspect Gate, run conditions, consume grants or execute handlers. Candidate rules are not a requestability promise: the concrete action input and resource must still pass `access.check` or `access.simulate`. The result deliberately has no action-level `allowed` field.

Override `Action::description()` to provide a public business description. No PHP handler/condition classes, raw Laravel validation expressions, SQL table names or resource/user records are serialized. Input field names are discovery hints rather than a complete JSON Schema; business MCP tools continue to define their schemas.

- PHP: `app(ActionCatalog::class)->list($context)`.
- HTTP: authenticated `GET /agent-access/v1/actions`.
- MCP: register `ListActions` (`access.actions`).
- Artisan: `agent-access:actions --as=<user-id>`.

## Visibility remains policy-based

Request/grant listing now starts with a SQL candidate filter: owned records, or records in the configured recipient identity realm matching a current rule/fingerprint for one of the actor's grantor roles. Request status and clock-based expiration are filtered in SQL as well.

Every candidate still passes the full current visibility policy before it contributes to a page or total. Conditions such as department membership therefore continue to hide records. Losing a grantor role or changing a rule removes cross-user visibility; ownership still permits the owner to inspect or revoke their own grant. SQL filtering itself never grants authority.

The additive `add_agent_access_lookup_indexes` migration adds rule/realm/status and resource lookup indexes. Existing installations must publish the new migration with `agent-access:install` and explicitly run `migrate`. The migration can roll back without dropping existing grants or requests.

This avoids scanning every unrelated user's records and resolving their resources. Exact authorized totals still require checking all remaining candidates; a large deployment with many eligible rules may need a host-specific visibility query adapter or cursor pagination later. No claim of constant-time listing is made.
