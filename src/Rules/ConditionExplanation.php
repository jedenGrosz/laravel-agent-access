<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

final readonly class ConditionExplanation
{
    public function __construct(public string $code, public string $message)
    {
        if (! preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $code) || trim($message) === '' || mb_strlen($message) > 250) {
            throw new \InvalidArgumentException('Condition explanations require a stable public code and a short message.');
        }
    }
}
