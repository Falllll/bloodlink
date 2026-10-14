<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Modules\Request\Application\Exceptions\BloodRequestNotAllocatable;
use App\Modules\Request\Domain\RequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FEFO (First Expired First Out) DI BAWAH gerbang kompatibilitas kartu 315:
 * query menyaring kasar dan mengurutkan, gerbang yang memutuskan boleh/tidak.
 * Matriks kompatibilitas sengaja tidak ditulis ulang di WHERE -- aturan medis
 * tidak boleh hidup di dua tempat, apalagi yang satu tanpa provenans.
 *
 * Tidak menyentuh status unit: yang dialokasikan tetap released sampai
 * dipesan (kartu 330) atau diterbitkan (kartu 350).
 */
final class AllocateUnitsFefo
{
    // Nilai literal, bukan enum: ModRequest tidak boleh mengimpor DomInventory
    // (deptrac). Harus sama dengan BatchStatus::RELEASED dan TransferStatus::isOpen()
    // -- FefoAllocationTest memeriksa kesamaannya.
    public const string RELEASED = 'released';

    /** @var list<string> */
    public const array OPEN_TRANSFER_STATUSES = ['requested', 'approved', 'shipped'];

    private const int BATCH = 50;

    public function __construct(private readonly CheckBloodCompatibility $compatibility) {}

    /**
     * Kandidat terurut FEFO yang kompatibel. Tidak menulis apa pun.
     *
     * @return Collection<int, BloodBatch>
     */
    public function candidatesFor(BloodRequest $request, int $limit): Collection
    {
        /** @var Collection<int, BloodBatch> $picked */
        $picked = new Collection;

        // Trombosit: gerbang membalas UNSUPPORTED untuk semua unit; tidak perlu query.
        if ($limit < 1 || CheckBloodCompatibility::directionFor($request->component) === null) {
            return $picked;
        }

        /** @var array<string, bool> $verdicts */
        $verdicts = [];
        $after = null;

        // Keyset per batch, bukan "ambil limit x N lalu saring": unit tak kompatibel
        // yang kedaluwarsa lebih dulu tidak boleh memotong hasil diam-diam.
        do {
            $batch = $this->candidateQuery($request)
                ->when($after !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('expires_at', '>', $after->expires_at)
                    ->orWhere(fn (Builder $tie) => $tie->where('expires_at', $after->expires_at)->where('id', '>', $after->id))))
                ->limit(self::BATCH)
                ->get();

            foreach ($batch as $unit) {
                /** @var BloodBatch $unit */
                $key = $unit->getAttribute('blood_group').'|'.$unit->getAttribute('rh_factor');
                $verdicts[$key] ??= $this->compatibility->forUnit($request, $unit)->isCompatible();

                if ($verdicts[$key]) {
                    $picked->push($unit);

                    if ($picked->count() >= $limit) {
                        return $picked;
                    }
                }
            }

            $after = $batch->last();
        } while ($batch->count() === self::BATCH);

        return $picked;
    }

    /**
     * Alokasikan sebanyak mungkin, maksimal sisa kuantitas permintaan.
     *
     * @return list<BloodRequestAllocation>
     */
    public function handle(BloodRequest $request, int $allocatedBy, ?int $max = null): array
    {
        return DB::transaction(function () use ($request, $allocatedBy, $max): array {
            // Kunci baris permintaan: dua alokasi serentak untuk permintaan yang sama
            // tidak boleh sama-sama menghitung sisa yang sama lalu melampaui quantity.
            // Unique index hanya menjaga "satu unit, satu alokasi". URUTAN KUNCI:
            // permintaan dulu, baru baris alokasi -- sama dengan release().
            $fresh = BloodRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            // Dibatalkan/kedaluwarsa: tidak bisa menerima kantong lagi. Fulfilled BUKAN
            // penolakan -- sisanya nol, jadi hasilnya kosong (sudah penuh, bukan error).
            if ($fresh->status->isTerminal()) {
                throw BloodRequestNotAllocatable::closed($fresh->status);
            }

            $remaining = $fresh->quantity - $this->activeCount($fresh);

            if ($remaining <= 0) {
                return [];
            }

            $want = $max === null ? $remaining : min($max, $remaining);
            $allocations = [];

            // Cadangan kandidat: sebagian bisa direbut alokasi lain di antara baca dan tulis.
            foreach ($this->candidatesFor($fresh, $want * 2) as $unit) {
                if (count($allocations) >= $want) {
                    break;
                }

                $allocation = $this->tryAllocate($fresh, $unit, $allocatedBy);

                if ($allocation !== null) {
                    $allocations[] = $allocation;
                }
            }

            $this->syncStatus($fresh);
            $request->setRawAttributes($fresh->getAttributes(), sync: true);

            return $allocations;
        });
    }

    public function release(BloodRequestAllocation $allocation, string $reason): BloodRequestAllocation
    {
        DB::transaction(function () use ($allocation, $reason): void {
            $request = BloodRequest::query()->whereKey($allocation->blood_request_id)->lockForUpdate()->firstOrFail();
            $fresh = BloodRequestAllocation::query()->whereKey($allocation->getKey())->lockForUpdate()->firstOrFail();

            // Idempoten: DELETE tidak melewati EnsureIdempotency, jadi logikanya sendiri yang menjaga.
            if (! $fresh->isActive()) {
                $allocation->setRawAttributes($fresh->getAttributes(), sync: true);

                return;
            }

            $allocation->forceFill(['released_at' => now(), 'release_reason' => $reason])->save();

            $this->syncStatus($request);
        });

        return $allocation;
    }

    /**
     * Penyaring kasar beserta urutan FEFO. Gerbang kompatibilitas memutuskan
     * sisanya. Publik supaya EXPLAIN di test memeriksa query yang sama persis.
     *
     * @return Builder<BloodBatch>
     */
    public function candidateQuery(BloodRequest $request): Builder
    {
        return BloodBatch::query()
            // Hanya fasilitas peminta: memindahkan stok antar fasilitas milik kartu 300.
            ->where('facility_id', $request->ownerFacilityId())
            ->where('status', self::RELEASED)
            ->where('component', $request->component->value)
            // NULL berarti masa simpan belum pernah ditentukan: mengalokasikannya
            // bukan FEFO, itu menebak. Dikecualikan, bukan ditaruh di akhir.
            ->whereNotNull('expires_at')
            // TURUNAN, bukan aturan bersumber: kantong yang mati sebelum jadwal
            // transfusi tidak memenuhi permintaan itu.
            ->where('expires_at', '>=', $request->needed_by)
            ->whereNotNull('blood_group')
            ->whereNotNull('rh_factor')
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('blood_request_allocations')
                ->whereColumn('blood_request_allocations.blood_batch_id', 'blood_batches.id')
                ->whereNull('blood_request_allocations.released_at'))
            // Unit di perjalanan tetap released dan tetap milik fasilitas asal (kartu 300).
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('stock_transfers')
                ->whereColumn('stock_transfers.blood_batch_id', 'blood_batches.id')
                ->whereIn('stock_transfers.status', self::OPEN_TRANSFER_STATUSES))
            // Eksplisit, tidak bergantung pada default PostgreSQL. Tie-breaker id
            // membuat urutan deterministik untuk expires_at yang identik.
            ->orderByRaw('expires_at ASC NULLS LAST')
            ->orderBy('id');
    }

    private function tryAllocate(BloodRequest $request, BloodBatch $unit, int $allocatedBy): ?BloodRequestAllocation
    {
        try {
            // Savepoint per baris: unique violation di Postgres membatalkan transaksi luar.
            return DB::transaction(function () use ($request, $unit, $allocatedBy): BloodRequestAllocation {
                $allocation = new BloodRequestAllocation;
                $allocation->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'blood_request_id' => $request->id,
                    'blood_batch_id' => $unit->id,
                    'allocated_by' => $allocatedBy,
                    'allocated_at' => now(),
                ])->save();

                return $allocation;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Kondisi lomba yang wajar: unit direbut alokasi lain. Kartu 330 yang
            // mengubahnya jadi 409 yang rapi; di sini lanjut ke kandidat berikutnya.
            if (str_contains($e->getMessage(), 'blood_request_allocations_one_active_per_unit')) {
                return null;
            }

            throw $e;
        }
    }

    private function activeCount(BloodRequest $request): int
    {
        return BloodRequestAllocation::query()
            ->where('blood_request_id', $request->id)
            ->whereNull('released_at')
            ->count();
    }

    /** Naik/turun lewat graf RequestStatus, tidak pernah ditulis langsung. */
    private function syncStatus(BloodRequest $request): void
    {
        $active = $this->activeCount($request);

        $target = match (true) {
            $active === 0 => RequestStatus::OPEN,
            $active < $request->quantity => RequestStatus::PARTIALLY_FULFILLED,
            default => RequestStatus::FULFILLED,
        };

        if ($target !== $request->status && $request->status->canTransitionTo($target)) {
            $request->forceFill(['status' => $target])->save();
        }
    }
}
