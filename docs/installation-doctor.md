# Local installation diagnostics

```sh
php artisan agent-access:doctor
php artisan agent-access:doctor --json
```

The command checks reachable package configuration, whether the configured principal class is an authenticatable Eloquent model, the five package tables and essential columns, and the additive lookup/audit indexes. When HTTP uses `auth:sanctum`, it also checks that the authentication package exists.

When database notifications are enabled, it checks storage on the configured principal model's connection, whether the notification identity column can store the model's integer/string primary key, and asynchronous queue configuration/storage. `NOTIFICATION_KEY_TYPE_MISMATCH` usually means a UUID/ULID host still has numeric notification morphs. This check uses the model's primary key, not its authentication identifier; it does not validate column lengths, UUID formats or custom notification relationships.

The opt-in `notification_model` check requires the authenticatable model's `notify` and `routeNotificationFor` methods, normally provided by Laravel's `Notifiable` trait. Missing methods report `NOTIFIABLE_MODEL_REQUIRED` before the worker attempts delivery. The doctor checks method presence without calling them; it does not send notifications or verify custom method implementations. Notification support is not required when this adapter is disabled.

Notification storage and queue storage are diagnosed independently. A queue database failure reports `notification_queue_storage: DATABASE_UNAVAILABLE` and does not overwrite a successful notification storage check. Unavailable notification storage does not skip the queue checks. Each check name appears once, making the JSON safe to index by name; unavailable prerequisites omit the dependent identity check.

For a database queue, storage readiness requires the configured table and Laravel's essential job columns: `id`, `queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`. An existing incomplete table reports `HOST_MIGRATION_REQUIRED`; the doctor does not repair it. The check honors the queue's database connection, table name and connection prefix. It does not validate every column type/index or prove that a worker is running.

It returns a nonzero exit status for missing migrations, unavailable storage or invalid configuration. It never publishes files, runs migrations, executes business actions or writes audit. JSON contains only named checks and safe reason codes; no connection credentials, SQL statements or raw exception messages are returned.

This is a local administrative command like `validate`, so it does not require `--as`. It is not exposed through HTTP/MCP. Access operations still require an authenticated principal and their usual policies.

The doctor checks installation readiness, not every host Policy or credential flow. Invalid definitions that already prevent Laravel boot must first be corrected in configuration. Validate real authentication and business behavior using the application integration tests and [Passport smoke fixture](passport-verification.md).
