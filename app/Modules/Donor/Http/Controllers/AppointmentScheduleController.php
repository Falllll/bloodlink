<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Facility;
use App\Models\FacilityAppointmentClosure;
use App\Models\FacilityAppointmentSchedule;
use App\Modules\Donor\Application\AppointmentSlots;
use App\Modules\Donor\Http\Requests\AppointmentClosureRequest;
use App\Modules\Donor\Http\Requests\AppointmentScheduleRequest;
use App\Modules\Donor\Http\Requests\AppointmentSlotsRequest;
use App\Shared\Http\ApiResponse;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pola jadwal, penutupan, dan ketersediaan slot appointment per fasilitas (kartu 503).
 * Mengubah = izin `update` fasilitas (admin fasilitas itu, atau operator global).
 * Membaca slot = izin `view` fasilitas, termasuk donor yang ingin memesan.
 */
final class AppointmentScheduleController
{
    public function show(Facility $facility): JsonResponse
    {
        Gate::authorize('view', $facility);

        return ApiResponse::success($this->present($facility));
    }

    /**
     * Mengganti seluruh pola secara atomik. Appointment yang sudah ada tidak pernah
     * divalidasi ulang: pola baru hanya berlaku untuk pemesanan BARU.
     */
    public function replace(AppointmentScheduleRequest $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        try {
            DB::transaction(function () use ($request, $facility): void {
                $facility->forceFill(['timezone' => (string) $request->validated('timezone')])->save();

                FacilityAppointmentSchedule::query()->where('facility_id', $facility->id)->delete();

                foreach ((array) $request->validated('windows') as $window) {
                    $row = new FacilityAppointmentSchedule([
                        'weekday' => (int) $window['weekday'],
                        'opens_minute' => AppointmentScheduleRequest::minute($window['opens_at']),
                        'closes_minute' => AppointmentScheduleRequest::minute($window['closes_at']),
                        'slot_minutes' => (int) $window['slot_minutes'],
                        'capacity' => (int) $window['capacity'],
                    ]);
                    $row->forceFill(['facility_id' => $facility->id])->save();
                }
            });
        } catch (QueryException $e) {
            // EXCLUDE facility_appointment_schedules_no_overlap: dua jendela tumpang-tindih di hari yang sama.
            if (str_contains($e->getMessage(), 'facility_appointment_schedules_no_overlap')) {
                throw ValidationException::withMessages(['windows' => 'Two windows on the same weekday overlap.']);
            }

            throw $e;
        }

        return ApiResponse::success($this->present($facility->refresh()));
    }

    public function addClosure(AppointmentClosureRequest $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        $closure = new FacilityAppointmentClosure([
            'closed_on' => (string) $request->validated('date'),
            'reason' => (string) $request->validated('reason'),
        ]);

        try {
            $closure->forceFill(['facility_id' => $facility->id, 'created_by' => $request->user()?->getAuthIdentifier()])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['date' => 'This date is already closed.']);
        }

        return ApiResponse::success($closure->toApiArray())->setStatusCode(201);
    }

    public function removeClosure(Facility $facility, string $date): JsonResponse
    {
        Gate::authorize('update', $facility);

        FacilityAppointmentClosure::query()
            ->where('facility_id', $facility->id)
            ->whereDate('closed_on', $date)
            ->delete();

        return ApiResponse::success(['date' => $date]);
    }

    public function slots(AppointmentSlotsRequest $request, Facility $facility, AppointmentSlots $slots): JsonResponse
    {
        Gate::authorize('view', $facility);

        $from = $request->validated('from')
            ?? Carbon::now(new DateTimeZone($facility->timezone))->toDateString();
        $days = (int) ($request->validated('days') ?? 7);
        $configured = $slots->hasSchedule($facility);

        return ApiResponse::success([
            'timezone' => $facility->timezone,
            // false = fasilitas belum punya pola: tidak ada slot untuk ditampilkan, dan
            // pemesanan di fasilitas ini belum dibatasi kapasitas. Klien jangan mengarang grid.
            'schedule_configured' => $configured,
            'slots' => $configured ? $slots->availability($facility, (string) $from, $days) : [],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Facility $facility): array
    {
        $today = Carbon::now(new DateTimeZone($facility->timezone))->toDateString();

        return [
            'timezone' => $facility->timezone,
            'windows' => FacilityAppointmentSchedule::query()
                ->where('facility_id', $facility->id)
                ->orderBy('weekday')->orderBy('opens_minute')
                ->get()
                ->map(fn (FacilityAppointmentSchedule $w): array => $w->toApiArray())
                ->all(),
            'closures' => FacilityAppointmentClosure::query()
                ->where('facility_id', $facility->id)
                ->whereDate('closed_on', '>=', $today)
                ->orderBy('closed_on')
                ->get()
                ->map(fn (FacilityAppointmentClosure $c): array => $c->toApiArray())
                ->all(),
        ];
    }
}
