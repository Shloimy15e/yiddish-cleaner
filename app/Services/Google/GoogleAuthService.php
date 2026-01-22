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
        if ($this->shouldUseServiceAccount()) {
            $this->configureServiceAccount();
        } else {
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
        if ($this->shouldUseServiceAccount()) {
            return $this->client;
        }

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
        if ($this->shouldUseServiceAccount()) {
            return true;
        }

        return $this->getClientForUser($user) !== null;
    }

    /**
     * Revoke credentials for a user.
     */
    public function revokeCredentials(User $user): void
    {
        if ($this->shouldUseServiceAccount()) {
            return;
        }

        $credential = $user->googleCredential;

        if ($credential) {
            $this->client->setAccessToken($credential->token);
            $this->client->revokeToken();
            $credential->delete();
        }
    }

    public function usesServiceAccount(): bool
    {
        return $this->shouldUseServiceAccount();
    }

    protected function shouldUseServiceAccount(): bool
    {
        return app()->environment('local')
            && (bool) config('services.google.service_account_json');
    }

    protected function configureServiceAccount(): void
    {
        $json = config('services.google.service_account_json');
        $decoded = json_decode((string) $json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new \RuntimeException('Invalid GOOGLE_SERVICE_ACCOUNT_JSON value.');
        }

        $this->client->setAuthConfig($decoded);
    }
}
