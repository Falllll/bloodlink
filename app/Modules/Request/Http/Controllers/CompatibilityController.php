<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Controllers;

use App\Models\BloodRequest;
use App\Modules\Request\Application\CheckBloodCompatibility;
use App\Modules\Request\Domain\CompatibilityDirection;
use App\Modules\Request\Domain\CompatibilityOutcome;
use App\Modules\Request\Http\Requests\CompatibilityQueryRequest;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Baca-saja: donor mana yang lolos gerbang untuk satu penerima. Supaya gerbang
 * bisa diperiksa tangan tanpa menunggu alokasi (kartu 320).
 */
final class CompatibilityController
{
    public function __invoke(CompatibilityQueryRequest $request, CheckBloodCompatibility $check): JsonResponse
    {
        // Matriks bukan data pasien, tapi tetap bukan endpoint publik.
        Gate::authorize('viewAny', BloodRequest::class);

        $component = BloodComponent::from($request->validated('component'));
        $recipientGroup = BloodGroup::from($request->validated('recipient_group'));
        $recipientRh = RhFactor::from($request->validated('recipient_rh'));

        $direction = CheckBloodCompatibility::directionFor($component);

        if ($direction === null) {
            return ApiResponse::success([
                'component' => $component->value,
                'outcome' => CompatibilityOutcome::UNSUPPORTED->value,
                'detail' => CheckBloodCompatibility::unsupported($component)->detail,
                'compatible_donors' => [],
            ]);
        }

        // Plasma tidak punya dimensi RhD: satu entri per golongan, donor_rh null = tidak diperiksa.
        $donorRhs = $direction === CompatibilityDirection::RED_CELLS ? RhFactor::cases() : [RhFactor::POSITIVE];

        $donors = [];
        foreach (BloodGroup::cases() as $donorGroup) {
            foreach ($donorRhs as $donorRh) {
                $decision = $check->check($direction, $recipientGroup, $recipientRh, $donorGroup, $donorRh);

                if (! $decision->isCompatible()) {
                    continue;
                }

                $donors[] = [
                    'donor_group' => $donorGroup->value,
                    'donor_rh' => $direction === CompatibilityDirection::RED_CELLS ? $donorRh->value : null,
                    'provenance' => $decision->provenance?->value,
                    'source_references' => $decision->sourceReferences,
                ];
            }
        }

        return ApiResponse::success([
            'component' => $component->value,
            'direction' => $direction->value,
            'outcome' => $donors === [] ? CompatibilityOutcome::INCOMPATIBLE->value : CompatibilityOutcome::COMPATIBLE->value,
            'compatible_donors' => $donors,
        ]);
    }
}
