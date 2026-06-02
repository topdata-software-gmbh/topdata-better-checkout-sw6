<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CustomerAddressIsolationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'onPreWriteValidate',
        ];
    }

    public function onPreWriteValidate(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if (!$command instanceof UpdateCommand || $command->getDefinition()->getClass() !== CustomerDefinition::class) {
                continue;
            }

            $payload = $command->getPayload();
            $hasBillingChange = array_key_exists('default_billing_address_id', $payload);
            $hasShippingChange = array_key_exists('default_shipping_address_id', $payload);

            if (!$hasBillingChange && !$hasShippingChange) {
                continue;
            }

            $primaryKey = $command->getPrimaryKey();
            if (!isset($primaryKey['id'])) {
                continue;
            }

            $customerIdBytes = $primaryKey['id'];

            $currentData = $this->connection->fetchAssociative(
                'SELECT default_billing_address_id, default_shipping_address_id FROM customer WHERE id = :id',
                ['id' => $customerIdBytes]
            );

            if (!$currentData) {
                continue;
            }

            $newBillingIdBytes = $hasBillingChange ? $payload['default_billing_address_id'] : $currentData['default_billing_address_id'];
            $newShippingIdBytes = $hasShippingChange ? $payload['default_shipping_address_id'] : $currentData['default_shipping_address_id'];

            if ($newBillingIdBytes !== null && $newShippingIdBytes !== null && $newBillingIdBytes === $newShippingIdBytes) {
                throw new AccessDeniedHttpException('Setting default billing address same as shipping address (or vice-versa) is strictly forbidden due to address isolation rules.');
            }
        }
    }
}
