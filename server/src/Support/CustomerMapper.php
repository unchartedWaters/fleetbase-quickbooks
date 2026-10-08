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

        $email = trim((string) ($customer['email'] ?? ''));
        if ($email !== '') {
            $payload['PrimaryEmailAddr'] = ['Address' => $email];
        } elseif ($pushClears === true) {
            $payload['PrimaryEmailAddr'] = ['Address' => ''];
        }
        $phone = trim((string) ($customer['phone'] ?? ''));
        if ($phone !== '') {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => $phone];
        } elseif ($pushClears === true) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => ''];
        }
        if (empty($customer['notes']) === false) {
            $payload['Notes'] = $customer['notes'];
        } elseif ($pushClears === true) {
            $payload['Notes'] = '';
        }

        $address = $customer['address'] ?? null;
        if (is_array($address) === true && $this->addressHasContent($address) === true) {
            $payload['BillAddr'] = $this->billAddr($address, $pushClears);
        } elseif ($pushClears === true) {
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

        return $this->addressHasContent($address) === true ? $address : null;
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
        if (is_string($name) === false || $name === '') {
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
     * A Fleetbase clear of one line keeps the other lines and sends the blank as an empty string.
     * Omitting the key leaves the old QuickBooks value in place.
     *
     * @param array<string, mixed> $address
     *
     * @return array<string, string>
     */
    private function billAddr(array $address, bool $pushClears): array
    {
        $fields = [
            'Line1'                  => $address['line1'] ?? null,
            'Line2'                  => $address['line2'] ?? null,
            'City'                   => $address['city'] ?? null,
            'CountrySubDivisionCode' => $address['state'] ?? null,
            'PostalCode'             => $address['postal_code'] ?? null,
            'Country'                => $address['country'] ?? null,
        ];
        if ($pushClears === true) {
            $cleared = [];
            foreach ($fields as $key => $value) {
                $cleared[$key] = is_scalar($value) === true ? trim((string) $value) : '';
            }

            return $cleared;
        }

        $kept = [];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $kept[$key] = is_scalar($value) === true ? (string) $value : '';
            }
        }

        return $kept;
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
        if (is_scalar($value) === false) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
