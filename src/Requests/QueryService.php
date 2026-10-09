<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LaravelAgentAccess\Contracts\RoleResolver;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Rules\RuleRegistry;

final readonly class QueryService
{
    public function __construct(private AccessRequestService $requests, private GrantService $grants, private RoleResolver $roles, private RuleRegistry $rules) {}

    /**
     * @return array<string, mixed> */
    public function requests(ExecutionContext $context, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $query = $this->candidates(AccessRequest::query(), $context);
        $now = CarbonImmutable::now('UTC');
        if ($status !== null && ! in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'expired'], true)) {
            throw new AccessException('INVALID_STATUS', 422);
        }
        if ($status === 'expired') {
            $query->where(fn (Builder $q) => $q->where('status', 'expired')->orWhere(fn (Builder $q) => $q->where('status', 'pending')->where('expires_at', '<=', $now->format('Y-m-d H:i:s.u'))));
        } elseif ($status !== null) {
            $query->where('status', $status);
            if ($status === 'pending') {
                $query->where('expires_at', '>', $now->format('Y-m-d H:i:s.u'));
            }
        }

        return $this->paginate($query->orderBy('id')->cursor(), $page, $perPage, function (AccessRequest $request) use ($context, $now): bool {
            if ($request->status === 'pending' && $request->expires_at->lessThanOrEqualTo($now)) {
                $request->status = 'expired';
            }

            return $this->requests->owns($context, $request) || $this->requests->canApprove($context, $request);
        });
    }

    /**
     * @return array<string, mixed> */
    public function grants(ExecutionContext $context, int $page = 1, int $perPage = 20): array
    {
        return $this->paginate($this->candidates(Grant::query(), $context)->orderBy('id')->cursor(), $page, $perPage, fn (Grant $grant): bool => $this->grants->canManage($context, $grant));
    }

    /** SQL narrows the candidate set; full current policies still decide visibility before pagination/totals.
     * @template TModel of Model
     *
     * @param Builder<TModel> $query
     * @return Builder<TModel> */
    private function candidates(Builder $query, ExecutionContext $context): Builder
    {
        $context->principal->refresh();
        $roles = $this->roles->roles($context->principal);
        $eligible = array_values(array_filter($this->rules->all(), fn ($rule): bool => (bool) array_intersect($roles, $rule->grantorRoles)));

        return $query->where(function (Builder $query) use ($context, $eligible): void {
            $query->where(fn (Builder $owner) => $owner->where('principal_type', $context->principal->type)->where('principal_id', $context->principal->id));
            if ($eligible !== []) {
                $query->orWhere(function (Builder $other) use ($eligible): void {
                    $other->where('principal_type', (string) config('agent-access.principal_type', 'user'))
                        ->where(function (Builder $rules) use ($eligible): void {
                            foreach ($eligible as $rule) {
                                $rules->orWhere(fn (Builder $match) => $match->where('rule_id', $rule->id)->where('rule_fingerprint', $rule->fingerprint()));
                            }
                        });
                });
            }
        });
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param iterable<T> $records
     * @param \Closure(T): bool $visible
     * @return array<string, mixed> */
    private function paginate(iterable $records, int $page, int $perPage, \Closure $visible): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new AccessException('INVALID_PAGINATION', 422);
        }
        $data = [];
        $total = 0;
        foreach ($records as $record) {
            if (! $visible($record)) {
                continue;
            }
            if ($total >= ($page - 1) * $perPage && count($data) < $perPage) {
                $data[] = $record->toArray();
            }
            $total++;
        }

        return ['data' => $data, 'total' => $total, 'current_page' => $page, 'per_page' => $perPage];
    }
}
