<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu uji regional (§6.2) yang di-opt-in sebuah fasilitas. */
final class FacilityTtiPanelEntry extends Model
{
    use Auditable;

    protected $table = 'facility_tti_panels';

    protected $fillable = ['facility_id', 'tti_test_type_id'];

    protected $hidden = ['id'];

    /** @return BelongsTo<TtiTestType, $this> */
    public function testType(): BelongsTo
    {
        return $this->belongsTo(TtiTestType::class, 'tti_test_type_id');
    }
}
