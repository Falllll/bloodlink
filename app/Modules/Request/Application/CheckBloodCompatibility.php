<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Modules\Request\Domain\CompatibilityDecision;
use App\Modules\Request\Domain\CompatibilityDirection;
use App\Modules\Request\Domain\CompatibilityOutcome;
use App\Modules\Request\Domain\RuleProvenance;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Illuminate\Support\Facades\DB;

/**
 * Gerbang kompatibilitas ABO/RhD: menjawab boleh atau tidak, tidak memilih unit
 * (FEFO, kartu 320) dan tidak menggantikan crossmatch laboratorium (kartu 350).
 *
 * Aturannya data ter-seed ber-provenans (CompatibilityRuleSeeder), bukan `match`
 * di kode. Tidak ada baris berarti TIDAK BOLEH -- "tidak ketemu" tidak pernah
 * menjadi boleh.
 */
final class CheckBloodCompatibility
{
    private const string JURISDICTION = 'WHO';

    public function forUnit(BloodRequest $request, BloodBatch $unit): CompatibilityDecision
    {
        $direction = self::directionFor($request->component);

        if ($direction === null) {
            return self::unsupported($request->component);
        }

        // Gagal-tertutup: unit karantina belum bergolongan, dan golongan yang
        // belum diketahui tidak boleh dialokasikan ke siapa pun.
        // getAttribute(): kolomnya nullable, dan pembacaan bertipe dari migrasi
        // menyembunyikan NULL itu dari analisis statis.
        $rawGroup = $unit->getAttribute('blood_group');
        $rawRh = $unit->getAttribute('rh_factor');
        $donorGroup = is_string($rawGroup) ? BloodGroup::tryFrom($rawGroup) : null;
        $donorRh = is_string($rawRh) ? RhFactor::tryFrom($rawRh) : null;

        if ($donorGroup === null || $donorRh === null) {
            return new CompatibilityDecision(
                CompatibilityOutcome::INCOMPATIBLE,
                null,
                [],
                'The unit has no determined ABO group or RhD type.',
            );
        }

        return $this->check($direction, $request->blood_group, $request->rh_factor, $donorGroup, $donorRh);
    }

    public function check(
        CompatibilityDirection $direction,
        BloodGroup $recipientGroup,
        RhFactor $recipientRh,
        BloodGroup $donorGroup,
        RhFactor $donorRh,
    ): CompatibilityDecision {
        $abo = DB::table('abo_compatibility_rules')
            ->where('jurisdiction', self::JURISDICTION)
            ->where('direction', $direction->value)
            ->where('recipient_group', $recipientGroup->value)
            ->where('donor_group', $donorGroup->value)
            ->first(['provenance', 'source_reference']);

        if ($abo === null) {
            return new CompatibilityDecision(
                CompatibilityOutcome::INCOMPATIBLE,
                null,
                [],
                "No {$direction->value} ABO rule allows donor {$donorGroup->value} for recipient {$recipientGroup->value}.",
            );
        }

        $rows = [$abo];

        // RhD hanya untuk sel darah merah: tidak ada aturan RhD plasma di sumber
        // mana pun (Rujukan §12.5 butir 5), jadi plasma diperiksa ABO saja.
        if ($direction === CompatibilityDirection::RED_CELLS) {
            $rh = DB::table('rh_compatibility_rules')
                ->where('jurisdiction', self::JURISDICTION)
                ->where('recipient_rh', $recipientRh->value)
                ->where('donor_rh', $donorRh->value)
                ->first(['provenance', 'source_reference']);

            if ($rh === null) {
                return new CompatibilityDecision(
                    CompatibilityOutcome::INCOMPATIBLE,
                    null,
                    [],
                    "No RhD rule allows donor {$donorRh->value} for recipient {$recipientRh->value}.",
                );
            }

            $rows[] = $rh;
        }

        // Provenans TERLEMAH yang dibawa: satu baris turunan membuat seluruh
        // keputusan turunan, supaya ketergantungan pada aturan tak-bersumber terlihat.
        $provenances = array_map(fn (object $row): RuleProvenance => RuleProvenance::from((string) $row->provenance), $rows);

        return new CompatibilityDecision(
            CompatibilityOutcome::COMPATIBLE,
            in_array(RuleProvenance::DERIVED, $provenances, true) ? RuleProvenance::DERIVED : RuleProvenance::CITED,
            array_values(array_unique(array_map(fn (object $row): string => (string) $row->source_reference, $rows))),
        );
    }

    /** Null berarti tidak ada aturan bersumber untuk komponen ini. */
    public static function directionFor(BloodComponent $component): ?CompatibilityDirection
    {
        return match ($component) {
            BloodComponent::WHOLE_BLOOD, BloodComponent::PACKED_RED_CELLS => CompatibilityDirection::RED_CELLS,
            BloodComponent::FRESH_FROZEN_PLASMA, BloodComponent::CRYOPRECIPITATE => CompatibilityDirection::PLASMA,
            // WHO tidak memberi matriks trombosit, hanya "whenever possible" (Rujukan §12.6).
            // Menurunkan matriks dari situ adalah mengarang.
            BloodComponent::PLATELET_CONCENTRATE => null,
        };
    }

    public static function unsupported(BloodComponent $component): CompatibilityDecision
    {
        return new CompatibilityDecision(
            CompatibilityOutcome::UNSUPPORTED,
            null,
            [],
            "No sourced compatibility rule exists for {$component->value} (Rujukan Pedoman Medis §12.6).",
        );
    }
}
