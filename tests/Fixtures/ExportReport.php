<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use LaravelAgentAccess\Actions\Action;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Requirements\AccessRequirement;
use LaravelAgentAccess\Requirements\RequiresPermission;

#[RequiresPermission('reports.export', ability: 'exportReport')]
final class ExportReport extends Action
{
    public int $attempts = 0;

    public function key(): string
    {
        return 'reports.export';
    }

    public function rules(): array
    {
        return ['classification' => 'required|string', 'limit' => 'required|integer|min:1|max:100'];
    }

    public function normalize(array $arguments): array
    {
        return ['classification' => strtolower(trim($arguments['classification'])), 'limit' => (int) $arguments['limit']];
    }

    public function bindableArguments(): array
    {
        return ['classification', 'limit'];
    }

    public function handle(ExecutionContext $context, AccessRequirement $requirement): array
    {
        $this->attempts++;

        return $requirement->arguments;
    }
}
