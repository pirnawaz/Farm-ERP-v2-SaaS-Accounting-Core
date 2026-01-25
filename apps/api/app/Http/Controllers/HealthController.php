<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $sha = getenv('GIT_SHA') ?: null;
        
        return response()->json([
            'ok' => true,
            'service' => 'api',
            'sha' => $sha,
        ]);
    }
}
