<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Maps a Fleetbase customer snapshot onto a QuickBooks Customer payload.
 */
class CustomerMapper
{
    /**
     * @param array<string, mixed> $customer
     * @param bool                 $pushClears include an empty note and billing address so a Fleetbase clear is sent
     *
     * @return array<string, mixed>
     */
    public function toQuickBooks(array $customer, bool $pushClears = false): array
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
        } elseif ($pushClears) {
            $payload['Notes'] = '';
        }

        $address = $customer['address'] ?? null;
        if (is_array($address) && $this->addressHasContent($address)) {
            $payload['BillAddr'] = array_filter([
                'Line1'                  => $address['line1'] ?? null,
                'Line2'                  => $address['line2'] ?? null,
                'City'                   => $address['city'] ?? null,
                'CountrySubDivisionCode' => $address['state'] ?? null,
                'PostalCode'             => $address['postal_code'] ?? null,
                'Country'                => $address['country'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
        } elseif ($pushClears) {
            $payload['BillAddr'] = [
                'Line1'                  => '',
                'Line2'                  => '',
                'City'                   => '',
                'CountrySubDivisionCode' => '',
                'PostalCode'             => '',
                'Country'                => '',
            ];
        }

        return $payload;
    }

    /**
     * Billing address stored on the customer's place, in the snapshot shape toQuickBooks sends.
     *
     * @return array{line1: ?string, line2: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string}|null
     */
    public function addressFromPlace(object $place): ?array
    {
        return $this->addressFromBillAddr([
            'Line1'                  => $place->street1 ?? null,
            'Line2'                  => $place->street2 ?? null,
            'City'                   => $place->city ?? null,
            'CountrySubDivisionCode' => $place->province ?? null,
            'PostalCode'             => $place->postal_code ?? null,
            'Country'                => $place->country ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $bill QuickBooks BillAddr
     *
     * @return array{line1: ?string, line2: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string}|null
     */
    public function addressFromBillAddr(array $bill): ?array
    {
        $address = [
            'line1'       => $this->textOrNull($bill['Line1'] ?? null),
            'line2'       => $this->textOrNull($bill['Line2'] ?? null),
            'city'        => $this->textOrNull($bill['City'] ?? null),
            'state'       => $this->textOrNull($bill['CountrySubDivisionCode'] ?? null),
            'postal_code' => $this->textOrNull($bill['PostalCode'] ?? null),
            'country'     => $this->textOrNull($bill['Country'] ?? null),
        ];

        return $this->addressHasContent($address) ? $address : null;
    }

    /**
     * Place columns for the billing address the customer form edits.
     *
     * @param array<string, mixed> $address
     *
     * @return array{street1: ?string, street2: ?string, city: ?string, province: ?string, postal_code: ?string, country: ?string}
     */
    public function placeColumns(array $address): array
    {
        return [
            'street1'     => $this->textOrNull($address['line1'] ?? null),
            'street2'     => $this->textOrNull($address['line2'] ?? null),
            'city'        => $this->textOrNull($address['city'] ?? null),
            'province'    => $this->textOrNull($address['state'] ?? null),
            'postal_code' => $this->textOrNull($address['postal_code'] ?? null),
            'country'     => $this->textOrNull($address['country'] ?? null),
        ];
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

    private function textOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
