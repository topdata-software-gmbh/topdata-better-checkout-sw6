<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller\AdminApi;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

#[Route(defaults: ['_routeScope' => ['api']])]
class CompanyNameChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly CompanyNameChangeRequestService $changeRequestService,
    ) {
    }

    #[Route(
        path: '/api/topdata-better-checkout/company-name-change-request/{id}/approve',
        name: 'api.topdata_better_checkout.company_name_change.approve',
        methods: ['POST']
    )]
    public function approve(string $id, RequestDataBag $data, Context $context): JsonResponse
    {
        $reviewComment = $data->get('reviewComment');

        try {
            $this->changeRequestService->approveChangeRequest($id, $context, $reviewComment);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\LogicException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route(
        path: '/api/topdata-better-checkout/company-name-change-request/{id}/reject',
        name: 'api.topdata_better_checkout.company_name_change.reject',
        methods: ['POST']
    )]
    public function reject(string $id, RequestDataBag $data, Context $context): JsonResponse
    {
        $reviewComment = $data->get('reviewComment');

        try {
            $this->changeRequestService->rejectChangeRequest($id, $context, $reviewComment);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\LogicException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true]);
    }
}
