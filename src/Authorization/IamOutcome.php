<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Authorization;

enum IamOutcome: string
{
    case Allow = 'ALLOW';
    case MissingPermission = 'MISSING_PERMISSION';
    case Forbidden = 'FORBIDDEN';
}
