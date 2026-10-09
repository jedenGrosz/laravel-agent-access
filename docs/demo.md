# Orders demo walkthrough

Prepare the isolated Workbench database and start the server:

```sh
composer install
composer demo:prepare
composer demo
```

Use the URL printed by Testbench. Defaults are normally `http://127.0.0.1:8000`.
The demo is local development only; `demo:prepare` resets the demo database.

In your terminal generate one-hour Sanctum tokens for support (1) and manager (2):

```sh
php vendor/bin/testbench demo:token 1
php vendor/bin/testbench demo:token 2
```

Keep tokens in local shell variables. Do not paste them into documentation, audit records or a model prompt.

## HTTP flow

1. Support sends `POST /agent-access/v1/check` using its Bearer token:

```json
{"action":"orders.refund","arguments":{"order_id":431,"amount_minor":10000,"currency":"PLN"}}
```

The response is `ACCESS_REQUIRED`, with `finance-refund-support`, a maximum 900-second TTL, one use and bound amount/currency.

2. Support sends the same payload to `POST /agent-access/v1/requests`, optionally with `Idempotency-Key: refund-431`. Save the returned request ID.
3. Manager sends `GET /agent-access/v1/requests?status=pending` with its own token.
4. Manager sends `POST /agent-access/v1/requests/{id}/approve`.
5. Support explicitly retries `POST /agent-access/v1/actions/orders.refund/execute`:

```json
{"arguments":{"order_id":431,"amount_minor":10000,"currency":"PLN"}}
```

6. The refund succeeds. Repeating it, changing the bound amount or using another order does not reuse the grant.

## MCP flow

Connect an MCP client to `/mcp` using a support token. For a different user/manager, use a separately authenticated connection.

```text
refund_order(order_id=431, amount_minor=10000, currency="PLN")
→ isError=true, ACCESS_REQUIRED

access.request(action="orders.refund", arguments={...})
→ pending request

# Manager connection:
access.requests(status="pending")
access.approve(id="...")

# Support connection:
refund_order(order_id=431, amount_minor=10000, currency="PLN")
→ refunded=true
```

Approval is a server operation under the manager's authenticated identity. A statement inside the support chat cannot substitute that identity.

## Local administration

```sh
php vendor/bin/testbench agent-access:hierarchy
php vendor/bin/testbench agent-access:check --as=1 --input='{"action":"orders.refund","arguments":{"order_id":431,"amount_minor":10000,"currency":"PLN"}}'
php vendor/bin/testbench agent-access:requests --as=2 --input='{"status":"pending"}'
php vendor/bin/testbench agent-access:approve --as=2 --input='{"id":"REQUEST_ULID"}'
php vendor/bin/testbench agent-access:grants --as=1
```

Use shell-appropriate quoting for JSON. PowerShell and POSIX shells can use single-quoted JSON as above.
Inspect `agent_access_audit_events` and `agent_access_grant_uses` in the demo SQLite database for the complete server-side trail.
