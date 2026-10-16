<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodBatch;
use App\Models\BloodRequestAllocation;
use App\Models\Crossmatch;
use App\Models\User;
use App\Modules\Request\Application\Exceptions\CrossmatchRejected;
use App\Modules\Request\Domain\Events\AllocatedUnitIssued;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA jalur sah ke status issued: TransitionBloodBatch menolak ISSUED
 * (issueIsGated). Penerbitan menuntut crossmatch TERBARU yang compatible --
 * bukan "pernah ada hasil compatible".
 *
 * Status unit dimiliki modul Inventory: modul ini memutuskan, lalu memancarkan
 * AllocatedUnitIssued yang ditulis listener Inventory SINKRON di transaksi ini.
 */
final class IssueAllocatedUnit
{
    // Literal, bukan enum: ModRequest tidak boleh mengimpor DomInventory (deptrac).
    // Harus sama dengan BatchStatus::RESERVED -- RequestInventoryLiteralsTest memeriksanya.
    public const string RESERVED = 'reserved';

    public function handle(BloodRequestAllocation $allocation, User $issuedBy): BloodRequestAllocation
    {
        DB::transaction(function () use ($allocation): void {
            // URUTAN KUNCI sama dengan reservasi (kartu 330): baris UNIT dulu, baru
            // baris alokasi. Satu baris per jenis di sini; kalau kelak beberapa unit
            // diterbitkan sekaligus, kunci dalam urutan blood_batch_id (preseden MergeDonors).
            $unit = BloodBatch::query()->whereKey($allocation->blood_batch_id)->lockForUpdate()->firstOrFail();
            $fresh = BloodRequestAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->released_at !== null) {
                throw CrossmatchRejected::allocationIsReleased();
            }

            // Yang berlaku hanya hasil TERBARU: compatible lalu incompatible = tidak boleh terbit.
            $latest = Crossmatch::query()
                ->where('blood_request_allocation_id', $fresh->id)
                ->orderByDesc('performed_at')
                ->orderByDesc('id')
                ->first();

            if ($latest === null) {
                throw CrossmatchRejected::noCrossmatchOnRecord();
            }

            if (! $latest->result->permitsIssue()) {
                throw CrossmatchRejected::latestResultForbidsIssue($latest->result);
            }

            $status = (string) $unit->getRawOriginal('status');

            if ($status !== self::RESERVED) {
                throw CrossmatchRejected::unitNotReserved($status);
            }

            event(new AllocatedUnitIssued($unit->id));

            // Reservasi selesai karena diterbitkan: kosongkan supaya timeout reservasi
            // (kartu 330) tidak kelak "melepas" kantong yang sudah ditransfusikan.
            $allocation->forceFill([
                'reserved_at' => null,
                'reservation_expires_at' => null,
                'reservation_release_reason' => 'issued',
            ])->save();
        });

        return $allocation;
    }
}
