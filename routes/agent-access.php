<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelAgentAccess\Adapters\Http\AccessController;

Route::prefix(config('agent-access.http.prefix'))->middleware(config('agent-access.http.middleware'))->group(function (): void {
    foreach ([['post', 'check', 'check'], ['post', 'actions/{action}/execute', 'execute'], ['post', 'requests', 'request'],
        ['get', 'requests', 'requests'], ['get', 'requests/{id}', 'show'], ['post', 'requests/{id}/approve', 'approve'],
        ['post', 'requests/{id}/reject', 'reject'], ['post', 'requests/{id}/cancel', 'cancel'], ['get', 'grants', 'grants'],
        ['post', 'grants/{id}/revoke', 'revoke']] as [$verb, $path, $operation]) {
        Route::$verb($path, AccessController::class)->defaults('operation', $operation)->name('agent-access.'.$operation);
    }
});
