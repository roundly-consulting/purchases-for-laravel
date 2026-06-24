<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

use RoundlyConsulting\Purchases\Enum\Status;

enum PaymentIntentStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case RequiresCapture = 'requires_capture';
    case Canceled = 'canceled';
    case Succeeded = 'succeeded';

    public function status(): Status
    {
        return match ($this) {
            self::Succeeded => Status::Completed,
            self::Canceled => Status::Canceled,
            self::Processing, self::RequiresCapture => Status::Processing,
            self::RequiresPaymentMethod,
            self::RequiresConfirmation,
            self::RequiresAction => Status::Pending,
        };
    }
}
