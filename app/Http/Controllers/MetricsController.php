<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MetricsController extends Controller
{
    public function index(Request $request)
    {
        $apiUrl = $request->query('api') ?? 'query';
        $response = Http::get(config('services.prometheus.url').'/api/v1/'.$apiUrl, [
            ...$request->query(),
        ]);

        return response(
        )->json(
            $response->json(),
            $response->status()
        );
    }
}
