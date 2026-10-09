<?php

declare(strict_types=1);

use LaravelAgentAccess\Adapters\Laravel\EmptyRoleResolver;
use LaravelAgentAccess\Adapters\Laravel\LaravelGateIamAdapter;

return [
    'iam_adapter' => LaravelGateIamAdapter::class,
    'role_resolver' => EmptyRoleResolver::class,
    'roles' => ['spatie_guard' => null], // Defaults to the model's Spatie guard.
    'principal_model' => null, // Defaults to auth.providers.users.model.
    'principal_type' => 'user',
    'actions' => [],
    'rules' => [],
    'hierarchy' => [], // [['administrator', 'finance_manager'], ...]
    'grants' => ['default_ttl' => 900, 'max_ttl' => 86400, 'default_uses' => 1],
    'requests' => ['ttl' => 3600],
    'http' => ['enabled' => false, 'prefix' => 'agent-access/v1', 'middleware' => ['auth:sanctum', 'throttle:agent-access']],
];
