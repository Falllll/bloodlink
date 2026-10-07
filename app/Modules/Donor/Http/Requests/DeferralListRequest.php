<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\ListRequest;

final class DeferralListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['active'];

    /** @var array<int, string> */
    protected array $sortable = ['anchor_at', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'filter.active' => ['sometimes', 'boolean'],
        ]);
    }

    /** null = semua deferral, termasuk yang sudah dicabut atau berakhir. */
    public function active(): ?bool
    {
        $value = parent::filters()['active'] ?? null;

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** `active` bukan kolom: diterapkan controller dengan definisi "aktif" yang sama. */
    public function filters(): array
    {
        return array_diff_key(parent::filters(), ['active' => true]);
    }
}
