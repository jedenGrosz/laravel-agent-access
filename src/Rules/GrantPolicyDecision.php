<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

final readonly class GrantPolicyDecision
{
    /** @param list<array{check: string, allowed: bool, reason_code: string, condition_reason_code?: string, reason?: string}> $checks */
    public function __construct(public bool $allowed, public string $reasonCode, public array $checks = [], private ?string $publicReason = null) {}

    public function reason(): string
    {
        if ($this->publicReason !== null) {
            return $this->publicReason;
        }

        return match ($this->reasonCode) {
            'GRANT_ALLOWED' => 'The actor satisfies this complete grant rule.',
            'REQUEST_ELIGIBLE' => 'The recipient and proposed constraints satisfy this grant rule.',
            'PERMISSION_MISMATCH' => 'This rule applies to a different permission.',
            'RECIPIENT_ROLE_REQUIRED' => 'The recipient does not have a role allowed by this rule.',
            'DURATION_EXCEEDED' => 'The proposed duration exceeds the allowed limit.',
            'RESOURCE_REQUIRED' => 'This rule requires a resolved resource.',
            'USES_EXCEEDED' => 'The proposed usage limit exceeds the rule or requests unauthorized unlimited uses.',
            'ARGUMENT_BINDING_INVALID' => 'A required bound argument is missing or is not an exact scalar value.',
            'CONDITION_NOT_MET' => 'A business condition of this rule is not satisfied.',
            'SELF_APPROVAL_FORBIDDEN' => 'A user cannot approve their own access request.',
            'GRANTOR_ROLE_REQUIRED' => 'The actor does not have a grantor role allowed by this rule.',
            default => 'The proposed grant is not allowed.',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason_code' => $this->reasonCode, 'reason' => $this->reason(), 'checks' => $this->checks];
    }
}
