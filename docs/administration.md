# Optional administration preview (V0.2)

The V0.1 core candidate remains available at its local RC3 tag. The administration additions are opt-in and use the same request, approval, grant and audit services. No frontend build, new RBAC or OAuth server is introduced.

## Approval panel

Enable `agent-access.panel.enabled` in the published configuration. Default URLs are:

| URL | Purpose |
| --- | --- |
| `/agent-access/requests` | Bounded, authorized inbox with status filters |
| `/agent-access/requests/{id}` | Exact scope, bound arguments and approve/reject/cancel forms |
| `/agent-access/grants` | Authorized grants and revoke forms |
| `/agent-access/hierarchy` | Role graph and public rule definitions |

The panel reuses the host's existing login/session. Default middleware is `web`, `auth`, `throttle:agent-access`; retain session and CSRF protection when customizing it. Supply the host login route as usual. The package does not create accounts or provide a login page. API tokens are still used through the existing HTTP/MCP adapters.

Record visibility and every submitted mutation are reevaluated by the shared services. A displayed approval form is not authorization. Role loss, changed conditions, changed rules, expiration and self approval are enforced when the form is submitted. Requester text is escaped and identified as requester-supplied. No action automatically executes after approval.

Hierarchy/rule inspection additionally requires an explicit raw host Gate ability:

```php
Gate::define('agent_access.view_configuration', fn (User $user): bool =>
    ! $user->is_suspended && $user->can('access-configuration.view'));
```

There is no default grantor/administrator bypass for that ability. The viewer shows role names and constraints, without PHP condition class names. It is a read-only viewer, not an IAM editor.

Override package views under `resources/views/vendor/agent-access`, or publish their defaults:

```sh
php artisan vendor:publish --tag=agent-access-views
```

Customize domain-specific argument formatting, host layout, translations and CSP styling there. The package preserves exact raw scalar values in the default review screen and never guesses money units. It uses Laravel translation helpers and has no external fonts/scripts.

## Queued database notifications

Enable `agent-access.notifications.enabled` and configure `notifications.recipients` with a class implementing `NotificationRecipients`. The default resolver returns no candidates; the package does not enumerate the application's users.

```php
interface NotificationRecipients
{
    /** @return iterable<Principal> */
    public function recipients(AccessEvent $event): iterable;
}
```

The [Workbench resolver](../workbench/app/Notifications/WorkflowRecipients.php) is an example for Spatie: request creation selects active finance managers in the recipient's department; later lifecycle events select the owner. If the owner has been deleted before queued delivery, it returns no recipients; configuration and database failures still propagate for queue retry. Configure the class in the host only when its IAM/model assumptions match the application. A host may instead bind its own resolver in the container. Custom resolvers should handle missing owners explicitly, including when yielding candidates lazily.

Recipients must be configured authenticated-user models with Laravel's `Notifiable` trait. The listener validates their identity realm, refreshes them and checks current request/grant visibility at delivery time. Candidate selection does not authorize delivery. A manager who loses their role while a job waits is skipped.

The host supplies Laravel's `notifications` table and an asynchronous queue. For a fresh host using the standard database queue, generate its missing migrations as appropriate:

```sh
php artisan make:notifications-table
php artisan make:queue-table
php artisan migrate
php artisan agent-access:doctor
php artisan queue:work --queue=default --tries=3
```

Do not generate duplicate migrations if the host already has those tables. The package installer never runs these commands for the host. Configure another queue name through `notifications.queue`, and run a worker for that queue. The doctor checks notification storage and rejects sync/null queues as operationally unsuitable; worker health and broker credentials remain host responsibilities.

Match the notification migration's polymorphic key to the user model's primary key: use `uuidMorphs('notifiable')` for UUIDs or `ulidMorphs('notifiable')` for ULIDs instead of numeric morphs. Laravel's notification relationship uses the primary key, even if authentication uses another unique column. The doctor checks the configured model's connection and reports `NOTIFICATION_KEY_TYPE_MISMATCH` for incompatible integer/string column families. This is a basic schema check; it does not verify string lengths, UUID formats, custom notification relations or every database constraint.

The queued listener subscribes only when enabled, filters the configured lifecycle events, and is dispatched after the security transaction commits. Rollback queues nothing. Its payload carries the event type, correlation/audit IDs and allowlisted references; it contains no user models or business payloads. The database notification's JSON data contains only the event, correlation and request/grant references. Reasons, bound arguments, tokens and class names are excluded from that data. Laravel's standard type/morph columns still contain class identifiers; public host endpoints should expose the safe data rather than whole notification model rows.

Database delivery uses a deterministic UUID per audit event and recipient, backed by the notification table's primary key. Retrying the same job does not create another database notification. This guarantee applies to the built-in database channel, not external email/SMS delivery.

Duplicate checks use the same recipient database route as Laravel's database channel, preserving its table, connection and recipient constraints. A matching UUID belonging to someone else is a storage conflict, not successful delivery. Custom database notification routes must return an Eloquent relation or builder and scope it to the intended recipient. The installation doctor checks standard storage; custom routes need their own host integration checks.

Queue-enqueue failure after commit cannot undo an approved request or issued grant. The audit event remains durable evidence; application operations should monitor enqueue/worker failures and use existing request idempotency when retrying. This preview does not introduce a transactional outbox. Hosts can add explicitly configured email/chat integrations through their own queued AccessEvent listeners.

## Large histories: cursor scans

PHP exposes `QueryService::requestsCursor()` and `grantsCursor()`. HTTP, MCP and Artisan use the existing list operations with `pagination: cursor`:

```text
GET /agent-access/v1/requests?pagination=cursor&per_page=20&status=pending
GET /agent-access/v1/grants?pagination=cursor&per_page=20
```

Continue with the returned opaque `cursor` and the same filter. Responses contain `data`, `per_page`, `next_cursor`; they omit exact global totals. Existing page-number responses remain compatible and still scan candidates to compute authorized totals.

Cursor mode orders by ULID and uses SQL `id > last_scanned_id`, with a bounded candidate window. `queries.cursor_scan_limit` defaults to 500 and is capped at 5,000; the effective budget is at least the requested page size (maximum 100). Current SQL role/rule candidate filters and full PHP visibility checks still run. The mode does not bypass conditions or assume that every SQL candidate is authorized.

Cursors are encrypted/authenticated with the host application key, bound to the principal identity, record kind and status filter, and expire after one hour. Tampering or reuse by another user/list/filter gives `INVALID_CURSOR` (422). Role/rule/condition changes are checked anew at every scan. Restart scanning after a visibility/filter change if earlier records must be reconsidered.

A scan window can contain only hidden records, so an empty `data` with a `next_cursor` is valid. The cursor signals scan continuation, not a promise that another authorized record exists. Hidden record IDs and exact counts are not returned. This bounded work limits per-request database reads and PHP policy evaluation; it is not snapshot isolation or an arbitrary-data-volume performance guarantee. Hosts needing exact totals over huge datasets should add a visibility query adapter tailored to their IAM instead of removing final authorization checks.

Tests include a 2,001-request history with one bounded SQL read, cursor audience/filter enforcement, role loss, cross-transport parity, CSRF/XSS, after-commit/rollback behavior and real database notification retry/delivery checks.
