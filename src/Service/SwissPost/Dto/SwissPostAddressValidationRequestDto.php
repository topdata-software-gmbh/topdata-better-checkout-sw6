<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Service\SwissPost\Dto;

class SwissPostAddressValidationRequestDto implements \JsonSerializable
{
    public function __construct(
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $street,
        private readonly string $houseNumber,
        private readonly string $zip,
        private readonly string $city,
        private readonly string $country,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'addressee' => [
                'firstName' => $this->firstName,
                'lastName' => $this->lastName,
            ],
            'geographicLocation' => [
                'house' => [
                    'street' => $this->street,
                    'houseNumber' => $this->houseNumber,
                ],
                'zip' => [
                    'zip' => $this->zip,
                    'city' => $this->city,
                ],
            ],
            'country' => $this->country,
            'fullValidation' => true,
        ];
    }
}
