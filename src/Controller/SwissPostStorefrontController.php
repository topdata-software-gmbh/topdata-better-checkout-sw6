<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly Connection $connection
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
        methods: ['POST']
    )]
    public function validate(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['success' => false, 'error' => 'Swiss Post Validation is disabled.'], 403);
        }

        $addressData = $request->request->all('address');
        $result = $this->apiService->validateAddress($addressData, $context->getSalesChannelId());

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
        methods: ['GET']
    )]
    public function autocomplete(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isEnabled($context->getSalesChannelId())) {
            return new JsonResponse([], 403);
        }

        $query = $request->query->getString('query');
        if (mb_strlen($query) < 2) {
            return new JsonResponse([]);
        }

        $results = $this->apiService->autocompleteZip($query, $context->getSalesChannelId());

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
        methods: ['GET']
    )]
    public function getCountryIds(): JsonResponse
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT LOWER(HEX(id)) FROM country WHERE iso = 'CH' OR iso = 'LI'"
        );

        return new JsonResponse($ids);
    }
}