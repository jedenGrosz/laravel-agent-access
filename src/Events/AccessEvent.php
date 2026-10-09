<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Events;

final readonly class AccessEvent
{
    /**
     * @param array<string, scalar|null> $references */
    public function __construct(public string $type, public string $correlationId, public array $references, public ?string $eventId = null) {}
}
