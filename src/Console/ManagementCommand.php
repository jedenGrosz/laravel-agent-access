<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use LaravelAgentAccess\Adapters\OperationDispatcher;
use LaravelAgentAccess\ConfigurationValidator;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\ExpirationService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\InstallationDoctor;
use LaravelAgentAccess\Rules\RoleHierarchy;

final class ManagementCommand extends Command
{
    public function __construct(private readonly string $operation)
    {
        $this->signature = 'agent-access:'.$operation.' {--as= : Local trusted user ID} {--input= : JSON operation arguments} {--json : Machine-readable hierarchy}';
        $this->description = 'Laravel Agent Access: '.$operation;
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ($this->operation === 'doctor') {
                $report = app(InstallationDoctor::class)->check();
                if ($this->option('json')) {
                    $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                } else {
                    foreach ($report['checks'] as $check) {
                        $this->line(($check['passed'] ? 'PASS ' : 'FAIL ').$check['check'].' '.$check['reason_code']);
                    }
                }

                return $report['healthy'] ? self::SUCCESS : self::FAILURE;
            }
            if ($this->operation === 'hierarchy') {
                $hierarchy = app(RoleHierarchy::class);
                if ($this->option('json')) {
                    $this->line(json_encode($hierarchy->edges, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                } else {
                    foreach ($hierarchy->render() ?: ['No hierarchy relations configured.'] as $line) {
                        $this->line($line);
                    }
                }

                return self::SUCCESS;
            }
            if ($this->operation === 'validate') {
                app(RoleHierarchy::class);
                app(ConfigurationValidator::class)->validate();
                $this->info('Agent Access configuration is valid.');

                return self::SUCCESS;
            }
            $as = $this->option('as');
            if (! is_string($as) || $as === '') {
                throw new \InvalidArgumentException('--as is required for access operations.');
            }
            $context = new ExecutionContext(app(PrincipalDirectory::class)->find($as), 'artisan');
            if ($this->operation === 'expire') {
                app(ExpirationService::class)->run($context);
                $this->info('Expiration recorded.');

                return self::SUCCESS;
            }
            $json = $this->option('input') ?? '{}';
            $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($input) || (array_is_list($input) && $input !== [])) {
                throw new \InvalidArgumentException('Input must be a JSON object.');
            }
            $result = app(OperationDispatcher::class)->dispatch($context, $this->operation, $input);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (AccessException $e) {
            $this->error(json_encode($e->toArray(), JSON_THROW_ON_ERROR));

            return self::FAILURE;
        } catch (ValidationException) {
            $this->error('INVALID_ARGUMENTS');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e instanceof \InvalidArgumentException || $e instanceof \LogicException ? $e->getMessage() : 'Operation failed.');

            return self::FAILURE;
        }
    }
}
