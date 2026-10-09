<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Actions;

use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;

abstract class Action
{
    abstract public function key(): string;

    /**
     * @return array<string, mixed> */
    abstract public function rules(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    public function normalize(array $arguments): array
    {
        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments */
    public function resource(array $arguments): mixed
    {
        return null;
    }

    /**
     * @return list<string> */
    public function bindableArguments(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed> */
    abstract public function handle(ExecutionContext $context, AccessRequirement $requirement): array;
}
