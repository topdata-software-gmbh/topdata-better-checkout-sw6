<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

/**
 * Controller for handling Swiss Post related storefront operations.
 * Provides endpoints for address validation, autocomplete, and retrieving country IDs.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class SwissPostStorefrontController extends StorefrontController
{
    public function __construct(
        private readonly SwissPostApiService $apiService,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Validates an address using the Swiss Post API.
     * 
     * @param Request $request The incoming request containing address data
     * @param SalesChannelContext $context The current sales channel context
     * @return JsonResponse The validation result
     */
    #[Route(
        path: '/bettercheckoutsw6/swiss-post/validate',
        name: 'frontend.bettercheckoutsw6.swiss-post.validate',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function validate(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isValidationEnabled($context->getSalesChannelId())) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Swiss Post Validation is disabled.',
                'errorKey' => null,
                'details' => 'Swiss Post Validation is disabled'
            ], 403);
        }

        $addressData = $request->request->all('address');

        $countryId = $addressData['countryId'] ?? null;
        if (empty($countryId)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Country ID is required for address validation.',
                'errorKey' => null,
                'details' => 'Country ID is required for address validation'
            ], 400);
        }

        $iso = $this->connection->fetchOne(
            'SELECT iso FROM country WHERE id = UNHEX(?)',
            [$countryId]
        );
        if (!$iso) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid country ID: could not resolve country ISO.',
                'errorKey' => null,
                'details' => 'Invalid country ID: could not resolve country ISO'
            ], 400);
        }

        if (!in_array($iso, ['CH', 'LI'], true)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Address validation is only available for Switzerland and Liechtenstein.',
                'errorKey' => null,
                'details' => 'Address validation is only available for Switzerland and Liechtenstein'
            ], 400);
        }

        $zipcode = $addressData['zipcode'] ?? '';
        if ($zipcode !== '' && preg_match('/^\d{4}$/', $zipcode)) {
            $zipInt = (int)$zipcode;
            $isLiZip = ($zipInt >= 9480 && $zipInt <= 9499);

            if ($iso === 'LI' && !$isLiZip) {
                $errorKey = 'TopdataBetterCheckoutSW6.validation.invalidLiechtensteinZip';
                return new JsonResponse([
                    'success' => false,
                    'errorKey' => $errorKey,
                    'error' => $this->translator->trans($errorKey),
                    'details' => null
                ], 400);
            }

            if ($iso === 'CH' && $isLiZip) {
                $errorKey = 'TopdataBetterCheckoutSW6.validation.swissZipForLiechtenstein';
                return new JsonResponse([
                    'success' => false,
                    'errorKey' => $errorKey,
                    'error' => $this->translator->trans($errorKey),
                    'details' => null
                ], 400);
            }
        }

        $addressData['countryCode'] = $iso;

        $result = $this->apiService->validateAddress($addressData, $context->getSalesChannelId());

        if (isset($result['errorKey']) && !empty($result['errorKey'])) {
            $result['error'] = $this->translator->trans($result['errorKey']);
        }

        return new JsonResponse($result);
    }

    /**
     * Provides autocomplete suggestions for Swiss postal codes.
     * 
     * @param Request $request The incoming request containing the search query
     * @param SalesChannelContext $context The current sales channel context
     * @return JsonResponse The autocomplete results
     */
    #[Route(
        path: '/bettercheckoutsw6/swiss-post/autocomplete',
        name: 'frontend.bettercheckoutsw6.swiss-post.autocomplete',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function autocomplete(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isAutocompleteEnabled($context->getSalesChannelId())) {
            return new JsonResponse([], 403);
        }

        $query = $request->query->getString('query');
        if (mb_strlen($query) < 2) {
            return new JsonResponse([]);
        }

        $results = $this->apiService->autocompleteZip($query, $context->getSalesChannelId());

        if (!empty($results)) {
            $chId = $this->connection->fetchOne("SELECT LOWER(HEX(id)) FROM country WHERE iso = 'CH'");
            $liId = $this->connection->fetchOne("SELECT LOWER(HEX(id)) FROM country WHERE iso = 'LI'");

            $results = array_map(function (array $item) use ($chId, $liId): array {
                $zipInt = (int)($item['zip'] ?? 0);
                $item['countryId'] = ($zipInt >= 9480 && $zipInt <= 9499) ? $liId : $chId;
                return $item;
            }, $results);
        }

        return new JsonResponse($results);
    }

    /**
     * Retrieves the IDs for Switzerland and Liechtenstein countries.
     * 
     * @return JsonResponse The country IDs
     */
    #[Route(
        path: '/bettercheckoutsw6/swiss-post/country-ids',
        name: 'frontend.bettercheckoutsw6.swiss-post.country-ids',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function getCountryIds(): JsonResponse
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT LOWER(HEX(id)) FROM country WHERE iso = 'CH' OR iso = 'LI'"
        );

        return new JsonResponse($ids);
    }

    #[Route(
        path: '/bettercheckoutsw6/swiss-post/autocomplete-street',
        name: 'frontend.bettercheckoutsw6.swiss-post.autocomplete-street',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function autocompleteStreet(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isAutocompleteEnabled($context->getSalesChannelId())) {
            return new JsonResponse([], 403);
        }

        $query = $request->query->getString('query');
        $zip = $request->query->getString('zip');
        if (mb_strlen($query) < 2 || empty($zip)) {
            return new JsonResponse([]);
        }

        $results = $this->apiService->autocompleteStreet($query, $zip, $context->getSalesChannelId());

        return new JsonResponse($results);
    }

    #[Route(
        path: '/bettercheckoutsw6/swiss-post/autocomplete-house-number',
        name: 'frontend.bettercheckoutsw6.swiss-post.autocomplete-house-number',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function autocompleteHouseNumber(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isAutocompleteEnabled($context->getSalesChannelId())) {
            return new JsonResponse([], 403);
        }

        $query = $request->query->getString('query');
        $street = $request->query->getString('street');
        $zip = $request->query->getString('zip');
        if (mb_strlen($query) < 1 || empty($street) || empty($zip)) {
            return new JsonResponse([]);
        }

        $results = $this->apiService->autocompleteHouseNumber($query, $street, $zip, $context->getSalesChannelId());

        return new JsonResponse($results);
    }
}