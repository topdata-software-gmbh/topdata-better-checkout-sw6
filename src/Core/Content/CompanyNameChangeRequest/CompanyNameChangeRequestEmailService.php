<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class CompanyNameChangeRequestEmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function sendAdminNotificationEmail(?CompanyNameChangeRequestEntity $changeRequest, Context $context): void
    {
        if (!$changeRequest instanceof CompanyNameChangeRequestEntity) {
            return;
        }

        $recipientEmail = $this->systemConfigService->getString('core.basicInformation.email');

        if ($recipientEmail === '') {
            $recipientEmail = $this->systemConfigService->getString('core.mailerSettings.mailerSender');
        }

        if ($recipientEmail === '') {
            return;
        }

        $customer = $changeRequest->getCustomer();
        $defaultBillingAddress = $customer?->getDefaultBillingAddress();

        $subject = 'Neuer Antrag auf Firmennameänderung / New company name change request';

        $htmlBody = $this->twig->render(
            '@TopdataBetterCheckoutSW6/email/admin-company-name-change-notification.html.twig',
            [
                'changeRequest' => $changeRequest,
                'customerNumber' => $customer?->getCustomerNumber() ?? '',
                'customerEmail' => $customer?->getEmail() ?? '',
                'phoneNumber' => $defaultBillingAddress?->getPhoneNumber() ?? '',
            ]
        );

        $email = (new Email())
            ->from($recipientEmail)
            ->to($recipientEmail)
            ->subject($subject)
            ->html($htmlBody);

        $this->mailer->send($email);
    }

    public function sendCustomerStatusEmail(CompanyNameChangeRequestEntity $changeRequest, string $status, Context $context): void
    {
        if (!$changeRequest->getCustomer() instanceof \Shopware\Core\Checkout\Customer\CustomerEntity) {
            return;
        }

        $customerEmail = $changeRequest->getCustomer()->getEmail();
        $shopName = $this->systemConfigService->getString('core.basicInformation.shopName');

        if ($status === 'approved') {
            $subject = 'Ihr Antrag auf Firmennameänderung wurde genehmigt / Your company name change request has been approved';
        } else {
            $subject = 'Ihr Antrag auf Firmennameänderung wurde abgelehnt / Your company name change request has been rejected';
        }

        $htmlBody = $this->twig->render(
            $status === 'approved'
                ? '@TopdataBetterCheckoutSW6/email/customer-company-name-approved.html.twig'
                : '@TopdataBetterCheckoutSW6/email/customer-company-name-rejected.html.twig',
            [
                'changeRequest' => $changeRequest,
                'shopName' => $shopName,
            ]
        );

        $senderEmail = $this->systemConfigService->getString('core.basicInformation.email');
        if ($senderEmail === '') {
            $senderEmail = $this->systemConfigService->getString('core.mailerSettings.mailerSender');
        }

        $email = (new Email())
            ->from($senderEmail)
            ->to($customerEmail)
            ->subject($subject)
            ->html($htmlBody);

        $this->mailer->send($email);
    }
}
