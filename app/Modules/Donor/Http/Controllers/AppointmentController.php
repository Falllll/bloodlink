<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Appointment;
use App\Models\Donor;
use App\Models\User;
use App\Modules\Donor\Application\BookAppointment;
use App\Modules\Donor\Application\TransitionAppointment;
use App\Modules\Donor\Http\Requests\AppointmentListRequest;
use App\Modules\Donor\Http\Requests\BookAppointmentRequest;
use App\Modules\Donor\Http\Requests\TransitionAppointmentRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

final class AppointmentController
{
    use AppliesListQuery;

    public function index(AppointmentListRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        /** @var CursorPaginator<int, Appointment> $page */
        $page = $this->listing(Appointment::query()->visibleTo($user)->with('donor'), $request);

        return ApiResponse::paginated($page->through(fn (Appointment $appointment) => $appointment->toApiArray()));
    }

    public function store(BookAppointmentRequest $request, Donor $donor, BookAppointment $bookAppointment): JsonResponse
    {
        Gate::authorize('bookAppointment', $donor);

        $scheduledForInput = $request->validated('scheduled_for');

        $appointment = $bookAppointment->handle(
            $donor,
            $scheduledForInput !== null ? new DateTimeImmutable($scheduledForInput) : null,
            $request->user()?->id,
            $request->validated('note'),
        );

        return ApiResponse::success($appointment->toApiArray())->setStatusCode(201);
    }

    public function transition(
        TransitionAppointmentRequest $request,
        Appointment $appointment,
        TransitionAppointment $transitionAppointment,
    ): JsonResponse {
        Gate::authorize('transitionAppointment', $appointment->donor);

        $appointment = $transitionAppointment->handle($appointment, $request->status());

        return ApiResponse::success($appointment->toApiArray());
    }
}
