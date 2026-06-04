<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller\AdminApi;

use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

#[Route(defaults: ['_routeScope' => ['api']])]
class SwissPostAdminController extends AbstractController
{
    public function __construct(
        private readonly SwissPostApiService $apiService
    ) {
    }

    #[Route(
        path: '/api/topdata-better-checkout/swiss-post/test-credentials',
        name: 'api.topdata_better_checkout.swiss_post.test_credentials',
        methods: ['POST']
    )]
    public function testCredentials(RequestDataBag $data): JsonResponse
    {
        $clientId = $data->get('clientId');
        $clientSecret = $data->get('clientSecret');

        $result = $this->apiService->testCredentials($clientId, $clientSecret);

        return new JsonResponse($result, $result['success'] ? 200 : 400);
    }
}
