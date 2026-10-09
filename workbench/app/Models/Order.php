<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $amount_minor
 * @property string $currency
 * @property bool $is_refunded
 * @property bool $is_cancelled */
final class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'is_refunded' => 'boolean', 'is_cancelled' => 'boolean'];
    }
}
