<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Modules\Request\Domain\RequestStatus;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mencatat apa yang diminta. SENGAJA tidak memeriksa kompatibilitas (kartu 315)
 * maupun ketersediaan stok (kartu 320): permintaan yang mustahil dipenuhi tetap
 * tercatat, dan alokasilah yang menolaknya.
 */
final class CreateBloodRequest
{
    public function handle(
        int $facilityId,
        int $requestedBy,
        BloodComponent $component,
        BloodGroup $bloodGroup,
        RhFactor $rhFactor,
        int $quantity,
        RequestUrgency $urgency,
        DateTimeImmutable $neededBy,
        string $patientReference,
        ?string $note = null,
    ): BloodRequest {
        return DB::transaction(function () use ($facilityId, $requestedBy, $component, $bloodGroup, $rhFactor, $quantity, $urgency, $neededBy, $patientReference, $note): BloodRequest {
            $request = new BloodRequest([
                'patient_reference' => $patientReference,
                'component' => $component,
                'blood_group' => $bloodGroup,
                'rh_factor' => $rhFactor,
                'quantity' => $quantity,
                'urgency' => $urgency,
                'needed_by' => $neededBy,
                'note' => $note,
            ]);

            $request->assignFacility($facilityId);

            // Selalu lahir OPEN, apa pun yang dikirim klien; alur persetujuan belum ada.
            $request->forceFill([
                'public_id' => (string) Str::uuid(),
                'status' => RequestStatus::OPEN,
                'requested_by' => $requestedBy,
            ])->save();

            return $request;
        });
    }
}
