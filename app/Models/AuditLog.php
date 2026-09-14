<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array{before: array<string, mixed>, after: array<string, mixed>} $changes
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'datetime'];
    }
}
