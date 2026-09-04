<?php

namespace App\Http\Controllers\Health;

use Illuminate\Http\JsonResponse;

class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
