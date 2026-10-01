<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Maps a Fleetbase customer snapshot onto a QuickBooks Customer payload.
 */
class CustomerMapper
{
    /**
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    public function toQuickBooks(array $customer): array
    {
        $payload = [
            'DisplayName' => (string) ($customer['name'] ?? 'Customer'),
        ];

        if (!empty($customer['email'])) {
            $payload['PrimaryEmailAddr'] = ['Address' => $customer['email']];
        }
        if (!empty($customer['phone'])) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => $customer['phone']];
        }
        if (!empty($customer['notes'])) {
            $payload['Notes'] = $customer['notes'];
        }

        $address = $customer['address'] ?? null;
        if (is_array($address) && $this->addressHasContent($address)) {
            $payload['BillAddr'] = array_filter([
                'Line1'                  => $address['line1'] ?? null,
                'City'                   => $address['city'] ?? null,
                'CountrySubDivisionCode' => $address['state'] ?? null,
                'PostalCode'             => $address['postal_code'] ?? null,
                'Country'                => $address['country'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $party a contact, customer, company, or user snapshot
     *
     * @return array<string, mixed>
     */
    public function fromParty(array $party): array
    {
        $name = $party['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $given  = trim((string) ($party['given_name'] ?? $party['first_name'] ?? ''));
            $family = trim((string) ($party['family_name'] ?? $party['last_name'] ?? ''));
            $name   = trim($given . ' ' . $family);
        }
        if ($name === '') {
            $name = (string) ($party['email'] ?? 'Customer');
        }

        return [
            'name'    => $name,
            'email'   => $party['email'] ?? null,
            'phone'   => $party['phone'] ?? null,
            'notes'   => $party['notes'] ?? null,
            'address' => $party['address'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $address
     */
    private function addressHasContent(array $address): bool
    {
        foreach ($address as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
