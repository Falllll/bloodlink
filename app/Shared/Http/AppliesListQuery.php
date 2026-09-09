<?php

namespace App\Shared\Http;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

trait AppliesListQuery
{
    /**
     * Apply filter/sort/pagination to $query using $request's validated input.
     *
     * Callers that restrict columns with ->select(...) must include the sort
     * column in that select, or cursor pagination silently breaks past page 1.
     */
    protected function listing(Builder $query, ListRequest $request): CursorPaginator
    {
        foreach ($request->filters() as $column => $value) {
            if (! in_array($column, $request->filterable(), true)) {
                continue;
            }

            $query->where($column, $value);
        }

        $sort = $request->sort();
        $column = $sort === null ? null : ltrim($sort, '-');

        if ($column !== null && in_array($column, $request->sortable(), true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $query->orderBy('id');

        $perPage = min($request->perPage() ?? 25, 100);

        return $query->cursorPaginate($perPage, cursor: $request->cursor());
    }
}
