<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Requirements;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RequiresPermission
{
    public function __construct(
        public string $permission,
        public ?string $ability = null,
        public ?string $resourceArgument = null,
        public ?string $resourceType = null,
    ) {}
}
