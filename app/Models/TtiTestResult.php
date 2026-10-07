<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\TtiResult;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property TtiResult $result
 * @property bool $is_confirmatory
 * @property Carbon $tested_at
 */
final class TtiTestResult extends Model
{
    use Auditable;

    // public_id, blood_batch_id, donation_id, dan recorded_by ditentukan server
    // lewat forceFill() di RecordTtiTestResult.
    protected $fillable = ['tti_test_type_id', 'is_confirmatory', 'result', 'tested_at'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'result' => TtiResult::class,
            'is_confirmatory' => 'boolean',
            'tested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TtiTestType, $this> */
    public function testType(): BelongsTo
    {
        return $this->belongsTo(TtiTestType::class, 'tti_test_type_id');
    }

    /** @return BelongsTo<BloodBatch, $this> */
    public function bloodBatch(): BelongsTo
    {
        return $this->belongsTo(BloodBatch::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'test_code' => $this->testType->code->value,
            'is_confirmatory' => $this->is_confirmatory,
            'result' => $this->result->value,
            'tested_at' => $this->tested_at->toIso8601String(),
        ];
    }
}
