<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Support;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sort(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::sort(...), $value);
        }
        if (! is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Action arguments must be JSON values.');
        }

        return $value;
    }
}
