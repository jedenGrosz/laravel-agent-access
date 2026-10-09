<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

use LaravelAgentAccess\Contracts\GrantCondition;

final class GrantRule
{
    public string $permission = '';

    /** @var list<string> */
    public array $grantorRoles = [];

    /** @var list<string> */
    public array $targetRoles = [];

    public bool $resourceRequired = true;

    public int $maximumDuration = 900;

    public ?int $maximumUses = 1;

    /** @var list<string> */
    public array $boundArguments = [];

    /** @var list<class-string<GrantCondition>> */
    public array $conditions = [];

    public string $revision = '1';

    private function __construct(public string $id) {}

    public static function named(string $id): self
    {
        return new self($id);
    }

    public function forPermission(string $permission): self
    {
        $r = clone $this;
        $r->permission = $permission;

        return $r;
    }

    public function grantableBy(string ...$roles): self
    {
        $r = clone $this;
        $r->grantorRoles = array_values($roles);

        return $r;
    }

    public function toRole(string ...$roles): self
    {
        $r = clone $this;
        $r->targetRoles = array_values($roles);

        return $r;
    }

    public function resourceScoped(): self
    {
        $r = clone $this;
        $r->resourceRequired = true;

        return $r;
    }

    public function allowGlobalScope(): self
    {
        $r = clone $this;
        $r->resourceRequired = false;

        return $r;
    }

    public function maxDuration(int $seconds): self
    {
        $r = clone $this;
        $r->maximumDuration = $seconds;

        return $r;
    }

    public function maxUses(int $uses): self
    {
        $r = clone $this;
        $r->maximumUses = $uses;

        return $r;
    }

    public function unlimitedUses(): self
    {
        $r = clone $this;
        $r->maximumUses = null;

        return $r;
    }

    /**
     * @param list<string> $names */
    public function bindArguments(array $names): self
    {
        $r = clone $this;
        $r->boundArguments = $names;

        return $r;
    }

    /**
     * @param class-string<GrantCondition> $condition */
    public function condition(string $condition): self
    {
        $r = clone $this;
        $r->conditions[] = $condition;

        return $r;
    }

    public function version(string $version): self
    {
        $r = clone $this;
        $r->revision = $version;

        return $r;
    }

    public function validate(): void
    {
        if ($this->id === '' || $this->permission === '' || str_starts_with($this->permission, 'agent_access.') ||
            $this->grantorRoles === [] || $this->targetRoles === [] || $this->maximumDuration < 1 ||
            ($this->maximumUses !== null && $this->maximumUses < 1)) {
            throw new \InvalidArgumentException('Incomplete or unsafe grant rule: '.$this->id);
        }
        foreach ([$this->grantorRoles, $this->targetRoles, $this->boundArguments] as $values) {
            if (count($values) !== count(array_unique($values)) || in_array('', $values, true)) {
                throw new \InvalidArgumentException('Empty or repeated rule identifiers.');
            }
        }
        foreach ($this->conditions as $condition) {
            if (! is_subclass_of($condition, GrantCondition::class)) {
                throw new \InvalidArgumentException('Conditions must implement GrantCondition.');
            }
        }
    }

    public function fingerprint(): string
    {
        $sources = [];
        foreach ($this->conditions as $condition) {
            $file = (new \ReflectionClass($condition))->getFileName();
            $sources[$condition] = $file === false ? null : hash_file('sha256', $file);
        }

        return hash('sha256', json_encode([get_object_vars($this), $sources], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $state */
    public static function __set_state(array $state): self
    {
        $rule = new self((string) $state['id']);
        foreach ($state as $key => $value) {
            $rule->$key = $value;
        }

        return $rule;
    }
}
