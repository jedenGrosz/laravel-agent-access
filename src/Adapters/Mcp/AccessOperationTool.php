<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Adapters\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class AccessOperationTool extends Tool
{
    abstract protected function operation(): string;

    final public function handle(Request $request): ResponseFactory
    {
        // Use exactly the same transport bridge as business tools.
        $bridge = new class extends AgentAccessTool
        {
            public function run(Request $request, string $operation): ResponseFactory
            {
                return $this->invoke($request, $operation, $request->all());
            }
        };

        return $bridge->run($request, $this->operation());
    }

    public function schema(JsonSchema $schema): array
    {
        $op = $this->operation();
        if ($op === 'actions') {
            return [];
        }
        if (in_array($op, ['check', 'request', 'simulate'], true)) {
            $fields = ['action' => $schema->string()->required(), 'arguments' => $schema->object()->required()];
            if ($op === 'request') {
                $fields += ['rule_id' => $schema->string(), 'duration_seconds' => $schema->integer()->min(1),
                    'max_uses' => $schema->integer()->nullable()->min(1), 'reason' => $schema->string(), 'idempotency_key' => $schema->string()];
            }

            if ($op === 'simulate') {
                $fields += ['rule_id' => $schema->string()->required(), 'recipient_id' => $schema->string(),
                    'duration_seconds' => $schema->integer()->min(1), 'max_uses' => $schema->integer()->nullable()->min(1)];
            }

            return $fields;
        }
        if (in_array($op, ['requests', 'grants'], true)) {
            return ['page' => $schema->integer()->min(1), 'per_page' => $schema->integer()->min(1)->max(100), 'status' => $schema->string()->enum(['pending', 'approved', 'rejected', 'cancelled', 'expired'])];
        }

        return ['id' => $schema->string()->required(), 'reason' => $schema->string()];
    }
}
