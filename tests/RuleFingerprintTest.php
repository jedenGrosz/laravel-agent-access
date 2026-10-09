<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Str;
use LaravelAgentAccess\Contracts\GrantCondition;
use LaravelAgentAccess\Rules\GrantRule;
use PHPUnit\Framework\TestCase;

final class RuleFingerprintTest extends TestCase
{
    public function test_parent_and_nested_trait_changes_invalidate_a_condition_rule_without_changing_the_child_file(): void
    {
        $namespace = 'FingerprintFixture'.Str::ulid();
        $files = [];
        try {
            foreach (['nested', 'trait', 'parent', 'child'] as $key) {
                $files[$key] = tempnam(sys_get_temp_dir(), 'laa-condition-');
                self::assertNotFalse($files[$key]);
            }
            file_put_contents($files['nested'], "<?php namespace $namespace; trait NestedChecks { public function eligible(): bool { return true; } }");
            file_put_contents($files['trait'], "<?php namespace $namespace; trait Checks { use NestedChecks; }");
            file_put_contents($files['parent'], '<?php namespace '.$namespace.';
                abstract class ParentCondition implements \\LaravelAgentAccess\\Contracts\\GrantCondition {
                    use Checks;
                    public function requestable(\\LaravelAgentAccess\\Identity\\Principal $target,
                        \\LaravelAgentAccess\\Requirements\\AccessRequirement $requirement,
                        \\LaravelAgentAccess\\Rules\\GrantConstraints $constraints): bool { return $this->eligible(); }
                    public function allows(\\LaravelAgentAccess\\Identity\\Principal $actor,
                        \\LaravelAgentAccess\\Identity\\Principal $target,
                        \\LaravelAgentAccess\\Requirements\\AccessRequirement $requirement,
                        \\LaravelAgentAccess\\Rules\\GrantConstraints $constraints): bool { return $this->eligible(); }
                }');
            file_put_contents($files['child'], "<?php namespace $namespace; final class ChildCondition extends ParentCondition {}");
            foreach ($files as $file) {
                require $file;
            }
            /** @var class-string<GrantCondition> $condition */
            $condition = $namespace.'\\ChildCondition';
            $rule = GrantRule::named('inherited')->forPermission('reports.export')->allowGlobalScope()
                ->grantableBy('manager')->toRole('support')->condition($condition);
            $childHash = hash_file('sha256', $files['child']);
            $fingerprint = $rule->fingerprint();
            self::assertSame($fingerprint, $rule->fingerprint());
            foreach (['parent', 'trait', 'nested'] as $key) {
                file_put_contents($files[$key], "\n// deployment changed $key\n", FILE_APPEND);
                $next = $rule->fingerprint();
                self::assertNotSame($fingerprint, $next, 'Changes in '.$key.' must invalidate the old rule.');
                self::assertSame($childHash, hash_file('sha256', $files['child']));
                $fingerprint = $next;
            }
            unlink($files['parent']);
            $this->expectException(\LogicException::class);
            $rule->fingerprint();
        } finally {
            foreach ($files as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_explicit_revision_covers_external_condition_dependencies(): void
    {
        $rule = GrantRule::named('config-dependent')->forPermission('reports.export')->allowGlobalScope()
            ->grantableBy('manager')->toRole('support');
        self::assertNotSame($rule->fingerprint(), $rule->version('2')->fingerprint());
        self::assertSame('1', $rule->revision);
    }
}
