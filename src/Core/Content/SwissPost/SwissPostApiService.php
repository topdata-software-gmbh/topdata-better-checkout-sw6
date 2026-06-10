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
    private const CACHE_KEY_PREFIX_ZIP = 'topdata_swiss_post_zip_';
    private const CACHE_KEY_PREFIX_STREET = 'topdata_swiss_post_street_';
    private const CACHE_KEY_PREFIX_HOUSENR = 'topdata_swiss_post_housenr_';
    private const LOG_FILE_AUTOCOMPLETE = '{LOGS_DIR}/swiss-post-autocomplete.jsonl';
    private const LOG_FILE_VALIDATION = '{LOGS_DIR}/swiss-post-validation.jsonl';
    private const TOKEN_CACHE_FILE = '{LOGS_DIR}/.swiss-post-oauth-token';
    private static string $autocompleteLogFile;
    private static string $validationLogFile;
    private static string $tokenCacheFile;

    private string $currentLogFile = '';


    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CacheItemPoolInterface $cache,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
        string $logsDir,
    ) {
        self::$autocompleteLogFile = str_replace('{LOGS_DIR}', $logsDir, self::LOG_FILE_AUTOCOMPLETE);
        self::$validationLogFile = str_replace('{LOGS_DIR}', $logsDir, self::LOG_FILE_VALIDATION);
        self::$tokenCacheFile = str_replace('{LOGS_DIR}', $logsDir, self::TOKEN_CACHE_FILE);
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
        $cached = $this->readTokenFromCache($salesChannelId);
        if ($cached !== null) {
            return $cached;
        }

        $clientId = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.swissPostClientId', $salesChannelId);
        $clientSecret = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.swissPostClientSecret', $salesChannelId);

        if (empty($clientId) || empty($clientSecret)) {
            $this->logToJsonl([
                'action' => 'getAccessToken',
                'error' => 'Missing credentials',
            ]);

            return null;
        }

        $data = $this->requestAccessToken($clientId, $clientSecret);
        if ($data === null) {
            return null;
        }

        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3500);

        if ($token) {
            $this->writeTokenToCache($token, $expiresIn, $salesChannelId);

            $this->logToJsonl([
                'action' => 'getAccessToken',
                'success' => true,
            ]);

            return $token;
        }

        $this->logToJsonl([
            'action' => 'getAccessToken',
            'error' => 'No access_token in response',
        ]);

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

            $this->logToJsonl([
                'action' => 'auth',
                'status' => $response->getStatusCode(),
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Swiss Post Auth Failure', ['status' => $response->getStatusCode()]);

                return null;
            }

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Auth Exception', ['exception' => $e->getMessage()]);
            $this->logToJsonl([
                'action' => 'auth',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function validateAddress(array $address, ?string $salesChannelId = null): array
    {
        $this->currentLogFile = self::$validationLogFile;

        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            $this->logToJsonl([
                'action' => 'validate',
                'direction' => 'request',
                'error' => 'No token available',
                'address' => $address,
            ]);

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

            $this->logToJsonl([
                'action' => 'validate',
                'direction' => 'request',
                'address' => $address,
                'payload' => $dto->jsonSerialize(),
            ]);

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
                    $this->logToJsonl([
                        'action' => 'validate',
                        'direction' => 'response',
                        'error' => 'Re-auth failed after 401',
                        'address' => $address,
                    ]);

                    return ['success' => false, 'error' => 'Could not re-authenticate with Swiss Post API'];
                }
            }

            $contents = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $result = json_decode($contents, true);
                $quality = $result['quality'] ?? 'UNKNOWN';

                $isUnusable = $quality === 'UNUSABLE';
                $errorMsg = $isUnusable ? ('Swiss Post could not validate this address (quality: ' . $quality . ')') : null;

                $this->logToJsonl([
                    'action' => 'validate',
                    'direction' => 'response',
                    'status' => $statusCode,
                    'quality' => $quality,
                    'originalResponse' => $result,
                ]);

                return [
                    'success' => !$isUnusable,
                    'quality' => $quality,
                    'originalResponse' => $result,
                    'errorKey' => $isUnusable ? 'better-checkout.swissPostValidationFailed' : null,
                    'details' => $isUnusable ? 'quality: UNUSABLE' : null,
                    'error' => $errorMsg,
                ];
            }

            $this->logToJsonl([
                'action' => 'validate',
                'direction' => 'response',
                'status' => $statusCode,
                'error' => 'API returned status ' . $statusCode,
                'body' => json_decode($contents, true),
                'address' => $address,
            ]);

            return [
                'success' => false,
                'quality' => null,
                'originalResponse' => null,
                'errorKey' => 'better-checkout.swissPostValidationFailed',
                'details' => 'API returned status ' . $statusCode,
                'error' => 'API returned status ' . $statusCode,
            ];
        } catch (\Throwable $e) {
            $this->logToJsonl([
                'action' => 'validate',
                'direction' => 'response',
                'error' => $e->getMessage(),
                'address' => $address,
            ]);

            return [
                'success' => false,
                'quality' => null,
                'originalResponse' => null,
                'errorKey' => 'better-checkout.swissPostValidationFailed',
                'details' => $e->getMessage(),
                'error' => $e->getMessage(),
            ];
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
        $file = $this->getTokenCacheFilePath($salesChannelId);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function logToJsonl(array $entry): void
    {
        if (empty($this->currentLogFile)) {
            return;
        }

        try {
            $dir = \dirname($this->currentLogFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $line = json_encode(array_merge(
                ['timestamp' => date('c')],
                $entry
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            file_put_contents($this->currentLogFile, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silently ignore logging failures
        }
    }

    public function autocompleteZip(string $query, ?string $salesChannelId = null): array
    {
        $this->currentLogFile = self::$autocompleteLogFile;

        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            $this->logToJsonl([
                'action' => 'autocompleteZip',
                'error' => 'No token',
                'query' => $query,
            ]);

            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX_ZIP . md5($query);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logToJsonl([
                'action' => 'autocompleteZip',
                'cache' => true,
                'query' => $query,
            ]);

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
                    $this->logToJsonl([
                        'action' => 'autocompleteZip',
                        'error' => 'Re-auth failed',
                        'query' => $query,
                    ]);

                    return [];
                }
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $data = json_decode($response->getBody()->getContents(), true) ?? [];

                $results = array_map(static fn ($item) => [
                    'zip' => $item['zip'] ?? '',
                    'city' => $item['city18'] ?? $item['city27'] ?? '',
                ], $data);

                $cacheItem->set($results);
                $cacheItem->expiresAfter(86400);
                $this->cache->save($cacheItem);

                $this->logToJsonl([
                    'action' => 'autocompleteZip',
                    'status' => $statusCode,
                    'query' => $query,
                    'results' => count($results),
                ]);

                return $results;
            }

            $this->logToJsonl([
                'action' => 'autocompleteZip',
                'status' => $statusCode,
                'query' => $query,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Autocomplete Exception', ['exception' => $e->getMessage()]);
            $this->logToJsonl([
                'action' => 'autocompleteZip',
                'error' => $e->getMessage(),
                'query' => $query,
            ]);
        }

        return [];
    }

    public function autocompleteStreet(string $query, string $zip, ?string $salesChannelId = null): array
    {
        $this->currentLogFile = self::$autocompleteLogFile;

        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            $this->logToJsonl([
                'action' => 'autocompleteStreet',
                'error' => 'No token',
                'query' => $query,
                'zip' => $zip,
            ]);

            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX_STREET . md5($query . $zip);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logToJsonl([
                'action' => 'autocompleteStreet',
                'cache' => true,
                'query' => $query,
                'zip' => $zip,
            ]);

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
                    $this->logToJsonl([
                        'action' => 'autocompleteStreet',
                        'error' => 'Re-auth failed',
                        'query' => $query,
                        'zip' => $zip,
                    ]);

                    return [];
                }
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $data = json_decode($response->getBody()->getContents(), true) ?? [];

                $results = array_map(static fn ($item) => [
                    'street' => $item['street'] ?? '',
                    'zip' => $item['zip'] ?? '',
                    'city' => $item['city18'] ?? $item['city27'] ?? '',
                ], $data);

                $cacheItem->set($results);
                $cacheItem->expiresAfter(86400);
                $this->cache->save($cacheItem);

                $this->logToJsonl([
                    'action' => 'autocompleteStreet',
                    'status' => $statusCode,
                    'query' => $query,
                    'zip' => $zip,
                    'results' => count($results),
                ]);

                return $results;
            }

            $this->logToJsonl([
                'action' => 'autocompleteStreet',
                'status' => $statusCode,
                'query' => $query,
                'zip' => $zip,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Street Autocomplete Exception', ['exception' => $e->getMessage()]);
            $this->logToJsonl([
                'action' => 'autocompleteStreet',
                'error' => $e->getMessage(),
                'query' => $query,
                'zip' => $zip,
            ]);
        }

        return [];
    }

    public function autocompleteHouseNumber(string $query, string $street, string $zip, ?string $salesChannelId = null): array
    {
        $this->currentLogFile = self::$autocompleteLogFile;

        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            $this->logToJsonl([
                'action' => 'autocompleteHouseNumber',
                'error' => 'No token',
                'query' => $query,
                'street' => $street,
                'zip' => $zip,
            ]);

            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX_HOUSENR . md5($query . $street . $zip);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logToJsonl([
                'action' => 'autocompleteHouseNumber',
                'cache' => true,
                'query' => $query,
                'street' => $street,
                'zip' => $zip,
            ]);

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
                    $this->logToJsonl([
                        'action' => 'autocompleteHouseNumber',
                        'error' => 'Re-auth failed',
                        'query' => $query,
                        'street' => $street,
                        'zip' => $zip,
                    ]);

                    return [];
                }
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
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

                $this->logToJsonl([
                    'action' => 'autocompleteHouseNumber',
                    'status' => $statusCode,
                    'query' => $query,
                    'street' => $street,
                    'zip' => $zip,
                    'results' => count($results),
                ]);

                return $results;
            }

            $this->logToJsonl([
                'action' => 'autocompleteHouseNumber',
                'status' => $statusCode,
                'query' => $query,
                'street' => $street,
                'zip' => $zip,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post House Number Autocomplete Exception', ['exception' => $e->getMessage()]);
            $this->logToJsonl([
                'action' => 'autocompleteHouseNumber',
                'error' => $e->getMessage(),
                'query' => $query,
                'street' => $street,
                'zip' => $zip,
            ]);
        }

        return [];
    }

    private function getTokenCacheFilePath(?string $salesChannelId): string
    {
        return self::$tokenCacheFile . '_' . ($salesChannelId ?? 'global');
    }

    private function readTokenFromCache(?string $salesChannelId): ?string
    {
        $file = $this->getTokenCacheFilePath($salesChannelId);

        if (!is_file($file)) {
            return null;
        }

        $data = file_get_contents($file);
        if ($data === false) {
            return null;
        }

        $entry = json_decode($data, true);
        if (!isset($entry['token'], $entry['expires_at'])) {
            return null;
        }

        if (strtotime($entry['expires_at']) <= time() + 60) {
            return null;
        }

        return $entry['token'];
    }

    private function writeTokenToCache(string $token, int $expiresIn, ?string $salesChannelId): void
    {
        $file = $this->getTokenCacheFilePath($salesChannelId);
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $entry = json_encode([
            'token' => $token,
            'expires_at' => date('c', time() + $expiresIn - 60),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $tmp = $file . '.tmp';
        file_put_contents($tmp, $entry, LOCK_EX);
        rename($tmp, $file);
    }
}
