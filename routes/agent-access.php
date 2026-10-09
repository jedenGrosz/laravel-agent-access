<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelAgentAccess\Adapters\Http\AccessController;

Route::prefix(config('agent-access.http.prefix'))->middleware(config('agent-access.http.middleware'))->group(function (): void {
    foreach ([['get', 'actions', 'actions'], ['get', 'executions', 'executions'], ['get', 'executions/{id}', 'execution'], ['post', 'check', 'check'], ['post', 'simulate', 'simulate'],
        ['post', 'actions/{action}/execute', 'execute'], ['post', 'actions/{action}/run', 'run'], ['post', 'requests', 'request'],
        ['get', 'requests', 'requests'], ['get', 'requests/{id}', 'show'], ['post', 'requests/{id}/approve', 'approve'],
        ['post', 'requests/{id}/reject', 'reject'], ['post', 'requests/{id}/cancel', 'cancel'], ['get', 'grants', 'grants'],
        ['post', 'grants/{id}/revoke', 'revoke']] as [$verb, $path, $operation]) {
        Route::$verb($path, AccessController::class)->defaults('operation', $operation)->name('agent-access.'.$operation);
    }
});
