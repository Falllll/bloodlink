<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Appointment;
use App\Models\User;
use App\Modules\Donor\Application\BookAppointment;
use App\Modules\Donor\Application\ResolveDonorForUser;
use App\Modules\Donor\Application\TransitionAppointment;
use App\Modules\Donor\Http\Requests\BookOwnAppointmentRequest;
use App\Modules\Donor\Http\Requests\CancelOwnAppointmentRequest;
use App\Shared\Http\ApiResponse;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Donor diselesaikan dari token, bukan dari parameter path. Appointment pun
 * dicari di dalam baris milik donor itu, jadi milik orang lain TIDAK ADA --
 * bukan ditemukan lalu ditolak. Karena itu tidak ada Gate::authorize() di sini.
 */
final class MeAppointmentController
{
    public function __construct(private readonly ResolveDonorForUser $resolve) {}

    public function index(Request $request): JsonResponse
    {
        $donor = $this->resolve->handle($this->user($request));

        // Sengaja TANPA visibleTo(): akun donor tidak berfasilitas, jadi scope
        // itu membalas 1 = 0 dan riwayatnya hilang tanpa suara.
        //
        // Walk-in petugas tidak punya scheduled_for. Mengurutkan kolom itu apa
        // adanya menaruh NULL di atas (DESC) dan menerbitkan kursor bernilai null
        // -> halaman berikutnya 500. Waktu walk-in adalah kedatangannya.
        $page = Appointment::query()
            ->select('appointments.*')
            ->selectRaw('COALESCE(scheduled_for, arrived_at) AS occurs_at')
            ->where('donor_id', $donor->id)
            ->with('donor')
            ->orderBy('occurs_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate(20);

        // Transformer lewat ApiResponse, BUKAN $page->through(): kursor harus dihitung dari model.
        return ApiResponse::paginated($page, transform: fn (Appointment $appointment): array => $appointment->toApiArray());
    }

    public function store(BookOwnAppointmentRequest $request, BookAppointment $bookAppointment): JsonResponse
    {
        $donor = $this->resolve->handle($this->user($request));

        // scheduled_for dijamin terisi oleh request: null di sini berarti walk-in ARRIVED.
        $appointment = $bookAppointment->handle(
            $donor,
            new DateTimeImmutable($request->validated('scheduled_for')),
            $request->user()?->id,
            $request->validated('note'),
        );

        return ApiResponse::success($appointment->toApiArray())->setStatusCode(201);
    }

    public function cancel(
        CancelOwnAppointmentRequest $request,
        string $appointment,
        TransitionAppointment $transitionAppointment,
    ): JsonResponse {
        $donor = $this->resolve->handle($this->user($request));

        // firstOrFail() SENGAJA: milik orang lain -> 404, bukan 403.
        $row = Appointment::query()
            ->where('donor_id', $donor->id)
            ->where('public_id', $appointment)
            ->firstOrFail();

        $row = $transitionAppointment->handle($row, $request->status());

        return ApiResponse::success($row->toApiArray());
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
