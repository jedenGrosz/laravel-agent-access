# Durable execution receipts

`ActionExecutor::execute()` keeps its existing behavior: it returns the business handler's result array. `executeWithReceipt()` uses the identical admission and execution path once, returning an `ExecutionResult` with the result, server-generated execution ID and correlation ID. Business result keys stay inside `result` in the envelope, so they cannot overwrite receipt metadata.

```php
$result = app(ActionExecutor::class)->executeWithReceipt($context, 'orders.refund', $arguments);
$executionId = $result->executionId;
$businessResult = $result->result;
$receipt = app(ExecutionReceiptService::class)->show($context, $executionId);
```

The matching HTTP operation is `POST /agent-access/v1/actions/{action}/run`. The original `/execute` retains its raw result shape. MCP can register `RunAction` (`access.run`) to execute an action from the allowlist with a receipt envelope. Both routes/tools invoke the same authorization services and consume a one-use grant before the handler.

## Read evidence without executing

- HTTP: `GET /agent-access/v1/executions/{id}` and `GET /agent-access/v1/executions`.
- MCP: `ShowExecution` (`access.execution`) and `ListExecutions` (`access.executions`).
- Artisan: `agent-access:execution`, `executions` and `run`, with explicit `--as`.
- PHP: `ExecutionReceiptService::show()` and `list()`.

Listing includes only the authenticated actor's own attempts. It supports `page`, `per_page` (maximum 100) and an optional `correlation_id`. A correlation ID cannot expose another user's executions. This lets a caller find its attempts after losing an HTTP result; an adapter's handler error includes the server correlation ID when a response can be returned.

Individual receipt reads permit the owner or an actor explicitly authorized by the host's raw inspection policy. Unknown IDs, other identity realms and hidden executions return 404. Read [IAM inspection](iam-inspection.md) for configuring that separate authority.

| Status | Durable evidence |
| --- | --- |
| `DENIED` | A denial was recorded; no handler was admitted. |
| `SUCCEEDED` | Admission and a terminal success event are recorded. |
| `FAILED` | Admission and a terminal handler failure event are recorded; the grant remains consumed. |
| `OUTCOME_UNKNOWN` | Admission exists without a durable terminal event; inspect business state. |

`OUTCOME_UNKNOWN` may represent an in-progress handler, process crash or result-audit failure. It is not proof that the handler did nothing. `requires_review` is true for this state. Receipt reads never retry, resume, consume or restore access. A success event records that the handler returned; it does not guarantee exactly-once effects in an external system.

Receipts expose event types/times, safe action/permission/resource references and the grant ID. They omit payloads, argument values, handler results, credentials, exception details and other user records. Older audit rows may have null action/resource metadata; history is not rewritten.

Admission denials include an execution ID in their structured error, and result-audit failures retain `ACTION_RESULT_UNRECORDED` with the execution ID. The audit-owner index is published in a separate additive migration; existing hosts explicitly publish and migrate it.
