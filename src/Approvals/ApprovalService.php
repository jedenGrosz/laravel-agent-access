<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Approvals;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LaravelAgentAccess\Authorization\IamOutcome;
use LaravelAgentAccess\Contracts\AuditWriter;
use LaravelAgentAccess\Contracts\IamAdapter;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\Approval;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Requests\AccessRequestService;
use LaravelAgentAccess\Requirements\RequirementResolver;

final readonly class ApprovalService
{
    public function __construct(private AccessRequestService $requests, private AuditWriter $audit, private IamAdapter $iam,
        private RequirementResolver $requirements, private PrincipalDirectory $principals) {}

    public function approve(ExecutionContext $context, string $id): Grant
    {
        return $this->decide($context, $id, 'approved');
    }

    public function reject(ExecutionContext $context, string $id, ?string $reason = null): AccessRequest
    {
        $this->decide($context, $id, 'rejected', $reason);

        return $this->requests->show($context, $id);
    }

    /**
     * @return ($decision is 'approved' ? Grant : AccessRequest) */
    private function decide(ExecutionContext $context, string $id, string $decision, ?string $reason = null): Grant|AccessRequest
    {
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new AccessException('INVALID_REASON', 422);
        }
        // Persist expiration separately so it survives the later conflict exception.
        $this->requests->show($context, $id);

        return DB::transaction(function () use ($context, $id, $decision, $reason): Grant|AccessRequest {
            $request = AccessRequest::query()->lockForUpdate()->find($id);
            if ($request === null || ! $this->requests->canApprove($context, $request)) {
                throw new AccessException('REQUEST_NOT_FOUND', 404);
            }
            if ($request->status !== 'pending') {
                $prior = Approval::query()->where('request_id', $id)->first();
                if ($request->status === $decision && $prior !== null && $prior->actor_type === $context->principal->type && $prior->actor_id === $context->principal->id) {
                    return $decision === 'approved' ? Grant::query()->where('request_id', $id)->firstOrFail() : $request;
                }
                throw new AccessException('REQUEST_STATE_CONFLICT', 409);
            }
            if ($request->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw new AccessException('REQUEST_EXPIRED', 409);
            }
            $target = $this->principals->find($request->principal_id, $request->principal_type);
            $requirement = $this->requirements->restore($request->action, $request->resource_id, $request->bound_arguments);
            if ($decision === 'approved' && $this->iam->evaluate($target, $requirement)->outcome !== IamOutcome::MissingPermission) {
                throw new AccessException('REQUEST_NO_LONGER_GRANTABLE', 409);
            }
            $grant = null;
            if ($decision === 'approved') {
                $grant = Grant::query()->create(['request_id' => $id, 'principal_type' => $request->principal_type, 'principal_id' => $request->principal_id,
                    'action' => $request->action, 'permission' => $request->permission, 'resource_type' => $request->resource_type, 'resource_id' => $request->resource_id,
                    'bound_arguments' => $request->bound_arguments, 'rule_id' => $request->rule_id, 'rule_fingerprint' => $request->rule_fingerprint,
                    'duration_seconds' => $request->duration_seconds, 'max_uses' => $request->max_uses, 'uses' => 0,
                    'valid_from' => CarbonImmutable::now('UTC'), 'expires_at' => CarbonImmutable::now('UTC')->addSeconds($request->duration_seconds),
                    'grantor_type' => $context->principal->type, 'grantor_id' => $context->principal->id]);
            }
            Approval::query()->create(['request_id' => $id, 'actor_type' => $context->principal->type, 'actor_id' => $context->principal->id, 'decision' => $decision, 'reason' => $reason]);
            $request->fill(['status' => $decision, 'decided_at' => CarbonImmutable::now('UTC')])->save();
            $this->audit->record($decision === 'approved' ? 'ACCESS_REQUEST_APPROVED' : 'ACCESS_REQUEST_REJECTED', $context, ['request_id' => $id, 'grant_id' => $grant?->id]);
            if ($grant !== null) {
                $this->audit->record('GRANT_CREATED', $context, ['request_id' => $id, 'grant_id' => $grant->id, 'rule_id' => $request->rule_id]);
            }

            return $grant ?? $request;
        });
    }
}
