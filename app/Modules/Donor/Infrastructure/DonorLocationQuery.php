<?php

declare(strict_types=1);

namespace App\Modules\Donor\Infrastructure;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single PostGIS search helper for donor radius lookups.
 */
final class DonorLocationQuery
{
    /**
     * @param  float  $latitude  Latitude in degrees, -90..90
     * @param  float  $longitude  Longitude in degrees, -180..180
     * @param  int  $radiusMeters  Radius in meters
     */
    public function withinRadius(float $latitude, float $longitude, int $radiusMeters): Builder
    {
        return DB::table('donors')
            ->whereNotNull('location')
            ->whereRaw(
                'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$longitude, $latitude, $radiusMeters]
            );
    }

    /**
     * Distance can be selected or ordered without blocking the GiST index.
     */
    public function withinRadiusOrderedByDistance(float $latitude, float $longitude, int $radiusMeters): Builder
    {
        return $this->withinRadius($latitude, $longitude, $radiusMeters)
            ->select('donors.*')
            ->selectRaw(
                'ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters',
                [$longitude, $latitude]
            )
            ->orderBy('distance_meters');
    }
}
