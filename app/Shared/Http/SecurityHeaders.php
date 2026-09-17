<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    /** @var array<string, string> */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; form-action 'none'; base-uri 'none';",
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'Cross-Origin-Resource-Policy' => 'same-site',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            $response->headers->set($header, $value);
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('security.hsts_max_age').'; includeSubDomains'
            );
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
