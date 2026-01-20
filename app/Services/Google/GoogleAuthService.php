<?php

namespace App\Services\Google;

use App\Models\GoogleCredential;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Sheets;

class GoogleAuthService
{
    protected GoogleClient $client;

    public function __construct()
    {
        $this->client = new GoogleClient;
        $this->client->setApplicationName(config('app.name'));
        $this->client->setScopes([
            Drive::DRIVE,
            Sheets::SPREADSHEETS,
        ]);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');

        // Set OAuth credentials from config
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));

        // Use GOOGLE_REDIRECT_URI env var for local dev with ngrok, otherwise use route
        $redirectUri = config('services.google.redirect_uri')
            ?? route('settings.google.callback');
        $this->client->setRedirectUri($redirectUri);
    }

    /**
     * Get the authorization URL for OAuth flow.
     */
    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for tokens.
     */
    public function exchangeCode(string $code): array
    {
        return $this->client->fetchAccessTokenWithAuthCode($code);
    }

    /**
     * Store tokens for a user.
     */
    public function storeTokens(User $user, array $token): GoogleCredential
    {
        return GoogleCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'token' => $token,
                'expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds($token['expires_in'])
                    : null,
            ]
        );
    }

    /**
     * Get an authenticated client for a user.
     */
    public function getClientForUser(User $user): ?GoogleClient
    {
        $credential = $user->googleCredential;

        if (! $credential) {
            return null;
        }

        $this->client->setAccessToken($credential->token);

        // Refresh if expired
        if ($this->client->isAccessTokenExpired()) {
            if ($refreshToken = $credential->getRefreshToken()) {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

                // Preserve refresh token if not returned
                if (! isset($newToken['refresh_token'])) {
                    $newToken['refresh_token'] = $refreshToken;
                }

                $this->storeTokens($user, $newToken);
                $this->client->setAccessToken($newToken);
            } else {
                return null; // Need to re-authenticate
            }
        }

        return $this->client;
    }

    /**
     * Check if user has valid Google credentials.
     */
    public function hasValidCredentials(User $user): bool
    {
        return $this->getClientForUser($user) !== null;
    }

    /**
     * Revoke credentials for a user.
     */
    public function revokeCredentials(User $user): void
    {
        $credential = $user->googleCredential;

        if ($credential) {
            $this->client->setAccessToken($credential->token);
            $this->client->revokeToken();
            $credential->delete();
        }
    }
}
