<?php

namespace App\Shared\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class ListRequest extends FormRequest
{
    /**
     * Filter keys that may appear in the `filter[...]` input.
     *
     * @var array<int, string>
     */
    protected array $filterable = [];

    /**
     * Columns that may be named in the `sort` input (without the `-` prefix).
     *
     * @var array<int, string>
     */
    protected array $sortable = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string'],
            'filter' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnsortableColumn($validator),
            fn (Validator $validator) => $this->rejectUnsupportedFilters($validator),
        ];
    }

    private function rejectUnsortableColumn(Validator $validator): void
    {
        if (! $this->has('sort') || $validator->errors()->has('sort')) {
            return;
        }

        $column = ltrim((string) $this->input('sort'), '-');

        if (! in_array($column, $this->sortable, true)) {
            $validator->addFailure('sort', 'invalid_sort', [$column]);
        }
    }

    private function rejectUnsupportedFilters(Validator $validator): void
    {
        if (! $this->has('filter') || $validator->errors()->has('filter')) {
            return;
        }

        $unknown = array_values(array_diff(array_keys((array) $this->input('filter')), $this->filterable));

        if ($unknown !== []) {
            $validator->addFailure('filter', 'unsupported_filter', array_map('strval', $unknown));
        }
    }

    /**
     * @return array<int, string>
     */
    public function sortable(): array
    {
        return $this->sortable;
    }

    /**
     * @return array<int, string>
     */
    public function filterable(): array
    {
        return $this->filterable;
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }

    public function perPage(): ?int
    {
        $perPage = $this->validated('per_page');

        return $perPage === null ? null : (int) $perPage;
    }

    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return (array) $this->validated('filter', []);
    }
}
