<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/purchases-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=purchases-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/purchases-for-laravel/main/art/hero.png" alt="Purchases for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/purchases-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/purchases-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/purchases-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/purchases-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/purchases-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/purchases-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=purchases-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Purchases for Laravel

One API for Apple App Store, Google Play and Stripe purchases and subscriptions. It verifies
every store's webhooks natively, records purchases, subscriptions and refunds as Eloquent
models, and fires lifecycle events, with no third-party SDKs.

## Installation

Requires PHP 8.4 (`ext-bcmath`) and Laravel 12 or 13.

```bash
composer require roundly-consulting/purchases-for-laravel
php artisan purchases:install --providers   # publishes config + migrations, appends the providers' .env keys
php artisan migrate
```

If your owner models have UUID/ULID keys, set `PURCHASES_KEY_TYPE` **before** migrating. Fill in
each provider's credentials (Stripe needs both `PURCHASES_STRIPE_SECRET` and
`PURCHASES_STRIPE_WEBHOOK_SECRET`) and set `PURCHASES_ROUTES_ENABLED=true` to receive webhooks at
`POST /purchases/webhooks/{provider}`.

## Usage

The bundled route verifies, records and fires events for you. From your own controller it is
one call:

```php
use RoundlyConsulting\Purchases\Facades\Purchases;

$model = Purchases::handle('stripe', $request);   // verify the signature, record, fire events
```

A store never says which of your users paid, so link the owner in a listener:

```php
use App\Models\User;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;

Event::listen(function (SubscriptionStarted $event): void {
    $customer = $event->result->raw()['customer'];          // the Stripe customer id

    $event->subscription->owner()->associate(User::firstWhere('stripe_id', $customer));
    $event->subscription->name = 'pro';                      // Stripe names no plan
    $event->subscription->save();
});
```

Then gate features on it:

```php
Purchases::for($user)->subscribedTo('pro');          // true
Purchases::for($user)->activeSubscription('pro');    // ?Subscription
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/purchases-for-laravel](https://roundly-consulting.com/open-source/docs/purchases-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=purchases-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=purchases-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=purchases-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
