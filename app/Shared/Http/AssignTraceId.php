<?php

namespace App\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssignTraceId
{
    public function handle(Request $request, Closure $next)
    {
        $traceId = (string) Str::uuid();
        $request->attributes->set('trace_id', $traceId);
        Log::withContext(['trace_id' => $traceId]);

        return $next($request);
    }
}
