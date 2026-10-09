<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

abstract class AccessModel extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function freshTimestamp(): Carbon
    {
        return Carbon::instance(CarbonImmutable::now('UTC'))->utc();
    }

    protected function asDateTime($value): Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }
        if (is_int($value) || is_float($value)) {
            return Carbon::createFromTimestampUTC($value);
        }
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Invalid audit or grant timestamp.');
        }

        return Carbon::parse($value, 'UTC')->utc();
    }
}
