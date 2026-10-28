<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Mengganti SELURUH pola mingguan appointment satu fasilitas (kartu 503). Jam adalah
 * jam dinding LOKAL di `timezone`. Daftar kosong = fasilitas kembali tanpa pembatasan.
 */
final class AppointmentScheduleRequest extends StrictRequest
{
    private const string CLOCK = '/^(?:[01]\d|2[0-3]):[0-5]\d$|^24:00$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'windows' => ['present', 'array', 'max:50'],
            'windows.*' => ['array:weekday,opens_at,closes_at,slot_minutes,capacity'],
            'windows.*.weekday' => ['required', 'integer', 'between:1,7'],
            'windows.*.opens_at' => ['required', 'string', 'regex:'.self::CLOCK],
            'windows.*.closes_at' => ['required', 'string', 'regex:'.self::CLOCK],
            'windows.*.slot_minutes' => ['required', 'integer', 'between:5,480'],
            'windows.*.capacity' => ['required', 'integer', 'between:1,500'],
        ];
    }

    /** Pesan yang bisa dibaca untuk aturan yang juga dijaga CHECK di database. */
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('windows', []) as $i => $window) {
                if (! is_array($window)) {
                    continue;
                }

                $opens = self::minute($window['opens_at'] ?? null);
                $closes = self::minute($window['closes_at'] ?? null);
                $slot = $window['slot_minutes'] ?? null;

                if ($opens === null || $closes === null || ! is_int($slot) || $slot <= 0) {
                    continue;
                }

                if ($opens >= $closes) {
                    $validator->errors()->add("windows.{$i}.closes_at", 'The closing time must be after the opening time.');
                } elseif (($closes - $opens) % $slot !== 0) {
                    $validator->errors()->add("windows.{$i}.slot_minutes", 'The window length must be a whole number of slots.');
                }
            }
        });
    }

    public static function minute(mixed $clock): ?int
    {
        if (! is_string($clock) || preg_match(self::CLOCK, $clock) !== 1) {
            return null;
        }

        [$h, $m] = array_map(intval(...), explode(':', $clock));

        return $h * 60 + $m;
    }
}
