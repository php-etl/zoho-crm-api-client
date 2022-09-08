<?php

declare(strict_types=1);

namespace Kiboko\Component\Flow\ZohoCRM\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;

class AuthenticationMiddleware implements ClientInterface
{
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    public function __construct(
        private ClientInterface $decorated,
        private RequestFactoryInterface $requestFactory,
        private UriFactoryInterface $uriFactory,
        private string $oauthBaseUri,
        private string $clientId,
        private string $clientSecret,
        private string $grantToken,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->accessToken === null) {
            $this->generateToken();
        }

        $response = $this->tryRequest($request);
        if ($response->getStatusCode() === 401) {
            $this->refreshToken();

            $response = $this->tryRequest($request);
        }

        return $response;
    }

    private function tryRequest(RequestInterface $request): ResponseInterface
    {
        $request = $request->withHeader('Authorization', sprintf('Bearer %s', $this->accessToken));

        return $this->decorated->sendRequest($request);
    }

    private function generateToken(): void
    {
        $response = $this->decorated->sendRequest(
            request: $this->requestFactory->createRequest(
                method: 'POST',
                uri: $this->uriFactory->createUri()
                    ->withQuery(http_build_query([
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'code' => $this->grantToken,
                        'grant_type' => 'authorization_code'
                    ]))
                    ->withPath('/oauth/v2/token')
                    ->withHost($this->oauthBaseUri)
                    ->withScheme('https')
            )
        );

        if ($response->getStatusCode() !== 200) {
            throw new AccessDeniedException('Something went wrong while generating your credentials. Please check your information.');
        }

        $credentials = json_decode($response->getBody()->getContents(), true);

        if (array_key_exists('error', $credentials)) {
            throw new InvalidCodeException('Invalid grant token. Please check your information.');
        }

        $this->accessToken = $credentials['access_token'];
        $this->refreshToken = $credentials['refresh_token'];
    }

    private function refreshToken(): void
    {
        $response = $this->decorated->sendRequest(
            $this->requestFactory->createRequest(
                method: 'POST',
                uri: $this->uriFactory->createUri()
                    ->withQuery(http_build_query([
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'refresh_token' => $this->refreshToken,
                        'grant_type' => 'refresh_token'
                    ]))
                    ->withPath('/oauth/v2/token')
                    ->withHost($this->oauthBaseUri)
                    ->withScheme('https')
            )
        );

        if ($response->getStatusCode() !== 200) {
            throw new AccessDeniedException('Something went wrong while refreshing your credentials. Please check your information.');
        }

        $credentials = json_decode($response->getBody()->getContents(), true);

        if (array_key_exists('error', $credentials)) {
            throw new InvalidCodeException('Invalid grant token. Please check your information.');
        }

        $this->accessToken = $credentials["access_token"];
        $this->refreshToken = $credentials["refresh_token"];
    }
}
