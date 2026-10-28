<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Appointment;
use App\Models\Facility;
use App\Models\FacilityAppointmentClosure;
use App\Models\FacilityAppointmentSchedule;
use App\Modules\Donor\Application\Exceptions\AppointmentSlotRejected;
use App\Modules\Donor\Domain\AppointmentStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Slot appointment yang dihitung dari pola mingguan fasilitas (kartu 503).
 *
 * ZONA WAKTU, ditulis eksplisit: pola memakai jam dinding LOKAL fasilitas
 * (facilities.timezone); appointments.scheduled_for adalah instan UTC. Setiap
 * perbandingan mengonversi lewat zona fasilitas, tidak pernah lewat zona server.
 *
 * KAPASITAS dijaga kunci baris di database, bukan oleh `if` saja: assertBookable()
 * mengunci baris jendela pola (FOR UPDATE) sebelum menghitung okupansi, jadi dua
 * pemesanan slot terakhir yang bersamaan berjalan berurutan dan yang kedua melihat
 * slotnya penuh (DonorAppointmentSlotRaceTest). Pemanggil WAJIB di dalam transaksi
 * yang juga menyisipkan appointment-nya.
 *
 * Fasilitas TANPA pola sama sekali tidak dibatasi -- perilaku sebelum kartu ini --
 * supaya pemesanan tidak berhenti total sampai admin mengisi polanya.
 */
final class AppointmentSlots
{
    public const int MAX_DAYS = 31;

    public function hasSchedule(Facility $facility): bool
    {
        return FacilityAppointmentSchedule::query()->where('facility_id', $facility->id)->exists();
    }

    /** @param  DateTimeImmutable  $start  instan; zonanya dihormati, dibandingkan dalam UTC */
    public function assertBookable(Facility $facility, DateTimeImmutable $start): void
    {
        if (! $this->hasSchedule($facility)) {
            return;
        }

        $utc = Carbon::instance($start)->toImmutable()->setTimezone('UTC');
        $local = $utc->setTimezone(new DateTimeZone($facility->timezone));
        $minute = $local->hour * 60 + $local->minute;

        // Kunci baris jendela: pemesanan lain untuk jendela ini menunggu di sini.
        $window = FacilityAppointmentSchedule::query()
            ->where('facility_id', $facility->id)
            ->where('weekday', $local->dayOfWeekIso)
            ->where('opens_minute', '<=', $minute)
            ->where('closes_minute', '>', $minute)
            ->lockForUpdate()
            ->first();

        if ($window === null || $local->second !== 0 || $local->microsecond !== 0
            || ($minute - $window->opens_minute) % $window->slot_minutes !== 0) {
            throw AppointmentSlotRejected::notASlot();
        }

        if ($this->closedOn($facility, $local->toDateString())) {
            throw AppointmentSlotRejected::closed();
        }

        // Semua appointment yang tidak dibatalkan memakan slotnya.
        $booked = Appointment::query()
            ->where('facility_id', $facility->id)
            ->where('scheduled_for', $utc->format('Y-m-d H:i:s'))
            ->where('status', '!=', AppointmentStatus::CANCELLED->value)
            ->count();

        if ($booked >= $window->capacity) {
            throw AppointmentSlotRejected::full();
        }
    }

    /**
     * Slot mendatang mulai tanggal lokal $from selama $days hari, dalam zona fasilitas.
     *
     * @return list<array{starts_at: string, ends_at: string, capacity: int, booked: int, available: int}>
     */
    public function availability(Facility $facility, string $from, int $days): array
    {
        $zone = new DateTimeZone($facility->timezone);
        $firstDay = Carbon::createFromFormat('Y-m-d', $from, $zone)->toImmutable()->startOfDay();
        $endDay = $firstDay->addDays($days);

        $windows = FacilityAppointmentSchedule::query()
            ->where('facility_id', $facility->id)
            ->orderBy('weekday')->orderBy('opens_minute')
            ->get()
            ->groupBy('weekday');

        $closed = FacilityAppointmentClosure::query()
            ->where('facility_id', $facility->id)
            ->whereBetween('closed_on', [$firstDay->toDateString(), $endDay->toDateString()])
            ->pluck('closed_on')
            ->map(fn ($date): string => $date->toDateString())
            ->flip();

        // Okupansi seluruh rentang dalam SATU query, dikunci instan UTC.
        $booked = Appointment::query()
            ->where('facility_id', $facility->id)
            ->where('status', '!=', AppointmentStatus::CANCELLED->value)
            ->whereBetween('scheduled_for', [
                $firstDay->setTimezone('UTC')->format('Y-m-d H:i:s'),
                $endDay->setTimezone('UTC')->format('Y-m-d H:i:s'),
            ])
            ->toBase()
            ->selectRaw('scheduled_for, count(*) as total')
            ->groupBy('scheduled_for')
            ->pluck('total', 'scheduled_for');

        $now = Carbon::now()->toImmutable();
        $slots = [];

        for ($day = $firstDay; $day->lessThan($endDay); $day = $day->addDay()) {
            if (isset($closed[$day->toDateString()])) {
                continue;
            }

            foreach ($windows->get($day->dayOfWeekIso, collect()) as $window) {
                for ($m = $window->opens_minute; $m < $window->closes_minute; $m += $window->slot_minutes) {
                    $start = $day->addMinutes($m);

                    if ($start->lessThanOrEqualTo($now)) {
                        continue;
                    }

                    $taken = (int) ($booked[$start->setTimezone('UTC')->format('Y-m-d H:i:s')] ?? 0);

                    $slots[] = [
                        'starts_at' => $start->toIso8601String(),
                        'ends_at' => $start->addMinutes($window->slot_minutes)->toIso8601String(),
                        'capacity' => $window->capacity,
                        'booked' => $taken,
                        'available' => max(0, $window->capacity - $taken),
                    ];
                }
            }
        }

        return $slots;
    }

    private function closedOn(Facility $facility, string $localDate): bool
    {
        return FacilityAppointmentClosure::query()
            ->where('facility_id', $facility->id)
            ->whereDate('closed_on', $localDate)
            ->exists();
    }
}
