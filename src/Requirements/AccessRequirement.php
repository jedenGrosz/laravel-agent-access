<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Requirements;

final readonly class AccessRequirement
{
    /**
     * @param array<string, mixed> $arguments */
    public function __construct(
        public string $action,
        public string $permission,
        public string $ability,
        public ?string $resourceType,
        public ?string $resourceId,
        public mixed $resource,
        public array $arguments,
    ) {}

    /**
     * @return array<string, mixed> */
    public function toArray(): array
    {
        return ['action' => $this->action, 'permission' => $this->permission,
            'resource' => $this->resourceType === null ? null : ['type' => $this->resourceType, 'id' => $this->resourceId]];
    }
}
