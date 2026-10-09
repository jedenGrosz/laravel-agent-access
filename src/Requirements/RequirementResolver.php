<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Requirements;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\Model;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Exceptions\AccessException;

final readonly class RequirementResolver
{
    public function __construct(private ActionRegistry $actions, private Factory $validation) {}

    /**
     * @param array<string, mixed> $input */
    public function resolve(string $key, array $input): AccessRequirement
    {
        $action = $this->actions->get($key);
        $valid = $this->validation->make($input, $action->rules())->validate();
        $arguments = $action->normalize($valid);
        // Normalizers are trusted code, but their output must still satisfy the schema.
        $this->validation->make($arguments, $action->rules())->validate();

        return $this->assemble($key, $arguments);
    }

    /**
     * @param array<string, mixed> $bound */
    public function restore(string $key, ?string $resourceId, array $bound): AccessRequirement
    {
        $attribute = $this->attribute($key);
        if ($attribute->resourceArgument !== null) {
            $bound[$attribute->resourceArgument] = $resourceId;
        }

        return $this->assemble($key, $bound);
    }

    public function attribute(string $key): RequiresPermission
    {
        return (new \ReflectionClass($this->actions->get($key)))->getAttributes(RequiresPermission::class)[0]->newInstance();
    }

    /**
     * @param array<string, mixed> $arguments */
    private function assemble(string $key, array $arguments): AccessRequirement
    {
        $attribute = $this->attribute($key);
        $resource = $this->actions->get($key)->resource($arguments);
        if ($attribute->resourceArgument !== null && (! $resource instanceof Model || ! $resource->exists)) {
            throw new AccessException('RESOURCE_NOT_FOUND', 404);
        }

        return new AccessRequirement($key, $attribute->permission, $attribute->ability ?? $attribute->permission,
            $attribute->resourceType, $resource instanceof Model ? (string) $resource->getKey() : null, $resource, $arguments);
    }
}
