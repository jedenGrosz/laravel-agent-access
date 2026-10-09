<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Rules;

final readonly class RoleHierarchy
{
    /** @var array<string, list<string>> */
    private array $children;

    /** @var array<string, list<string>> */
    private array $parents;

    /** @var list<string> */
    private array $nodes;

    /** @var list<list<string>> */
    public array $edges;

    /** Raw configuration is validated before being exposed as typed graph edges.
     * @param array<array-key, mixed> $edges */
    public function __construct(array $edges = [])
    {
        if (! array_is_list($edges)) {
            throw new \InvalidArgumentException('Hierarchy edges must be a list.');
        }
        $children = $parents = $nodes = $seen = [];
        $validated = [];
        foreach ($edges as $edge) {
            if (! is_array($edge) || ! array_is_list($edge) || count($edge) !== 2 || ! is_string($edge[0]) || ! is_string($edge[1]) ||
                trim($edge[0]) === '' || trim($edge[1]) === '' || $edge[0] === $edge[1]) {
                throw new \InvalidArgumentException('Invalid hierarchy edge.');
            }
            $key = json_encode($edge, JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Duplicate hierarchy edge.');
            }
            $seen[$key] = true;
            [$parent, $child] = $edge;
            $validated[] = [$parent, $child];
            $nodes[$parent] = $parent;
            $nodes[$child] = $child;
            $children[$parent][] = $child;
            $parents[$child][] = $parent;
        }
        foreach ($children as &$values) {
            sort($values, SORT_STRING);
        }
        unset($values);
        foreach ($parents as &$values) {
            sort($values, SORT_STRING);
        }
        unset($values);
        $this->nodes = array_values($nodes);
        $this->edges = $validated;
        $this->children = $children;
        $this->parents = $parents;
        $degrees = [];
        $queue = [];
        foreach ($this->nodes as $node) {
            $degrees[$node] = count($parents[$node] ?? []);
            if ($degrees[$node] === 0) {
                $queue[] = $node;
            }
        }
        for ($index = 0; $index < count($queue); $index++) {
            foreach ($children[$queue[$index]] ?? [] as $child) {
                if (--$degrees[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }
        if (count($queue) !== count($this->nodes)) {
            throw new \InvalidArgumentException('Hierarchy contains a cycle.');
        }
    }

    /** @return list<string> */
    public function roles(): array
    {
        $roles = $this->nodes;
        sort($roles, SORT_STRING);

        return $roles;
    }

    /** @return list<string> */
    public function roots(): array
    {
        return array_values(array_filter($this->roles(), fn (string $role): bool => ! isset($this->parents[$role])));
    }

    /** @return list<string> */
    public function ancestors(string $role): array
    {
        return $this->related($role, $this->parents);
    }

    /** @return list<string> */
    public function descendants(string $role): array
    {
        return $this->related($role, $this->children);
    }

    /** @param array<string, list<string>> $graph
     * @return list<string> */
    private function related(string $role, array $graph): array
    {
        $queue = [$role];
        $seen = [$role => true];
        $distance = [$role => 0];
        $result = [];
        for ($index = 0; $index < count($queue); $index++) {
            foreach ($graph[$queue[$index]] ?? [] as $node) {
                if (! isset($seen[$node])) {
                    $seen[$node] = true;
                    $distance[$node] = $distance[$queue[$index]] + 1;
                    $queue[] = $node;
                    $result[] = $node;
                }
            }
        }

        usort($result, fn (string $a, string $b): int => ($distance[$a] <=> $distance[$b]) ?: strcmp($a, $b));

        return $result;
    }

    /** @return list<string>|null */
    public function shortestPath(string $ancestor, string $descendant): ?array
    {
        if (! in_array($ancestor, $this->nodes, true) || ! in_array($descendant, $this->nodes, true)) {
            return null;
        }
        $queue = [[$ancestor]];
        $seen = [$ancestor => true];
        for ($index = 0; $index < count($queue); $index++) {
            $path = $queue[$index];
            $node = $path[count($path) - 1];
            if ($node === $descendant) {
                return $path;
            }
            foreach ($this->children[$node] ?? [] as $child) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $queue[] = [...$path, $child];
                }
            }
        }

        return null;
    }

    public function distance(string $ancestor, string $descendant): ?int
    {
        $path = $this->shortestPath($ancestor, $descendant);

        return $path === null ? null : count($path) - 1;
    }

    /** @param list<string> $grantorRoles
     * @param list<string> $targetRoles
     * @return list<array{role: string, distance: int|null, path: list<string>|null}> */
    public function rankGrantors(array $grantorRoles, array $targetRoles): array
    {
        sort($targetRoles, SORT_STRING);
        $ranked = [];
        foreach (array_unique($grantorRoles) as $role) {
            $best = null;
            foreach ($targetRoles as $target) {
                $path = $this->shortestPath($role, $target);
                if ($path !== null && ($best === null || count($path) < count($best))) {
                    $best = $path;
                }
            }
            $ranked[] = ['role' => $role, 'distance' => $best === null ? null : count($best) - 1, 'path' => $best];
        }
        usort($ranked, fn (array $a, array $b): int => (($a['distance'] ?? PHP_INT_MAX) <=> ($b['distance'] ?? PHP_INT_MAX)) ?: strcmp($a['role'], $b['role']));

        return $ranked;
    }

    /** @return list<string> */
    public function render(): array
    {
        $lines = [];
        $expanded = [];
        $walk = function (string $node, string $prefix, string $connector) use (&$walk, &$lines, &$expanded): void {
            $shared = isset($expanded[$node]);
            $lines[] = $prefix.$connector.$node.($shared ? ' [shared]' : '');
            if ($shared) {
                return;
            }
            $expanded[$node] = true;
            $children = $this->children[$node] ?? [];
            foreach ($children as $index => $child) {
                $walk($child, $prefix.($connector === '' ? '' : ($connector === '`-- ' ? '    ' : '|   ')),
                    $index === count($children) - 1 ? '`-- ' : '|-- ');
            }
        };
        foreach ($this->roots() as $root) {
            $walk($root, '', '');
        }

        return $lines;
    }
}
