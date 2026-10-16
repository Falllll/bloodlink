<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Master data jenis reaksi transfusi. Hanya baris terkutip dari Rujukan Pedoman
 * Medis (provenance 'cited'); kosong itu sah.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string|null $description
 * @property string $source_reference
 * @property string $provenance
 * @property bool $is_active
 */
final class TransfusionReactionType extends Model
{
    use Auditable;

    protected $fillable = ['code', 'label', 'description', 'source_reference', 'provenance', 'is_active'];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
