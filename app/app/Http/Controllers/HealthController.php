<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'alive'])
            ->header('Cache-Control', 'no-store');
    }

    public function ready(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            // Não reportar detalhes de conexão ou credenciais em logs/respostas.
            return response()->json(['status' => 'not_ready'], 503)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['status' => 'ready'])
            ->header('Cache-Control', 'no-store');
    }
}
