<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
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
        $deleteAddressIds = [];

        foreach ($event->getCommands() as $command) {
            if ($command instanceof DeleteCommand && $command->getEntityName() === 'customer_address') {
                $primaryKey = $command->getPrimaryKey();
                if (isset($primaryKey['id'])) {
                    $deleteAddressIds[] = $primaryKey['id'];
                }
                continue;
            }

            if (!$command instanceof UpdateCommand || $command->getEntityName() !== 'customer') {
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

            if ($hasBillingChange && $payload['default_billing_address_id'] !== $currentData['default_billing_address_id']) {
                throw new AccessDeniedHttpException('Changing the default billing address is not allowed. Please edit the existing billing address instead.');
            }

            if ($newBillingIdBytes !== null && $newShippingIdBytes !== null && $newBillingIdBytes === $newShippingIdBytes) {
                throw new AccessDeniedHttpException('Setting default billing address same as shipping address (or vice-versa) is strictly forbidden due to address isolation rules.');
            }
        }

        if ($deleteAddressIds !== []) {
            $this->assertAddressesNotReferencedAsDefault($deleteAddressIds);
        }
    }

    private function assertAddressesNotReferencedAsDefault(array $addressIdBytes): void
    {
        $placeholders = implode(', ', array_fill(0, count($addressIdBytes), '?'));

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM customer WHERE default_billing_address_id IN ({$placeholders}) OR default_shipping_address_id IN ({$placeholders})",
            array_merge($addressIdBytes, $addressIdBytes),
            array_merge(
                array_fill(0, count($addressIdBytes), ParameterType::BINARY),
                array_fill(0, count($addressIdBytes), ParameterType::BINARY),
            )
        );

        if ($count > 0) {
            throw new AccessDeniedHttpException('Cannot delete a customer address that is still set as a default billing or shipping address.');
        }
    }
}
