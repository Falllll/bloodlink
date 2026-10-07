<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Modules\Donor\Application\SummariseDeferralStatus;
use App\Shared\Http\ListRequest;
use Illuminate\Validation\Rule;

final class DonorListRequest extends ListRequest
{
    /** Bukan kolom donors: diterapkan SummariseDeferralStatus, bukan oleh listing(). */
    private const string DEFERRAL_STATUS = 'deferral_status';

    /** @var array<int, string> */
    protected array $filterable = ['blood_group', 'rh_factor', 'sex', 'city', self::DEFERRAL_STATUS];

    /** @var array<int, string> */
    protected array $sortable = ['full_name', 'donor_number', 'last_donation_date', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter.'.self::DEFERRAL_STATUS => ['sometimes', Rule::in([
                SummariseDeferralStatus::NONE,
                SummariseDeferralStatus::TEMPORARY,
                SummariseDeferralStatus::PERMANENT,
            ])],
        ]);
    }

    public function searchTerm(): ?string
    {
        $term = trim((string) $this->validated('search', ''));

        return $term === '' ? null : $term;
    }

    public function deferralStatus(): ?string
    {
        return parent::filters()[self::DEFERRAL_STATUS] ?? null;
    }

    /** Filter kolom saja; deferral_status diambil lewat deferralStatus(). */
    public function filters(): array
    {
        return array_diff_key(parent::filters(), [self::DEFERRAL_STATUS => true]);
    }
}
