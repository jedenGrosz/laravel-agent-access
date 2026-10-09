# Local installation diagnostics

```sh
php artisan agent-access:doctor
php artisan agent-access:doctor --json
```

The command checks reachable package configuration, whether the configured principal class is an authenticatable Eloquent model, the five package tables and essential columns, and the additive lookup/audit indexes. When HTTP uses `auth:sanctum`, it also checks that the authentication package exists.

It returns a nonzero exit status for missing migrations, unavailable storage or invalid configuration. It never publishes files, runs migrations, executes business actions or writes audit. JSON contains only named checks and safe reason codes; no connection credentials, SQL statements or raw exception messages are returned.

This is a local administrative command like `validate`, so it does not require `--as`. It is not exposed through HTTP/MCP. Access operations still require an authenticated principal and their usual policies.

The doctor checks installation readiness, not every host Policy or credential flow. Invalid definitions that already prevent Laravel boot must first be corrected in configuration. Validate real authentication and business behavior using the application integration tests and [Passport smoke fixture](passport-verification.md).
