<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu laporan reaksi pasca-transfusi. Pengamatan, bukan perubahan status: tidak
 * ada unit maupun permintaan yang berpindah status karenanya. Tanpa derajat
 * keparahan dan tanpa identitas pasien -- disengaja, lihat migration-nya.
 *
 * @property int $id
 * @property string $public_id
 * @property int $transfusion_id
 * @property int|null $reaction_type_id
 * @property int $reported_by
 * @property Carbon $observed_at
 * @property string $description
 * @property Carbon $created_at
 */
final class TransfusionReaction extends Model
{
    use Auditable;

    protected $fillable = ['observed_at', 'description'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Transfusion, $this> */
    public function transfusion(): BelongsTo
    {
        return $this->belongsTo(Transfusion::class);
    }

    /** @return BelongsTo<TransfusionReactionType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TransfusionReactionType::class, 'reaction_type_id');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa id internal maupun reported_by.
        return [
            'id' => $this->public_id,
            'transfusion_id' => $this->transfusion->public_id,
            'reaction_type_code' => $this->type?->code,
            'observed_at' => $this->observed_at->format(DateTimeInterface::ATOM),
            'reported_at' => $this->created_at->format(DateTimeInterface::ATOM),
            'description' => $this->description,
        ];
    }
}
