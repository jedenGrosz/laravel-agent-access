<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelAgentAccess\Adapters\Panel\PanelController;

Route::prefix(config('agent-access.panel.prefix', 'agent-access'))->middleware(config('agent-access.panel.middleware', ['web', 'auth', 'throttle:agent-access']))->group(function (): void {
    foreach (['requests' => 'requests', 'requests/{id}' => 'request', 'grants' => 'grants', 'hierarchy' => 'hierarchy'] as $path => $screen) {
        Route::get($path, PanelController::class)->defaults('screen', $screen)->name('agent-access.panel.'.$screen);
    }
    foreach (['approve', 'reject', 'cancel'] as $operation) {
        Route::post('requests/{id}/'.$operation, PanelController::class)->defaults('operation', $operation)->name('agent-access.panel.'.$operation);
    }
    Route::post('grants/{id}/revoke', PanelController::class)->defaults('operation', 'revoke')->name('agent-access.panel.revoke');
});
