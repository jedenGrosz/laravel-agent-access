<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

final readonly class RoleHierarchy
{
    /**
     * @param list<list<string>> $edges */
    public function __construct(public array $edges = [])
    {
        $seen = [];
        $graph = [];
        foreach ($edges as $edge) {
            if (count($edge) !== 2 || $edge[0] === '' || $edge[1] === '' || $edge[0] === $edge[1]) {
                throw new \InvalidArgumentException('Invalid hierarchy edge.');
            }
            $key = json_encode($edge, JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Duplicate hierarchy edge.');
            }
            $seen[$key] = true;
            $graph[$edge[0]][] = $edge[1];
        }
        $visited = [];
        $active = [];
        $walk = function (string $node) use (&$walk, &$visited, &$active, $graph): void {
            if (isset($active[$node])) {
                throw new \InvalidArgumentException('Hierarchy contains a cycle.');
            }
            if (isset($visited[$node])) {
                return;
            }
            $active[$node] = true;
            foreach ($graph[$node] ?? [] as $child) {
                $walk($child);
            }
            unset($active[$node]);
            $visited[$node] = true;
        };
        foreach (array_keys($graph) as $node) {
            $walk($node);
        }
    }
}
