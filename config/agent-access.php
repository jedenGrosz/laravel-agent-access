<?php

declare(strict_types=1);

use LaravelAgentAccess\Adapters\Laravel\EmptyRoleResolver;
use LaravelAgentAccess\Adapters\Laravel\LaravelGateIamAdapter;
use LaravelAgentAccess\Notifications\EmptyNotificationRecipients;

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
    'inspection' => ['ability' => 'agent_access.inspect'], // Raw host Gate; never supplemented by business grants.
    'queries' => ['cursor_scan_limit' => 500],
    'panel' => ['enabled' => false, 'prefix' => 'agent-access', 'middleware' => ['web', 'auth', 'throttle:agent-access']],
    'notifications' => ['enabled' => false, 'queue' => 'default',
        'recipients' => EmptyNotificationRecipients::class,
        'events' => ['ACCESS_REQUEST_CREATED', 'ACCESS_REQUEST_APPROVED', 'ACCESS_REQUEST_REJECTED',
            'ACCESS_REQUEST_CANCELLED', 'ACCESS_REQUEST_EXPIRED', 'GRANT_REVOKED', 'GRANT_EXPIRED']],
    'http' => ['enabled' => false, 'prefix' => 'agent-access/v1', 'middleware' => ['auth:sanctum', 'throttle:agent-access']],
];
