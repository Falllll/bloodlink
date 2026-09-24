<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;

final class RecordScreeningRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Batas-batas di bawah adalah kewarasan alat ukur, BUKAN §3.3/§3.4.
        // Kalau ditulis min:120 pada Hb, skrining yang gagal berubah diam-diam
        // jadi 422 dan tidak pernah tercatat -- persis yang dilarang
        // Keputusan teknis 4.
        return [
            'haemoglobin_g_dl' => ['required', 'numeric', 'min:1', 'max:30'],
            'systolic_mmhg' => ['required', 'integer', 'min:40', 'max:300'],
            'diastolic_mmhg' => ['required', 'integer', 'min:20', 'max:200'],
            'pulse_bpm' => ['required', 'integer', 'min:20', 'max:250'],
            'temperature_c' => ['required', 'numeric', 'min:30', 'max:45'],
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:300'],
            'planned_volume_ml' => ['nullable', 'integer', 'in:350,450'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
