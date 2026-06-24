<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Concerns\HasPurchases;

/**
 * A minimal owner model used by the test-suite to exercise the HasPurchases trait
 * and the package's `owner` morph relationship.
 */
final class User extends Model
{
    use HasPurchases;

    protected $table = 'users';

    protected $guarded = [];
}
