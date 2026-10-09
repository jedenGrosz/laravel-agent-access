<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Authorization;

use LaravelAgentAccess\Requirements\AccessRequirement;

final readonly class AccessDecision
{
    /**
     * @param list<array<string, mixed>> $grantOptions */
    public function __construct(
        public bool $allowed,
        public string $reasonCode,
        public AccessRequirement $requirement,
        public string $correlationId,
        public ?string $source = null,
        public ?string $grantId = null,
        public array $grantOptions = [],
    ) {}

    public function reason(): string
    {
        return match ($this->reasonCode) {
            'BASE_PERMISSION' => 'The existing IAM allows this action.',
            'VALID_GRANT' => 'A current grant covers this action, resource and arguments.',
            'FORBIDDEN' => 'The existing IAM forbids this action; a grant cannot override it.',
            'MISSING_PERMISSION' => $this->grantOptions === [] ? 'The required permission is missing and no eligible grant rule was found.'
                : 'The required permission is missing; an access request can be submitted under an eligible rule.',
            default => 'Access is not available.',
        };
    }

    /**
     * @return array<string, mixed> */
    public function toArray(): array
    {
        return ['schema_version' => 1, 'code' => $this->allowed ? 'ACCESS_ALLOWED' : ($this->grantOptions !== [] ? 'ACCESS_REQUIRED' : 'ACCESS_DENIED'),
            'allowed' => $this->allowed, 'reason_code' => $this->reasonCode, 'reason' => $this->reason(), 'requirement' => $this->requirement->toArray(),
            'source' => $this->source, 'grant_id' => $this->grantId, 'requestable' => $this->grantOptions !== [],
            'grant_options' => $this->grantOptions, 'correlation_id' => $this->correlationId];
    }
}
