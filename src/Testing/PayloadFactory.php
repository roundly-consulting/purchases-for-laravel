<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Testing;

use RoundlyConsulting\Crypto\Codec\Base64Url;

/**
 * Builds raw, provider-shaped webhook payloads for tests. These mirror the JSON
 * each store posts, so the provider decoders can be exercised end to end.
 */
final class PayloadFactory
{
    /**
     * A decoded App Store Server notification payload (the claims a verified JWS
     * would yield), ready to feed a TransactionInfo/notification mapper.
     *
     * @return array<string, mixed>
     */
    public static function appleNotification(string $type = 'DID_RENEW', string $subType = 'BILLING_RECOVERY', string $transactionId = 'tx-1'): array
    {
        return [
            'notificationUUID' => 'uuid-'.$transactionId,
            'notificationType' => $type,
            'subtype' => $subType,
            'data' => [
                'transactionInfo' => [
                    'transactionId' => $transactionId,
                    'originalTransactionId' => $transactionId,
                    'productId' => 'com.example.pro',
                ],
            ],
        ];
    }

    /**
     * A Google Pub/Sub RTDN envelope wrapping the given developer notification.
     *
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    public static function googleEnvelope(array $notification): array
    {
        return [
            'message' => [
                'data' => Base64Url::encode((string) json_encode($notification)),
            ],
        ];
    }

    /**
     * A Google subscription RTDN (notificationType 1-20).
     *
     * @return array<string, mixed>
     */
    public static function googleSubscriptionNotification(int $type = 2, string $token = 'token-1'): array
    {
        return [
            'version' => '1.0',
            'packageName' => 'com.example.app',
            'eventTimeMillis' => '1700000000000',
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => $type,
                'purchaseToken' => $token,
                'subscriptionId' => 'com.example.pro',
            ],
        ];
    }

    /**
     * A Google voided-purchase RTDN (refunds).
     *
     * @return array<string, mixed>
     */
    public static function googleVoidedNotification(string $orderId = 'GPA.1234', string $token = 'token-1'): array
    {
        return [
            'version' => '1.0',
            'packageName' => 'com.example.app',
            'eventTimeMillis' => '1700000000000',
            'voidedPurchaseNotification' => [
                'purchaseToken' => $token,
                'orderId' => $orderId,
                'productType' => 1,
                'refundType' => 1,
            ],
        ];
    }

    /**
     * A Stripe webhook event envelope.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public static function stripeEvent(string $type, array $object): array
    {
        return [
            'id' => 'evt_'.uniqid(),
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }
}
