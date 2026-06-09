<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Message;

class ValidateAddressMessage
{
    public function __construct(
        private readonly string $addressId,
        private readonly ?string $salesChannelId = null,
    ) {
    }

    public function getAddressId(): string
    {
        return $this->addressId;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }
}
