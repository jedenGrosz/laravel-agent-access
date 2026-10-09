<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Identity;

use Illuminate\Support\Str;

final readonly class ExecutionContext
{
    public string $correlationId;

    /**
     * @param array<string, string> $trustedMetadata
     * @param array<string, string> $declaredMetadata */
    public function __construct(
        public Principal $principal,
        public string $channel = 'php',
        ?string $correlationId = null,
        public array $trustedMetadata = [],
        public array $declaredMetadata = [],
    ) {
        if (! in_array($channel, ['php', 'http', 'mcp', 'artisan'], true)) {
            throw new \InvalidArgumentException('Unknown execution channel.');
        }
        $this->correlationId = $correlationId ?? (string) Str::ulid();
    }
}
