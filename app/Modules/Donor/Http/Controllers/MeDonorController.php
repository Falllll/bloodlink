<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donation;
use App\Models\User;
use App\Modules\Donor\Application\DonorEligibilityService;
use App\Modules\Donor\Application\ResolveDonorForUser;
use App\Modules\Donor\Domain\EligibilityDecision;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Donor diselesaikan dari token, bukan dari parameter path: tanpa ID di URL
 * tidak ada IDOR yang perlu dijaga policy, jadi tidak ada Gate::authorize().
 */
final class MeDonorController
{
    public function __construct(private readonly ResolveDonorForUser $resolve) {}

    public function show(Request $request): JsonResponse
    {
        $donor = $this->resolve->handle($this->user($request));

        return ApiResponse::success($donor->toApiArray());
    }

    /**
     * Kelayakan berbasis RIWAYAT, bukan kuesioner (kartu 537). Berbeda dari
     * POST /eligibility/self-check: di sini deferral aktif dan flag administratif
     * ikut diperhitungkan, karena itu is_provisional false.
     *
     * Data kesehatan: tidak ditulis ke log maupun audit_logs (endpoint ini hanya membaca).
     */
    public function eligibility(Request $request, DonorEligibilityService $eligibility): JsonResponse
    {
        $donor = $this->resolve->handle($this->user($request));

        return ApiResponse::success($this->present($eligibility->for($donor)));
    }

    /**
     * Duplikasi SADAR dari EligibilitySelfCheckController::present(): bentuknya harus
     * identik supaya klien cukup punya satu parser, bedanya hanya is_provisional.
     * Belasan baris duplikat lebih murah daripada abstraksi bersama yang salah.
     *
     * @return array<string, mixed>
     */
    private function present(EligibilityDecision $decision): array
    {
        return [
            'outcome' => $decision->outcome->value,
            'is_provisional' => false,
            'retry_after_date' => $decision->retryAfterDate?->format('Y-m-d'),
            'findings' => array_map(fn ($f) => [
                'code' => $f->code->value,
                'outcome' => $f->outcome->value,
                'retry_after_date' => $f->retryAfterDate?->format('Y-m-d'),
                'detail' => $f->detail,
            ], $decision->findings),
        ];
    }

    public function donations(Request $request): JsonResponse
    {
        $donor = $this->resolve->handle($this->user($request));

        // Sengaja TANPA visibleTo(): akun donor tidak berfasilitas, jadi scope
        // itu membalas 1 = 0 dan riwayatnya hilang tanpa suara.
        $page = $donor->donations()
            ->with(['donor', 'appointment', 'screening'])
            ->orderBy('completed_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate(20);

        // Transformer lewat ApiResponse, BUKAN $page->through(): kursor harus dihitung dari model.
        return ApiResponse::paginated($page, transform: fn (Donation $donation): array => $donation->toApiArray());
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
