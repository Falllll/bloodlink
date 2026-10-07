<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\TtiTestCode;
use App\Modules\Inventory\Domain\TtiTestRequirement;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property TtiTestCode $code
 * @property TtiTestRequirement $requirement
 * @property bool $is_active
 */
final class TtiTestType extends Model
{
    use Auditable;

    protected $fillable = [
        'jurisdiction', 'code', 'label', 'requirement',
        'preferred_min_sensitivity_percent', 'preferred_min_specificity_percent',
        'source_reference', 'is_active',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'code' => TtiTestCode::class,
            'requirement' => TtiTestRequirement::class,
            'preferred_min_sensitivity_percent' => 'decimal:1',
            'preferred_min_specificity_percent' => 'decimal:1',
            'is_active' => 'boolean',
        ];
    }
}
