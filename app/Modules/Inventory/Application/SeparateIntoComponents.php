<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Modules\Inventory\Application\Exceptions\ComponentSeparationRejected;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\BloodComponent;
use App\Modules\Inventory\Domain\ComponentExpiryPolicy;
use App\Modules\Inventory\Domain\ComponentSeparationPlan;
use App\Modules\Inventory\Domain\DerivedComponentSpec;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Satu kantong whole blood -> N turunan, masing-masing baris sendiri yang
 * menunjuk induknya (parent_unit_id). Turunan dan pembaruan induk commit
 * bersama atau tidak sama sekali.
 */
final class SeparateIntoComponents
{
    public function __construct(
        private ComponentExpiryPolicy $expiryPolicy,
        private GenerateUnitNumber $unitNumbers,
    ) {}

    /** @return list<BloodBatch> */
    public function handle(BloodBatch $unit, ComponentSeparationPlan $plan): array
    {
        return DB::transaction(function () use ($unit, $plan): array {
            // Kunci baris induk: dua pemisahan serentak atas kantong yang sama
            // tidak boleh sama-sama lolos cek status dan volume.
            $parent = BloodBatch::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            $this->assertSeparable($parent, $plan);

            $facilityCode = (string) Facility::withTrashed()->whereKey($parent->facility_id)->value('code');

            $children = array_map(
                fn (DerivedComponentSpec $spec): BloodBatch => $this->derive($parent, $spec, $facilityCode),
                $plan->components,
            );

            $parent->forceFill([
                'status' => BatchStatus::SEPARATED,
                'separated_at' => new DateTimeImmutable,
                'separated_volume_ml' => $parent->separated_volume_ml + $plan->totalVolumeMl(),
            ])->save();

            $unit->setRawAttributes($parent->getAttributes(), sync: true);

            return $children;
        });
    }

    private function assertSeparable(BloodBatch $parent, ComponentSeparationPlan $plan): void
    {
        if ($parent->parent_unit_id !== null) {
            throw ComponentSeparationRejected::alreadyDerived();
        }

        if ($parent->component !== BloodComponent::WHOLE_BLOOD->value) {
            throw ComponentSeparationRejected::notWholeBlood($parent->component);
        }

        if (! $parent->status->canTransitionTo(BatchStatus::SEPARATED)) {
            throw ComponentSeparationRejected::notSeparable($parent->status);
        }

        $available = $parent->volume_ml - $parent->separated_volume_ml;

        if ($plan->totalVolumeMl() > $available) {
            throw ComponentSeparationRejected::volumeExceeded($plan->totalVolumeMl(), $available);
        }
    }

    private function derive(BloodBatch $parent, DerivedComponentSpec $spec, string $facilityCode): BloodBatch
    {
        // Acuan kedaluwarsa = waktu pengambilan ASLI induk, bukan waktu pemisahan:
        // turunan tidak pernah lebih segar dari darah asalnya.
        $collectedAt = $parent->collected_at->toDateTimeImmutable();

        $child = new BloodBatch([
            'component' => $spec->component->value,
            'blood_group' => $parent->blood_group,
            'rh_factor' => $parent->rh_factor,
            'volume_ml' => $spec->volumeMl,
            'donor_id' => $parent->donor_id,
            'collected_at' => $collectedAt,
        ]);

        $child->assignFacility($parent->facility_id);

        $child->forceFill([
            'public_id' => (string) Str::uuid(),
            'parent_unit_id' => $parent->id,
            // donation_id unique milik induk (Kartu 190); jejak ke donasi lewat induk.
            'donation_id' => null,
            // §5: belum diuji, jadi karantina -- apa pun status induknya.
            'status' => BatchStatus::QUARANTINED,
            ...$this->expiryPolicy->resolve($spec->component->value, $spec->storageTemperatureC, $collectedAt),
        ]);

        $this->unitNumbers->retrying(
            $facilityCode,
            $collectedAt,
            fn (string $number): bool => $child->forceFill(['batch_number' => $number])->save(),
        );

        return $child;
    }
}
