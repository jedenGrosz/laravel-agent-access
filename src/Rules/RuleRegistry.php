<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

final class RuleRegistry
{
    /** @var array<string, GrantRule> */
    private array $rules = [];

    /**
     * @param list<GrantRule> $rules */
    public function __construct(array $rules = [])
    {
        foreach ($rules as $rule) {
            $this->register($rule);
        }
    }

    public function register(GrantRule $rule): void
    {
        $rule->validate();
        if (isset($this->rules[$rule->id])) {
            throw new \InvalidArgumentException('Repeated rule ID.');
        }
        $this->rules[$rule->id] = clone $rule;
        ksort($this->rules);
    }

    public function get(string $id): ?GrantRule
    {
        return isset($this->rules[$id]) ? clone $this->rules[$id] : null;
    }

    /**
     * @return list<GrantRule> */
    public function all(): array
    {
        return array_map(fn ($rule) => clone $rule, array_values($this->rules));
    }
}
