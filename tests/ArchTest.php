<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Exceptions\Exception as PurchasesException;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;
use RoundlyConsulting\Purchases\Providers\Google\Auth\AccessTokenFactory;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Google\GoogleClient;
use RoundlyConsulting\Purchases\Providers\Google\GoogleMoney;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Providers\Stripe\StripeAmount;
use RoundlyConsulting\Purchases\Providers\Stripe\StripeClient;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * `noDebuggingLeftovers` replaces the local `['dd','dump','ray']` ban. The replacement is
 * not cosmetic: Pest's arch layer only sees a dependency whose symbol EXISTS, and `ray` is
 * not in the dependency graph by policy — so `ray` was filtered out before the ban ran and
 * **could never fail**. The one debug tool you would realistically leave behind was the
 * exact one this could not catch. The preset reads source tokens, which do not care
 * whether the function exists.
 */
ArchPresets::noDebuggingLeftovers();

ArchPresets::strictTypes('RoundlyConsulting\Purchases');

/**
 * The deliberate tension, run as a pair. `finalByDefault` wants every class closed;
 * `swappableModelsAreNotFinal` forbids `final` on a config-swappable model — a PHP fatal
 * the moment a host uses the seam the config documents, shipped 7x across the fleet under
 * green "everything is final" arch tests. This package had NEITHER rule.
 *
 * ## The inventory was a holding position. This is the decision.
 *
 * The adoption row found **26** non-final classes and — correctly — declined to decide a
 * src-wide finality refactor as a side effect of installing test machinery. It shipped a
 * rot-checked inventory instead and reported it. That decision has now been made class by
 * class, on evidence: **12 closed, 14 remain open.**
 *
 * The 12 that closed had nothing extending them, no config key naming them, and no
 * documented seam — the six exception leaves, Resolver, DataSet, ReceiptStatus,
 * GoogleClient, StripeClient and AppStoreServerApi. What remains is not a backlog; each
 * entry below is a seam a host is genuinely invited through, or a PHP requirement.
 *
 * An exemption here is **expensive** — it is class-scoped, so it blinds that class to every
 * other rule this preset carries. That is the reason the bar for staying is evidence and
 * not convenience, and the reason "our own tests find it easier to stub" was not on its own
 * enough to keep a class open (Resolver was mocked with Mockery and closed anyway; the test
 * now drives the real class through the real config seam, which is the better test).
 *
 * The list stays **rot-proof**: an entry that stops silencing anything FAILS. So it cannot
 * quietly decay, and — the real point — **every NEW class must be final or be argued onto
 * this list**.
 *
 * Declared as a constant rather than inline because the shadow guard further down consumes
 * the SAME list. Two copies of this would eventually disagree, and the guard's whole job is
 * to know exactly what these exemptions reach.
 *
 * Grouped by why:
 *
 * @var list<class-string>
 */
const FINALITY_EXEMPTIONS = [
    // 1. The six documented model seams. `purchases.models.*` invites a host to
    //    subclass each one ("swap any of these for your own subclass" —
    //    docs/technical/configuration.md); `final` here is the 7x-shipped fatal.
    //    Pinned by swappableModelsAreNotFinal below, which is the counter-weight.
    Purchase::class,
    PurchaseItem::class,
    PurchaseRefund::class,
    PurchaseNotification::class,
    Subscription::class,
    SubscriptionItem::class,

    // 2. The exception BASE — and only the base. Six leaves extend it, so `final` here
    //    is not a policy call but a PHP fatal; it is also the documented catch-all
    //    ("All package exceptions extend ... Exceptions\Exception" — README). Its
    //    constructor is already `final`, so the base is closed where it counts. The
    //    seven-entry exception block is now one: every leaf is final.
    PurchasesException::class,

    // 3. The manager. `PurchasesFake extends PurchasesManager` ships IN THIS PACKAGE
    //    (src/Testing/PurchasesFake.php) as the documented test double, and the facade
    //    convention requires the fake to be a subtype of the root, so `final` is a fatal
    //    in our own source.
    PurchasesManager::class,

    // 4. Provider drivers, named as class strings in the `purchases.providers` config.
    //    A host swapping `Apple::class` for its own `extends Apple` is editing a config
    //    list we ship — structurally the SAME move as a model swap, and so the same
    //    fatal. (Adding a brand-new provider goes through the abstract BaseProvider,
    //    which the preset excludes automatically.) git-for-laravel keeps Github/Gitlab/
    //    Bitbucket open for exactly this reason.
    Apple::class,
    Google::class,
    Stripe::class,

    // 5. The Apple trust + token boundary: override points a host cannot test without.
    //    JwsVerifier exposes a `protected fingerprints()` precisely so the trust anchors
    //    can be replaced — this suite's own `appleVerifierPinnedTo()` does exactly that
    //    to exercise the positive trust path against a throwaway CA, because nobody can
    //    sign with Apple's key. JwsManager and AccessTokenFactory are the same shape:
    //    both are subclassed by this suite to stub JWS decoding and Google token minting,
    //    and a host testing its own purchase flow hits that identical wall. This is what
    //    separates them from the transport clients that just closed (GoogleClient,
    //    StripeClient, AppStoreServerApi): those are stubbed with `Http::fake()` and never
    //    needed subclassing. Here the thing that must be faked is a signature, not a
    //    response — there is no `Http::fake()` for "pretend Apple signed this".
    JwsVerifier::class,
    JwsManager::class,
    AccessTokenFactory::class,
];

ArchPresets::finalByDefault('RoundlyConsulting\Purchases', FINALITY_EXEMPTIONS);

/**
 * Model traits and model methods reach behaviour through the manager, never an action —
 * so the facade's fake sees every call. `HasPurchases` delegates to `Purchases::for()`.
 */
ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Purchases');

/**
 * ## The exemptions above reach FURTHER than they read. This closes that hole.
 *
 * Pest matches arch exemptions by **string prefix**, not by class identity —
 * `pest-plugin-arch/src/Blueprint.php:103` is literally
 * `if (str_starts_with($object->name, $exclude))`. So exempting a class silently exempts
 * every class whose FQCN starts with the same characters:
 *
 *   `...\Stripe\Stripe`  also silences  `...\Stripe\StripeClient`
 *   `...\Google\Google`  also silences  `...\Google\GoogleClient`
 *
 * (and, since the money integration, `...\Stripe\StripeAmount` and `...\Google\GoogleMoney`.
 * The manager's old short name `...\Purchases` also shadowed `...\PurchasesServiceProvider`;
 * renamed to `PurchasesManager`, it shadows nothing.)
 *
 * This is not theoretical and it is not cosmetic: it was found by biting the preset.
 * `StripeClient` was un-finalled on purpose and `finalByDefault` stayed **GREEN**, because
 * the neighbouring `Stripe::class` exemption was covering for it. Four classes sit in that
 * shadow, and two of them (`GoogleClient`, `StripeClient`) are ones this package just
 * deliberately closed — so the exact classes we decided to close were the ones the preset
 * could not have policed. A `final` deleted from any of them would have gone green.
 *
 * The shared preset cannot be fixed from here (it is testing-for-laravel's, and Pest's
 * prefix semantics are Pest's), so the gap is closed locally and by EXACT match. This does
 * not re-implement `finalByDefault`; it covers only the set the preset provably cannot see,
 * derived rather than hand-listed so a new shadowed class is caught the day it appears.
 *
 * Pinned with a count: if the shadow set ever measures empty this FAILS rather than passing
 * over nothing — the vacuous green these checks exist to kill.
 */
it('closes the finality hole Pest\'s prefix-matched exemptions open', function (): void {
    $shadowed = [];

    foreach (concreteSourceClasses() as $class) {
        // Exactly exempt — argued for above, and genuinely open. Not our business here.
        if (in_array($class, FINALITY_EXEMPTIONS, true)) {
            continue;
        }

        foreach (FINALITY_EXEMPTIONS as $exemption) {
            if (str_starts_with($class, $exemption)) {
                $shadowed[$class] = (new ReflectionClass($class))->isFinal();

                break;
            }
        }
    }

    // The shadow set is real and known. If this count moves, the reach of an exemption
    // moved with it, and that is a review event — in either direction.
    expect($shadowed)->toHaveCount(4)
        ->and(array_keys($shadowed))->toEqualCanonicalizing([
            GoogleClient::class,
            GoogleMoney::class,
            StripeAmount::class,
            StripeClient::class,
        ]);

    // Every one of them must be final, since the preset's word on them is worthless.
    expect(array_keys(array_filter($shadowed, fn (bool $final): bool => ! $final)))->toBe([]);
});

/**
 * Every concrete, instantiable class in src — the population `finalByDefault` speaks about.
 * Abstracts/enums/interfaces are excluded for the same reason the preset excludes them:
 * `abstract final` is a PHP fatal, so they can never satisfy the ban.
 *
 * @return list<class-string>
 */
function concreteSourceClasses(): array
{
    $classes = [];

    foreach (packageSourceFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
            continue;
        }

        $class = $namespace[1].'\\'.$file->getBasename('.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isEnum()) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/**
 * Six swappable models, not the two the row spec claimed. Each is pinned non-final AND
 * pinned to default to the packaged class, so the seam cannot rot in either direction.
 *
 * `purchases.providers` (Apple/Google/Stripe) is deliberately NOT here: those are provider
 * DRIVERS, not Eloquent models behind a `*_model`-shaped key, so neither this preset nor
 * `toHonourModelSwap` has anything to say about them — the same miscount that inflated
 * metrics' "4 swaps".
 */
ArchPresets::swappableModelsAreNotFinal([
    Purchase::class => 'purchases.models.purchase',
    PurchaseItem::class => 'purchases.models.purchase-item',
    PurchaseRefund::class => 'purchases.models.purchase-refund',
    PurchaseNotification::class => 'purchases.models.purchase-notification',
    Subscription::class => 'purchases.models.subscription',
    SubscriptionItem::class => 'purchases.models.subscription-item',
]);

/**
 * **The keys MUST be declared.** Undeclared, the stray-literal half infers swap keys from
 * key *shape* — `model`, `models`, or `*_model`. This package's keys are
 * `purchases.models.purchase-item` and friends: none is shaped like that, so the preset
 * would have inferred NOTHING and gone green while covering none of the six seams —
 * authoritative-looking and completely inert (the alerts trap). A declared key that
 * matches no literal fails rather than pretending to cover something.
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support', [
    'purchases.models.purchase',
    'purchases.models.purchase-item',
    'purchases.models.purchase-refund',
    'purchases.models.purchase-notification',
    'purchases.models.subscription',
    'purchases.models.subscription-item',
]);

/**
 * The morph-key seam, guarded. Purchases' `owner` column on both `purchases` and
 * `subscriptions` migrated off raw `$table->morphs()` onto `morphKey('owner',
 * KeyType::fromConfig(...))` so a uuid/ulid host can flip its whole graph coherently — a
 * hardcoded bigint id breaks those hosts on Postgres, and SQLite type affinity hides it.
 * This pin reds if a future migration reintroduces a raw morph and bypasses the seam.
 */
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../database/migrations');

/**
 * The Dependency Policy as a test — this package had no such rule. No `alsoAllow`: its
 * `require` ships only php/illuminate/roundly, and the workflow installs test tooling with
 * `--dev`. If this goes red the shipped graph is wrong; never widen the allow-list.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

/*
 * Crypto primitives are crypto-for-laravel's, not ours: HMAC, constant-time
 * compares, RSA/ECDSA sign+verify, key loading, X.509 parsing/fingerprints/chain
 * linkage, base64(url), and CSPRNG bytes all route through RoundlyConsulting\Crypto.
 *
 * The openssl_x509_* family used to be exempt, because Apple's chain trust lived
 * here on top of raw OpenSSL. It no longer does: crypto's X509 module supplies the
 * mathematics and the ban is now package-wide. The *trust* ruling — Apple's pinned
 * WWDR/G3 anchors, the chain length, and the validity policy — still lives here, in
 * Apple\Jws\JwsVerifier, and always will.
 */
/**
 * Bespoke, KEPT and narrowed — the shared preset does not reach the `openssl_x509_*` /
 * `openssl_pkey_export` family this package specifically had to be talked out of, so both
 * run side by side. `ArchPresets::noLocalCryptoPrimitives` (above the fold in spirit) is
 * not called separately: this list plus the openssl sweep below is a strict superset of it
 * for every primitive purchases can reach, and running both would double-report.
 *
 * `hash_equals` is REMOVED from the list. It IS PHP's constant-time compare, not a
 * re-implementation of one; it has no algorithm or key to centralise; and banning it pushes
 * callers toward `$a === $b` — a timing leak in exactly the code that compares a webhook
 * signature. The fleet removed it from the shared preset on 2026-07-17, and this package
 * was one of six still banning it in a local list that never read the shared one.
 */
arch('no crypto primitive is re-implemented locally')
    ->expect('RoundlyConsulting\Purchases')
    ->not->toUse([
        'hash_hmac',
        'openssl_sign',
        'openssl_verify',
        'openssl_pkey_new',
        'openssl_pkey_get_private',
        'openssl_pkey_get_public',
        'openssl_pkey_get_details',
        'openssl_pkey_export',
        'openssl_x509_read',
        'openssl_x509_parse',
        'openssl_x509_export',
        'openssl_x509_fingerprint',
        'openssl_x509_verify',
        'random_bytes',
        'base64_encode',
        'base64_decode',
    ]);

it('calls no openssl function anywhere in src', function (): void {
    $offenders = [];

    foreach (packageSourceFiles() as $file) {
        if (preg_match('/\bopenssl_[a-z0-9_]+\s*\(/i', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getBasename();
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * Builds on crypto-for-laravel rather than a third-party crypto vendor. A source-token scan,
 * not `->not->toUse()`: Pest resolves a name only through an installed PSR-4 root at or above
 * it, so a bare vendor prefix missed its sibling packages (`ParagonIE\ConstantTime\Base64`
 * stayed green under `ParagonIE`; web-token's split packages under `Jose\Component\Core\`
 * slipped past `Jose\Component`) and an uninstalled vendor matched nothing. None of these
 * vendors is in the graph today; this bites the day one arrives, transitively or otherwise.
 */
ArchPresets::noVendorNamespace([
    'Firebase\JWT',
    'Lcobucci\JWT',
    'Jose\Component',
    'ParagonIE',
    'phpseclib3',
    'phpseclib4',
], __DIR__.'/../src');

it('does not import a crypto class marked @internal', function (): void {
    $internal = [];

    foreach (cryptoSourceFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (! str_contains($contents, '@internal') || preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
            continue;
        }

        $internal[] = $namespace[1].'\\'.$file->getBasename('.php');
    }

    // Assert the scan actually covers crypto's OpenSSL gateways by name, so the
    // guard cannot silently stop covering them if a docblock ever moves.
    expect($internal)
        ->toContain('RoundlyConsulting\Crypto\X509\OpenSslX509')
        ->toContain('RoundlyConsulting\Crypto\Signature\OpenSsl');

    $offenders = [];

    foreach (packageSourceFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        foreach ($internal as $class) {
            if (str_contains($contents, 'use '.$class.';')) {
                $offenders[] = $file->getBasename().' → '.$class;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('does not import a money class marked @internal', function (): void {
    $internal = [];

    foreach (phpFilesIn(__DIR__.'/../vendor/roundly-consulting/money-for-laravel/src') as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        // Class-level only: money also tags single methods of public classes
        // (`Ratio::fromIntegers()`), which does not make the class off-limits.
        if (preg_match('/@internal\b[^\n]*\n(?:\s*\*[^\n]*\n)*\s*\*\/\s*\n(?:#\[[^\n]*\]\s*\n)*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s/', $contents) !== 1
            || preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
            continue;
        }

        $internal[] = $namespace[1].'\\'.$file->getBasename('.php');
    }

    // Pinned by name so the scan cannot silently cover nothing: the cast
    // implementation and the bcmath gateway are money's internals.
    expect($internal)
        ->toContain('RoundlyConsulting\Money\Casts\MoneyCast')
        ->toContain('RoundlyConsulting\Money\Math\Calculator')
        ->not->toContain('RoundlyConsulting\Money\Ratio');

    $offenders = [];

    foreach (packageSourceFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        foreach ($internal as $class) {
            if (str_contains($contents, 'use '.$class.';')) {
                $offenders[] = $file->getBasename().' → '.$class;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('declares every helper shared across test files in tests/Pest.php', function (): void {
    // `--parallel` workers load only the files they run plus tests/Pest.php, so a helper
    // declared in one test file and called from another is order-dependent.
    $sources = [];

    foreach (phpFilesIn(__DIR__) as $file) {
        $path = (string) $file->getRealPath();
        $sources[$path] = (string) file_get_contents($path);
    }

    $pest = (string) realpath(__DIR__.'/Pest.php');
    $offenders = [];

    foreach ($sources as $declaringFile => $source) {
        if ($declaringFile === $pest || preg_match_all('/^function (\w+)\(/m', $source, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $helper) {
            foreach ($sources as $callingFile => $callingSource) {
                if ($callingFile !== $declaringFile && preg_match('/(?<![\w$>:])'.$helper.'\(/', $callingSource) === 1) {
                    $offenders[] = sprintf('%s() from %s used in %s', $helper, basename($declaringFile), basename($callingFile));
                }
            }
        }
    }

    expect($sources)->toHaveKey($pest)
        ->and($offenders)->toBe([]);
});

/**
 * @return list<SplFileInfo>
 */
function cryptoSourceFiles(): array
{
    return phpFilesIn(__DIR__.'/../vendor/roundly-consulting/crypto-for-laravel/src');
}

/**
 * @return list<SplFileInfo>
 */
function packageSourceFiles(): array
{
    return phpFilesIn(__DIR__.'/../src');
}

/**
 * @return list<SplFileInfo>
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file;
        }
    }

    return $files;
}
