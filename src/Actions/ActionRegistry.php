<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Actions;

use Illuminate\Contracts\Container\Container;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Requirements\RequiresPermission;

final class ActionRegistry
{
    /** @var array<string, Action> */
    private array $actions = [];

    /**
     * @param list<class-string<Action>> $classes */
    public function __construct(Container $container, array $classes = [])
    {
        foreach ($classes as $class) {
            $this->register($container->make($class));
        }
    }

    public function register(Action $action): void
    {
        $attributes = (new \ReflectionClass($action))->getAttributes(RequiresPermission::class);
        if ($action->key() === '' || isset($this->actions[$action->key()]) || count($attributes) !== 1) {
            throw new \InvalidArgumentException('An action requires a unique key and exactly one RequiresPermission.');
        }
        $requirement = $attributes[0]->newInstance();
        if ($requirement->permission === '' || (($requirement->resourceArgument === null) !== ($requirement->resourceType === null))) {
            throw new \InvalidArgumentException('Invalid action permission or resource mapping.');
        }
        $this->actions[$action->key()] = $action;
    }

    public function get(string $key): Action
    {
        return $this->actions[$key] ?? throw new AccessException('ACTION_NOT_FOUND', 404);
    }

    /**
     * @return list<Action> */
    public function all(): array
    {
        return array_values($this->actions);
    }
}
