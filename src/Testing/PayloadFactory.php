<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Testing;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Crypto\Codec\Base64Url;

/**
 * Builds raw, provider-shaped webhook payloads for tests. These mirror the JSON
 * each store posts, so the provider decoders can be exercised end to end.
 */
final class PayloadFactory
{
    /**
     * A decoded App Store Server notification payload — the claims a verified JWS
     * yields once JwsManager has decoded the nested signed transaction into
     * `data.transactionInfo` — ready to feed ServerNotificationDecodedPayload::fromRaw()
     * or a TransactionInfo mapper. Pass a null subtype for the types Apple sends
     * without one (REFUND, REVOKE, TEST, a plain DID_RENEW).
     *
     * @return array<string, mixed>
     */
    public static function appleNotification(string $type = 'DID_RENEW', ?string $subType = 'BILLING_RECOVERY', string $transactionId = 'tx-1'): array
    {
        $payload = [
            'notificationUUID' => 'uuid-'.$transactionId,
            'notificationType' => $type,
            'signedDate' => Carbon::now()->getTimestampMs(),
        ];

        if ($subType !== null) {
            $payload['subtype'] = $subType;
        }

        return $payload + [
            'version' => '2.0',
            'data' => [
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Sandbox',
                'transactionInfo' => [
                    'bundleId' => 'com.example.app',
                    'environment' => 'Sandbox',
                    'transactionId' => $transactionId,
                    'originalTransactionId' => $transactionId,
                    'productId' => 'com.example.pro',
                    'type' => 'Auto-Renewable Subscription',
                    'inAppOwnershipType' => 'PURCHASED',
                    'price' => 9990,
                    'currency' => 'USD',
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
            'created' => Carbon::now()->getTimestamp(),
            'data' => ['object' => $object],
        ];
    }
}
