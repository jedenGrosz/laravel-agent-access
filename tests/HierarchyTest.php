<?php

declare(strict_types=1);

namespace Tests;

use LaravelAgentAccess\Rules\RoleHierarchy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HierarchyTest extends TestCase
{
    public function test_diamond_graph_has_unique_relatives_and_deterministic_shortest_path(): void
    {
        $graph = new RoleHierarchy([['root', 'finance'], ['root', 'operations'], ['finance', 'support'], ['operations', 'support']]);
        self::assertSame(['finance', 'operations', 'root'], $graph->ancestors('support'));
        self::assertSame(['finance', 'operations', 'support'], $graph->descendants('root'));
        self::assertSame(['root', 'finance', 'support'], $graph->shortestPath('root', 'support'));
        self::assertSame(2, $graph->distance('root', 'support'));
        self::assertNull($graph->distance('support', 'root'));
        self::assertSame([], $graph->ancestors('outside'));
        self::assertSame(['root', '|-- finance', '|   `-- support', '`-- operations', '    `-- support [shared]'], $graph->render());
    }

    public function test_ranking_retains_roles_outside_graph_and_uses_nearest_target_role(): void
    {
        $graph = new RoleHierarchy([['admin', 'manager'], ['manager', 'support']]);
        $ranked = $graph->rankGrantors(['outside', 'admin', 'manager'], ['support', 'manager']);
        self::assertSame(['manager', 'admin', 'outside'], array_column($ranked, 'role'));
        self::assertSame([0, 1, null], array_column($ranked, 'distance'));
        self::assertNull($ranked[2]['path']);
    }

    public function test_numeric_names_are_strings_and_not_mistaken_for_missing_roles(): void
    {
        $graph = new RoleHierarchy([['0', '1'], ['1', '2']]);
        self::assertSame(['0', '1', '2'], $graph->roles());
        self::assertSame(['0'], $graph->roots());
        self::assertSame(['1', '0'], $graph->ancestors('2'));
        self::assertSame(0, $graph->distance('0', '0'));
    }

    #[DataProvider('invalidGraphs')]
    public function test_invalid_configuration_is_rejected(array $edges): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RoleHierarchy($edges);
    }

    public static function invalidGraphs(): iterable
    {
        yield 'empty' => [[['', 'support']]];
        yield 'whitespace' => [[[' ', 'support']]];
        yield 'duplicate' => [[['manager', 'support'], ['manager', 'support']]];
        yield 'self edge' => [[['support', 'support']]];
        yield 'cycle' => [[['a', 'b'], ['b', 'c'], ['c', 'a']]];
        yield 'disconnected cycle' => [[['root', 'support'], ['a', 'b'], ['b', 'a']]];
        yield 'wrong count' => [[['a']]];
        yield 'numeric value' => [[[0, 'support']]];
        yield 'non array' => [['invalid']];
        yield 'associative edge' => [[['parent' => 'manager', 'child' => 'support']]];
    }
}
