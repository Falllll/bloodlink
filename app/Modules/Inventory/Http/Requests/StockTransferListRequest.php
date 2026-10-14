<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Http\ListRequest;

/**
 * `direction` bukan kolom: ia divalidasi di sini, dikeluarkan dari filters()
 * supaya AppliesListQuery tidak menulis WHERE direction = ..., dan diterapkan
 * controller sebagai incoming (to_facility_id) / outgoing (from_facility_id).
 */
final class StockTransferListRequest extends ListRequest
{
    public const string INCOMING = 'incoming';

    public const string OUTGOING = 'outgoing';

    /** @var array<int, string> */
    protected array $filterable = ['status', 'direction'];

    /** @var array<int, string> */
    protected array $sortable = ['created_at'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filter.direction' => ['sometimes', 'in:'.self::INCOMING.','.self::OUTGOING],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $filters = parent::filters();
        unset($filters['direction']);

        return $filters;
    }

    public function direction(): ?string
    {
        $direction = parent::filters()['direction'] ?? null;

        return is_string($direction) ? $direction : null;
    }
}
