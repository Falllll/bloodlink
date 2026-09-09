<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EnsureIdempotency
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        if (blank($key)) {
            return ApiResponse::error(
                ErrorCode::IDEMPOTENCY_KEY_REQUIRED,
                'The Idempotency-Key header is required for this request.',
                [],
                400,
            );
        }

        $hash = hash('sha256', (string) $request->getContent());
        $id = (string) Str::uuid();

        if (! $this->claim($id, $key, $hash, $request)) {
            return $this->replayOrConflict($key, $hash, $request);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            DB::table('idempotency_keys')->where('id', $id)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
            ]);
        } else {
            DB::table('idempotency_keys')->where('id', $id)->delete();
        }

        return $response;
    }

    /**
     * Menyisipkan baris klaim. Mengembalikan true kalau request INI yang memenangkan key-nya.
     */
    private function claim(string $id, string $key, string $hash, Request $request): bool
    {
        $rows = DB::affectingStatement(
            'INSERT INTO idempotency_keys (id, key, user_id, endpoint, request_hash, locked_at, created_at)
             VALUES (?, ?, ?, ?, ?, now(), now())
             ON CONFLICT (user_id, key) DO NOTHING',
            [
                $id,
                $key,
                $request->user()?->id,
                $request->method().' '.$request->path(),
                $hash,
            ]
        );

        return $rows === 1;
    }

    /**
     * Dipanggil kalau key sudah dimiliki request lain.
     */
    private function replayOrConflict(string $key, string $hash, Request $request): Response
    {
        $userId = $request->user()?->id;

        $row = DB::table('idempotency_keys')
            ->where('key', $key)
            ->where(function ($query) use ($userId): void {
                if ($userId === null) {
                    $query->whereNull('user_id');
                } else {
                    $query->where('user_id', $userId);
                }
            })
            ->first();

        if ($row === null) {
            return ApiResponse::error(
                ErrorCode::REQUEST_IN_PROGRESS,
                'The original request with this Idempotency-Key is still being processed.',
                [],
                409,
            );
        }

        if ($row->request_hash !== $hash) {
            return ApiResponse::error(
                ErrorCode::IDEMPOTENCY_KEY_REUSED,
                'This Idempotency-Key was already used with a different request body.',
                [],
                422,
            );
        }

        if ($row->response_body === null) {
            return ApiResponse::error(
                ErrorCode::REQUEST_IN_PROGRESS,
                'The original request with this Idempotency-Key is still being processed.',
                [],
                409,
            );
        }

        return response()->json(json_decode((string) $row->response_body, true), $row->response_status)
            ->header('Idempotency-Replayed', 'true');
    }
}
