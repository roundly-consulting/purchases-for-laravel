<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Concerns\HasPurchases;

/**
 * A ULID-keyed company: the billable owner, not a user.
 */
final class Company extends Model
{
    use HasPurchases;
    use HasUlids;

    protected $guarded = [];
}
