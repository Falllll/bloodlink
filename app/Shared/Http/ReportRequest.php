<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Reporting\ReportGranularity;
use App\Shared\Reporting\ReportWindow;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;
use LogicException;

/**
 * Parameter bersama endpoint laporan (kartu 440). Sengaja BUKAN ListRequest:
 * hasil laporan kecil dan tetap, tidak berpaginasi.
 */
final class ReportRequest extends StrictRequest
{
    private ?ReportWindow $window = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Gerbang injeksi SQL: hanya kasus enum yang lolos ke date_trunc().
            'granularity' => ['required', Rule::enum(ReportGranularity::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (ValidationValidator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Tanpa batas, granularity=day&from=2000-01-01 adalah DoS yang kita tandatangani sendiri.
            $max = (int) config('security.report_max_buckets');
            $window = ReportWindow::between(
                $this->dateOf('from'),
                $this->dateOf('to'),
                ReportGranularity::from((string) $this->input('granularity')),
                $max,
            );

            if ($window === null) {
                $validator->addFailure('to', 'too_many_buckets', [(string) $max]);

                return;
            }

            $this->window = $window;
        });
    }

    public function window(): ReportWindow
    {
        return $this->window ?? throw new LogicException('ReportRequest belum tervalidasi.');
    }

    private function dateOf(string $key): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->input($key), new DateTimeZone('UTC'));

        return $date ?: throw new LogicException("Tanggal {$key} lolos validasi tapi tidak terbaca.");
    }
}
