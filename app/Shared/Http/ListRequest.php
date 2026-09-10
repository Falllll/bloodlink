<?php

namespace App\Shared\Http;

use Illuminate\Foundation\Http\FormRequest;

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
            'sort' => ['sometimes', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $column = ltrim((string) $value, '-');

                if (! in_array($column, $this->sortable, true)) {
                    $fail("The selected {$attribute} is invalid.");
                }
            }],
            'filter' => ['sometimes', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                $unknown = array_diff(array_keys((array) $value), $this->filterable);

                if ($unknown !== []) {
                    $fail('The filter contains unsupported keys: '.implode(', ', $unknown).'.');
                }
            }],
        ];
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
