<?php

namespace App\Shared\Http;

use App\Shared\Errors\ErrorCode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  CursorPaginator<int, mixed>  $page
     * @param  array<string, mixed>  $meta
     * @param  (callable(mixed): mixed)|null  $transform
     */
    public static function paginated(CursorPaginator $page, array $meta = [], ?callable $transform = null): JsonResponse
    {
        $links = ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()];
        $cursors = [
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
        ];

        // Kursor wajib dihitung dari model SEBELUM transform: ia memakai primary key internal, sedangkan 'id' hasil transform adalah public_id (UUID) -> `id < 'uuid'` -> 500.
        $items = $transform === null ? $page->items() : array_map($transform, $page->items());

        return response()->json([
            'data' => $items,
            'links' => $links,
            'meta' => array_merge([
                'per_page' => $page->perPage(),
                ...$cursors,
                'has_more' => $page->hasMorePages(),
            ], $meta),
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(ErrorCode $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        $traceId = request()->attributes->get('trace_id', (string) Str::uuid());

        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => (object) $details,
                'trace_id' => $traceId,
            ],
        ], $status);
    }
}
