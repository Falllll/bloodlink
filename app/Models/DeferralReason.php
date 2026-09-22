<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Donor\Domain\DeferralDurationUnit;
use App\Modules\Donor\Domain\DeferralType;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property DeferralType $type
 * @property DeferralDurationUnit|null $default_duration_unit
 */
final class DeferralReason extends Model
{
    use Auditable;

    protected $fillable = [
        'jurisdiction', 'code', 'type', 'default_duration_value',
        'default_duration_unit', 'label', 'anchor_note', 'source_reference', 'is_active',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'type' => DeferralType::class,
            'default_duration_unit' => DeferralDurationUnit::class,
            'default_duration_value' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isPermanent(): bool
    {
        return $this->type === DeferralType::PERMANENT;
    }
}
