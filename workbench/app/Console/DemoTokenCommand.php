<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Workbench\App\Models\User;

final class DemoTokenCommand extends Command
{
    protected $signature = 'demo:token {user : Local demo user ID}';

    protected $description = 'Create a Sanctum token for a LOCAL demo user. Treat the displayed token as a credential.';

    public function handle(): int
    {
        $id = $this->argument('user');
        if (! is_string($id)) {
            return self::FAILURE;
        }
        $user = User::query()->find($id);
        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }
        $this->line($user->createToken('local-demo', ['*'], now()->addHour())->plainTextToken);

        return self::SUCCESS;
    }
}
