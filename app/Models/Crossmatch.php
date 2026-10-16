<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Request\Domain\CrossmatchResult;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu hasil uji silang untuk satu alokasi. Append-only lewat trigger
 * crossmatches_no_change; yang berlaku adalah baris terbaru per alokasi.
 *
 * SENGAJA tanpa ScopedToFacility: cakupan fasilitas datang lewat alokasi dan
 * permintaannya. Dua lapisan scope pada satu rantai relasi menyaring dua kali
 * dengan kolom yang tidak dimiliki tabel ini.
 *
 * @property int $id
 * @property string $public_id
 * @property int $blood_request_allocation_id
 * @property int $performed_by
 * @property CrossmatchResult $result
 * @property string|null $method
 * @property string|null $note
 * @property DateTimeImmutable $performed_at
 */
final class Crossmatch extends Model
{
    protected $fillable = ['result', 'method', 'note'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result' => CrossmatchResult::class,
            'performed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<BloodRequestAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(BloodRequestAllocation::class, 'blood_request_allocation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa id internal maupun performed_by.
        return [
            'id' => $this->public_id,
            'allocation_id' => $this->allocation->public_id,
            'result' => $this->result->value,
            'method' => $this->method,
            'note' => $this->note,
            'performed_at' => $this->performed_at->format(DateTimeInterface::ATOM),
        ];
    }
}
