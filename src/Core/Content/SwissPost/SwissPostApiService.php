<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataBetterCheckoutSW6\Service\SwissPost\Dto\SwissPostAddressValidationRequestDto;

class SwissPostApiService
{
    private const AUTH_URL = 'https://api.post.ch/OAuth/token';
    private const BASE_API_URL = 'https://dcapi.apis.post.ch/address/v1';
    private const CACHE_KEY_TOKEN = 'topdata_swiss_post_oauth_token';
    private const CACHE_KEY_PREFIX_ZIP = 'topdata_swiss_post_zip_';
    private const CACHE_KEY_PREFIX_STREET = 'topdata_swiss_post_street_';
    private const CACHE_KEY_PREFIX_HOUSENR = 'topdata_swiss_post_housenr_';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CacheItemPoolInterface $cache,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            'TopdataBetterCheckoutSW6.config.swissPostEnabled',
            $salesChannelId
        );
    }

    public function isValidationEnabled(?string $salesChannelId = null): bool
    {
        if (!$this->isEnabled($salesChannelId)) {
            return false;
        }

        return $this->systemConfigService->getBool(
            'TopdataBetterCheckoutSW6.config.swissPostValidationEnabled',
            $salesChannelId
        );
    }

    public function isAutocompleteEnabled(?string $salesChannelId = null): bool
    {
        if (!$this->isEnabled($salesChannelId)) {
            return false;
        }

        return $this->systemConfigService->getBool(
            'TopdataBetterCheckoutSW6.config.swissPostAutocompleteEnabled',
            $salesChannelId
        );
    }

    public function getAccessToken(?string $salesChannelId = null): ?string
    {
        $cacheKey = self::CACHE_KEY_TOKEN . '_' . ($salesChannelId ?? 'global');
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $clientId = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.swissPostClientId', $salesChannelId);
        $clientSecret = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.swissPostClientSecret', $salesChannelId);

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        $data = $this->requestAccessToken($clientId, $clientSecret);
        if ($data === null) {
            return null;
        }

        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3500);

        if ($token) {
            $cacheItem->set($token);
            $cacheItem->expiresAfter($expiresIn - 60);
            $this->cache->save($cacheItem);

            return $token;
        }

        return null;
    }

    public function testCredentials(string $clientId, string $clientSecret): array
    {
        if (empty($clientId) || empty($clientSecret)) {
            return ['success' => false, 'message' => 'Credentials must not be empty.'];
        }

        $data = $this->requestAccessToken($clientId, $clientSecret);

        if ($data !== null && !empty($data['access_token'])) {
            return ['success' => true];
        }

        return ['success' => false, 'message' => 'Authentication with Swiss Post API failed. Please check your credentials.'];
    }

    private function requestAccessToken(string $clientId, string $clientSecret): ?array
    {
        try {
            $body = http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'DCAPI_ADDRESS_VALIDATE DCAPI_ADDRESS_AUTOCOMPLETE',
            ]);

            $request = $this->requestFactory->createRequest('POST', self::AUTH_URL)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($this->streamFactory->createStream($body));

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Swiss Post Auth Failure', ['status' => $response->getStatusCode()]);

                return null;
            }

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Auth Exception', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    public function validateAddress(array $address, ?string $salesChannelId = null): array
    {
        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            return ['success' => false, 'error' => 'Could not authenticate with Swiss Post API'];
        }

        try {
            $split = $this->splitStreet($address['street'] ?? '');

            $dto = new SwissPostAddressValidationRequestDto(
                firstName: $address['firstName'] ?? '',
                lastName: $address['lastName'] ?? '',
                street: $split['streetName'],
                houseNumber: $split['houseNumber'],
                zip: $address['zipcode'] ?? '',
                city: $address['city'] ?? '',
                country: $address['countryCode'] ?? 'CH',
            );

            $payload = json_encode($dto);
            $uri = self::BASE_API_URL . '/addresses/validation';

            $request = $this->requestFactory->createRequest('POST', $uri)
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() === 401) {
                $this->invalidateTokenCache($salesChannelId);
                $token = $this->getAccessToken($salesChannelId);
                if ($token) {
                    $request = $request->withHeader('Authorization', 'Bearer ' . $token);
                    $response = $this->httpClient->sendRequest($request);
                } else {
                    return ['success' => false, 'error' => 'Could not re-authenticate with Swiss Post API'];
                }
            }

            $contents = $response->getBody()->getContents();

            if ($response->getStatusCode() === 200) {
                $result = json_decode($contents, true);

                return [
                    'success' => true,
                    'quality' => $result['quality'] ?? 'UNKNOWN',
                    'originalResponse' => $result,
                ];
            }

            return [
                'success' => false,
                'error' => 'API returned status ' . $response->getStatusCode(),
                'details' => json_decode($contents, true),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function splitStreet(string $street): array
    {
        if (preg_match('/^(.+?)\s+(\d[\d\s\-\/]*(?:[a-zA-Z])?)$/u', trim($street), $m)) {
            return [
                'streetName' => trim($m[1]),
                'houseNumber' => trim($m[2]),
            ];
        }

        return ['streetName' => $street, 'houseNumber' => ''];
    }

    private function invalidateTokenCache(?string $salesChannelId = null): void
    {
        $cacheKey = self::CACHE_KEY_TOKEN . '_' . ($salesChannelId ?? 'global');
        $this->cache->deleteItem($cacheKey);
    }

    public function autocompleteZip(string $query, ?string $salesChannelId = null): array
    {
        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX_ZIP . md5($query);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        try {
            $url = self::BASE_API_URL . '/zips?zipCity=' . urlencode($query) . '&type=DOMICILE';
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() === 401) {
                $this->invalidateTokenCache($salesChannelId);
                $token = $this->getAccessToken($salesChannelId);
                if ($token) {
                    $request = $request->withHeader('Authorization', 'Bearer ' . $token);
                    $response = $this->httpClient->sendRequest($request);
                } else {
                    return [];
                }
            }

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true) ?? [];

                $results = array_map(static fn ($item) => [
                    'zip' => $item['zip'] ?? '',
                    'city' => $item['city18'] ?? $item['city27'] ?? '',
                ], $data);

                $cacheItem->set($results);
                $cacheItem->expiresAfter(86400);
                $this->cache->save($cacheItem);

                return $results;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Autocomplete Exception', ['exception' => $e->getMessage()]);
        }

        return [];
    }

    public function autocompleteStreet(string $query, string $zip, ?string $salesChannelId = null): array
    {
        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX_STREET . md5($query . $zip);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        try {
            $url = self::BASE_API_URL . '/streets?street=' . urlencode($query) . '&zip=' . urlencode($zip) . '&type=DOMICILE';
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() === 401) {
                $this->invalidateTokenCache($salesChannelId);
                $token = $this->getAccessToken($salesChannelId);
                if ($token) {
                    $request = $request->withHeader('Authorization', 'Bearer ' . $token);
                    $response = $this->httpClient->sendRequest($request);
                } else {
                    return [];
                }
            }

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true) ?? [];

                $results = array_map(static fn ($item) => [
                    'street' => $item['street'] ?? '',
                    'zip' => $item['zip'] ?? '',
                    'city' => $item['city18'] ?? $item['city27'] ?? '',
                ], $data);

                $cacheItem->set($results);
                $cacheItem->expiresAfter(86400);
                $this->cache->save($cacheItem);

                return $results;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Street Autocomplete Exception', ['exception' => $e->getMessage()]);
        }

        return [];
    }

    public function autocompleteHouseNumber(string $query, string $street, string $zip, ?string $salesChannelId = null): array
    {
        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX_HOUSENR . md5($query . $street . $zip);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        try {
            $url = self::BASE_API_URL . '/house-numbers?houseNumber=' . urlencode($query) . '&street=' . urlencode($street) . '&zip=' . urlencode($zip) . '&type=DOMICILE';
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() === 401) {
                $this->invalidateTokenCache($salesChannelId);
                $token = $this->getAccessToken($salesChannelId);
                if ($token) {
                    $request = $request->withHeader('Authorization', 'Bearer ' . $token);
                    $response = $this->httpClient->sendRequest($request);
                } else {
                    return [];
                }
            }

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true) ?? [];

                $results = array_map(static fn ($item) => [
                    'houseNumber' => $item['houseNumber'] ?? '',
                    'street' => $item['street'] ?? '',
                    'zip' => $item['zip'] ?? '',
                    'city' => $item['city18'] ?? $item['city27'] ?? '',
                ], $data);

                $cacheItem->set($results);
                $cacheItem->expiresAfter(86400);
                $this->cache->save($cacheItem);

                return $results;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post House Number Autocomplete Exception', ['exception' => $e->getMessage()]);
        }

        return [];
    }
}
