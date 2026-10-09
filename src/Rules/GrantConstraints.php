<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

final readonly class GrantConstraints
{
    public function __construct(public int $durationSeconds, public ?int $maxUses = 1)
    {
        if ($durationSeconds < 1 || ($maxUses !== null && $maxUses < 1)) {
            throw new \InvalidArgumentException('Grant constraints must be positive.');
        }
    }
}
