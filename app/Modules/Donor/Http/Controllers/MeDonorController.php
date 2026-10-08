<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donation;
use App\Models\User;
use App\Modules\Donor\Application\ResolveDonorForUser;
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
