<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use LaravelAgentAccess\Actions\Action;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;

#[RequiresPermission('fixture.bound', ability: 'fixture.bind')]
final class BoundValueAction extends Action
{
    public function key(): string
    {
        return 'fixture.bound';
    }

    public function rules(): array
    {
        return ['value' => 'present'];
    }

    public function bindableArguments(): array
    {
        return ['value'];
    }

    public function handle(ExecutionContext $context, AccessRequirement $requirement): array
    {
        return $requirement->arguments;
    }
}
