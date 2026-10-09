<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Mcp;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use LaravelAgentAccess\Adapters\OperationDispatcher;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ContextFactory;

abstract class AgentAccessTool extends Tool
{
    protected string $action = '';

    final public function handle(Request $request): ResponseFactory
    {
        return $this->invoke($request, 'execute', ['action' => $this->action, 'arguments' => $request->all()]);
    }

    /**
     * @param array<string, mixed> $arguments */
    protected function invoke(Request $request, string $operation, array $arguments): ResponseFactory
    {
        try {
            $context = app(ContextFactory::class)->authenticated($request->user(), 'mcp', $request->meta() ?? []);

            return Response::structured(app(OperationDispatcher::class)->dispatch($context, $operation, $arguments));
        } catch (AccessException $e) {
            return $this->failure($e->toArray());
        } catch (ValidationException) {
            return Response::make(Response::error('INVALID_ARGUMENTS'))->withStructuredContent(['schema_version' => 1, 'code' => 'INVALID_ARGUMENTS']);
        } catch (ModelNotFoundException) {
            return Response::make(Response::error('RESOURCE_NOT_FOUND'))->withStructuredContent(['schema_version' => 1, 'code' => 'RESOURCE_NOT_FOUND']);
        } catch (AuthenticationException) {
            return Response::make(Response::error('UNAUTHENTICATED'))->withStructuredContent(['schema_version' => 1, 'code' => 'UNAUTHENTICATED']);
        } catch (\Throwable) {
            return Response::make(Response::error('INTERNAL_ERROR'))->withStructuredContent(['schema_version' => 1, 'code' => 'INTERNAL_ERROR']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function failure(array $payload): ResponseFactory
    {
        return Response::make(Response::error(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
            ->withStructuredContent($payload);
    }
}
