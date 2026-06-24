<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

class ReceiptStatus extends BaseValueObject
{
    public function __construct(public readonly int $value) {}

    public function message(): string
    {
        // See: https://developer.apple.com/documentation/appstorereceipts/status?changes=latest_minor
        return '['.$this->value.']'.match ($this->value) {
            21000 => 'The request to the App Store didn’t use the HTTP POST request method.',
            21001 => 'The App Store no longer sends this status code.',
            21002 => 'The data in the receipt-data property is malformed or the service experienced a temporary issue. Try again.',
            21003 => 'The system couldn’t authenticate the receipt.',
            21004 => 'The shared secret you provided doesn’t match the shared secret on file for your account.',
            21005 => 'The receipt server was temporarily unable to provide the receipt. Try again.',
            21006 => 'This receipt is valid, but the subscription is in an expired state. When your server receives this status code, the system also decodes and returns receipt data as part of the response. This status only returns for iOS 6-style transaction receipts for auto-renewable subscriptions.',
            21007 => 'This receipt is from the test environment, but you sent it to the production environment for verification.',
            21008 => 'This receipt is from the production environment, but you sent it to the test environment for verification.',
            21009 => 'Internal data access error. Try again later.',
            21010 => 'The system can’t find the user account or the user account has been deleted.',
            default => 'Unknown receipt status',
        };
    }

    public function isValid(): bool
    {
        return $this->value === 0 || $this->value === 21006;
    }
}
