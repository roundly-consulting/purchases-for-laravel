<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Enum;

enum Status: string
{
    case New = 'new';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
