<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsrController extends Controller
{
    /**
     * Get available ASR providers with their models.
     */
    public function providers(Request $request): JsonResponse
    {
        $user = $request->user();
        $providers = config('asr.providers', []);

        $result = [];
        foreach ($providers as $key => $config) {
            $credential = $user->getApiCredential($key, 'asr');

            $result[$key] = [
                'name' => $config['name'],
                'default_model' => $config['default_model'],
                'models' => array_map(fn ($m) => ['id' => $m, 'name' => $m], $config['models']),
                'has_credential' => $credential !== null,
                'async' => $config['async'] ?? false,
                'description' => $config['description'] ?? '',
            ];
        }

        return response()->json($result);
    }
}
