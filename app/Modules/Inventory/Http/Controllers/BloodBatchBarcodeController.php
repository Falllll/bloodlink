<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\BloodBatch;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\BarcodeRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class BloodBatchBarcodeController
{
    /**
     * Dua lapis, urutannya menentukan kode HTTP:
     * 1. Izin inventory.view -- yang tidak berhak dijawab 403.
     * 2. Pencarian lewat visibleTo() -- unit fasilitas lain dijawab 404, bukan 403,
     *    supaya endpoint ini tidak bisa dipakai menebak public_id mana yang ada.
     */
    public function __invoke(Request $request, string $publicId, BarcodeRenderer $renderer): Response
    {
        Gate::authorize('viewAny', BloodBatch::class);

        /** @var User|null $user */
        $user = $request->user();

        $batch = BloodBatch::query()
            ->visibleTo($user)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return response($renderer->code128Svg($batch->batch_number), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
