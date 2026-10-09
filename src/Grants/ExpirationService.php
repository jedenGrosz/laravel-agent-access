<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Grants;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;

final readonly class ExpirationService
{
    public function __construct(private AccessRequestService $requests, private AuditWriter $audit) {}

    public function run(ExecutionContext $context): void
    {
        AccessRequest::query()->where('status', 'pending')->where('expires_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))->chunkById(100,
            function ($records) use ($context): void {
                foreach ($records as $request) {
                    $this->requests->expire($context, $request->id);
                }
            });
        Grant::query()->whereNull('expiry_recorded_at')->where('expires_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))->chunkById(100,
            function ($records) use ($context): void {
                foreach ($records as $candidate) {
                    DB::transaction(function () use ($context, $candidate): void {
                        $grant = Grant::query()->lockForUpdate()->findOrFail($candidate->id);
                        if ($grant->expiry_recorded_at !== null) {
                            return;
                        }
                        $grant->expiry_recorded_at = CarbonImmutable::now('UTC');
                        $grant->save();
                        $this->audit->record('GRANT_EXPIRED', $context, ['grant_id' => $grant->id]);
                    });
                }
            });
    }
}
