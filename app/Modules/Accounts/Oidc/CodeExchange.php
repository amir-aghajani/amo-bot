<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Oidc;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * The authorization-code flow's last step (PKCE): the code the provider sent the browser back with, exchanged at its
 * token endpoint for the sign-in's id_token — the site's Client ID and secret as HTTP Basic, the form's fields as
 * application/x-www-form-urlencoded, the verifier whose challenge began the sign-in. Through the shop's outgoing client.
 */
final class CodeExchange
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The id_token the code is worth — null when the provider refused it (spent, expired, another site's, a wrong
     * secret: the provider's reason goes to the log, never the secret).
     *
     * @throws ProviderUnreachableException when the provider could not be asked, or did not answer as its API does
     */
    public function idToken(OidcProvider $provider, string $clientId, #[\SensitiveParameter] string $clientSecret, string $code, string $redirectUri, string $verifier): ?string
    {
        try {
            $response = $this->http->request('POST', $provider->tokenEndpoint, [
                'auth' => [$clientId, $clientSecret],
                // The secret goes to the token endpoint and nowhere else: an answer that sends it on is no answer.
                'allow_redirects' => false,
                'headers' => ['Accept' => 'application/json'],
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'code_verifier' => $verifier,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new ProviderUnreachableException("{$provider->tokenEndpoint}: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        $answer = json_decode((string) $response->getBody(), true);
        if ($status >= 400 && $status < 500 && $status !== 429) {
            $this->logger->warning('{issuer} refused a sign-in\'s code ({status}): {error}', [
                'issuer' => $provider->name(),
                'status' => $status,
                'error' => is_array($answer) && is_string($answer['error'] ?? null) ? mb_substr($answer['error'], 0, 100) : 'no reason given',
            ]);

            return null;
        }
        if ($status !== 200 || !is_array($answer) || !is_string($answer['id_token'] ?? null) || $answer['id_token'] === '') {
            throw new ProviderUnreachableException("{$provider->tokenEndpoint} answered {$status} without an id_token.");
        }

        return $answer['id_token'];
    }
}
