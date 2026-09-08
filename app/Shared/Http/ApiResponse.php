<?php

namespace App\Shared\Http;

use App\Shared\Errors\ErrorCode;
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
