<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Authorization;

final readonly class IamDecision
{
    public const MISSING_PERMISSION_CODE = 'LAA_MISSING_PERMISSION';

    public function __construct(public IamOutcome $outcome) {}
}
