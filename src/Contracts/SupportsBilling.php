<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Contracts;

/**
 * The capability marker for outbound billing: a provider implementing it can create and
 * change customers, subscriptions and usage at its store, through `Purchases::stripe()`.
 * Only the Stripe provider does — Apple and Google sell through their own storefronts.
 * Check with `Purchases::canBill($id)`.
 */
interface SupportsBilling {}
