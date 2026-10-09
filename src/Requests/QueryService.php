<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Requests;

use Carbon\CarbonImmutable;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Models\AccessRequest;
use LaravelAgentAccess\Models\Grant;

final readonly class QueryService
{
    public function __construct(private AccessRequestService $requests, private GrantService $grants) {}

    /**
     * @return array<string, mixed> */
    public function requests(ExecutionContext $context, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        return $this->paginate(AccessRequest::query()->orderBy('id')->cursor(), $page, $perPage, function (AccessRequest $request) use ($context, $status): bool {
            if ($request->status === 'pending' && $request->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                $request->status = 'expired';
            }

            return ($status === null || $request->status === $status) && ($this->requests->owns($context, $request) || $this->requests->canApprove($context, $request));
        });
    }

    /**
     * @return array<string, mixed> */
    public function grants(ExecutionContext $context, int $page = 1, int $perPage = 20): array
    {
        return $this->paginate(Grant::query()->orderBy('id')->cursor(), $page, $perPage, fn (Grant $grant): bool => $this->grants->canManage($context, $grant));
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
