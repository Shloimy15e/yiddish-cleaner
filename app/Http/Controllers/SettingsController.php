<?php

namespace App\Http\Controllers;

use App\Models\ApiCredential;
use App\Services\Google\GoogleAuthService;
use App\Services\Llm\LlmManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function credentials(Request $request, LlmManager $llm): Response
    {
        $user = $request->user();

        $apiCredentials = $user->apiCredentials()->get();

        return Inertia::render('settings/Credentials', [
            'apiCredentials' => $apiCredentials,
            'googleCredential' => $user->googleCredential,
            'llmProviders' => $llm->getProviders(),
            'asrProviders' => ['whisper', 'google_asr'],
        ]);
    }

    public function storeApiCredential(Request $request): RedirectResponse
    {
        $request->validate([
            'provider' => 'required|string',
            'type' => 'required|in:llm,asr',
            'api_key' => 'required|string',
            'default_model' => 'nullable|string',
        ]);

        $user = $request->user();

        ApiCredential::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $request->provider,
                'type' => $request->type,
            ],
            [
                'api_key' => $request->api_key,
                'default_model' => $request->default_model,
                'is_active' => true,
            ]
        );

        return back()->with('success', 'API credential saved.');
    }

    public function deleteApiCredential(Request $request, string $provider, string $type): RedirectResponse
    {
        $request->user()->apiCredentials()
            ->where('provider', $provider)
            ->where('type', $type)
            ->delete();

        return back()->with('success', 'API credential removed.');
    }

    public function googleRedirect(GoogleAuthService $auth): RedirectResponse
    {
        return redirect($auth->getAuthUrl());
    }

    public function googleCallback(Request $request, GoogleAuthService $auth): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('settings.index')
                ->withErrors(['google' => 'Google authorization failed.']);
        }

        $code = $request->get('code');
        $token = $auth->exchangeCode($code);

        if (isset($token['error'])) {
            return redirect()->route('settings.index')
                ->withErrors(['google' => $token['error_description'] ?? 'Token exchange failed.']);
        }

        $auth->storeTokens($request->user(), $token);

        return redirect()->route('settings.index')
            ->with('success', 'Google account connected.');
    }

    public function googleDisconnect(Request $request, GoogleAuthService $auth): RedirectResponse
    {
        $auth->revokeCredentials($request->user());

        return back()->with('success', 'Google account disconnected.');
    }
}
