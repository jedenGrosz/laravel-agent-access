<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Http;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LaravelAgentAccess\Adapters\OperationDispatcher;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ContextFactory;

final readonly class AccessController
{
    public function __construct(private OperationDispatcher $dispatcher, private ContextFactory $contexts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $operation = $request->route('operation');
        if (! is_string($operation)) {
            throw new \LogicException('The route must declare an access operation.');
        }
        $input = $request->all();
        $id = $request->route('id');
        $action = $request->route('action');
        if ($id !== null) {
            $input['id'] = $id;
        }
        if ($action !== null) {
            $input['action'] = $action;
        }
        if ($request->hasHeader('Idempotency-Key')) {
            $input['idempotency_key'] = $request->header('Idempotency-Key');
        }
        $context = $this->contexts->authenticated($request->user(), 'http', ['client_id' => $request->userAgent()]);
        try {
            return new JsonResponse($this->dispatcher->dispatch($context, $operation, $input));
        } catch (AccessException $e) {
            return new JsonResponse($e->toArray(), $e->status);
        } catch (ValidationException $e) {
            return new JsonResponse(['schema_version' => 1, 'code' => 'INVALID_ARGUMENTS', 'fields' => array_keys($e->errors())], 422);
        } catch (ModelNotFoundException) {
            return new JsonResponse(['schema_version' => 1, 'code' => 'RESOURCE_NOT_FOUND'], 404);
        } catch (\Throwable) {
            return new JsonResponse(['schema_version' => 1, 'code' => 'INTERNAL_ERROR', 'correlation_id' => $context->correlationId], 500);
        }
    }
}
