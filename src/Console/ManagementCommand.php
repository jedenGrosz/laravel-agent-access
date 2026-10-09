<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use LaravelAgentAccess\Actions\ActionRegistry;
use LaravelAgentAccess\Adapters\OperationDispatcher;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\ExpirationService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Requirements\RequirementResolver;
use LaravelAgentAccess\Rules\RoleHierarchy;
use LaravelAgentAccess\Rules\RuleRegistry;

final class ManagementCommand extends Command
{
    public function __construct(private readonly string $operation)
    {
        $this->signature = 'agent-access:'.$operation.' {--as= : Local trusted user ID} {--input={} : JSON operation arguments}';
        $this->description = 'Laravel Agent Access: '.$operation;
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ($this->operation === 'hierarchy') {
                $this->line(json_encode(app(RoleHierarchy::class)->edges, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            if ($this->operation === 'validate') {
                $rules = app(RuleRegistry::class)->all();
                $actions = app(ActionRegistry::class)->all();
                app(RoleHierarchy::class);
                foreach ($rules as $rule) {
                    $matched = false;
                    foreach ($actions as $action) {
                        $attribute = app(RequirementResolver::class)->attribute($action->key());
                        if ($attribute->permission !== $rule->permission) {
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
            $json = $this->option('input');
            if (! is_string($json)) {
                throw new \InvalidArgumentException('Input must be JSON.');
            }
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
