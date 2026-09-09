<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignTraceId
{
    private const MAX_HEADER_LENGTH = 64;

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');

        $traceId = (blank($incoming) || strlen($incoming) > self::MAX_HEADER_LENGTH)
            ? (string) Str::uuid()
            : $incoming;

        $request->attributes->set('trace_id', $traceId);
        Context::add('trace_id', $traceId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $traceId);

        return $response;
    }
}
