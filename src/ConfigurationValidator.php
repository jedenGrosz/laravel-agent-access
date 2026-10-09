<?php

declare(strict_types=1);

namespace LaravelAgentAccess;

use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Requirements\RequirementResolver;
use LaravelAgentAccess\Rules\RuleRegistry;

final readonly class ConfigurationValidator
{
    public function __construct(private ActionRegistry $actions, private RuleRegistry $rules, private RequirementResolver $requirements) {}

    public function validate(): void
    {
        foreach ($this->actions->all() as $action) {
            $attribute = $this->requirements->attribute($action->key());
            $fields = $action->rules();
            $names = $action->bindableArguments();
            if (count($names) !== count(array_unique($names)) || in_array('', $names, true) || array_diff($names, array_keys($fields)) !== [] ||
                ($attribute->resourceArgument !== null && ! array_key_exists($attribute->resourceArgument, $fields))) {
                throw new \LogicException('Invalid action input mapping: '.$action->key());
            }
        }
        foreach ($this->rules->all() as $rule) {
            $matched = false;
            foreach ($this->actions->all() as $action) {
                if ($this->requirements->attribute($action->key())->permission !== $rule->permission) {
                    continue;
                }
                $matched = true;
                if (array_diff($rule->boundArguments, $action->bindableArguments()) !== []) {
                    throw new \LogicException('Rule bindings are not declared by the action: '.$rule->id);
                }
            }
            if (! $matched) {
                throw new \LogicException('Rule has no registered action: '.$rule->id);
            }
        }
    }
}
