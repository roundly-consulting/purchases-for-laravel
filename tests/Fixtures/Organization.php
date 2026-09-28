<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A second owner type WITHOUT the HasPurchases trait. It shares the `users` table, so an
 * Organization and a User can hold the same key — which is exactly what the owner-scoping
 * tests need: `Purchases::for()` must tell them apart by morph type, not by id alone.
 */
final class Organization extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
