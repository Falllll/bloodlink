<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Auth\FacilityMember;
use App\Shared\Auth\FacilityScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final class BindFacilityContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $facilityId = $user instanceof FacilityMember ? $user->facilityId() : null;

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        Context::add('facility_id', $facilityId);

        return $next($request);
    }
}
