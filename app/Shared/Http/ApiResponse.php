<?php

namespace App\Shared\Http;

use App\Shared\Errors\ErrorCode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ApiResponse
{
    public static function success(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    public static function paginated(CursorPaginator $page, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $page->items(),
            'meta' => array_merge([
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ], $meta),
        ]);
    }

    public static function error(ErrorCode $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        $traceId = request()->attributes->get('trace_id', (string) Str::uuid());

        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => $details,
                'trace_id' => $traceId,
            ],
        ], $status);
    }
}
