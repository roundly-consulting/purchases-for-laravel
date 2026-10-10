<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\StoreApi;

function storeAnswered(int $status): RequestException
{
    return new RequestException(new Response(new Psr7Response($status)));
}

it('names the 4xx answers that say nothing about the client\'s input', function (): void {
    expect(StoreApi::NOT_ABOUT_THE_INPUT)->toBe([401, 403, 429]);
});

it('encodes every segment of a store api path', function (): void {
    expect(StoreApi::segments('Malformed id.', 'checkout', 'cs_1?expand[]=x#/../y', 'tok en'))
        ->toBe('checkout/cs_1%3Fexpand%5B%5D%3Dx%23%2F..%2Fy/tok%20en');
});

it('keeps an id that only looks like a dot segment', function (string $id): void {
    expect(StoreApi::segments('Malformed id.', $id))->toBe(rawurlencode($id));
})->with(['three dots' => ['...'], 'a leading dot' => ['.x'], 'a trailing dot' => ['x.'], 'a space and a dot' => [' .']]);

it('refuses a segment the http client would resolve away', function (string $segment): void {
    expect(fn () => StoreApi::segments('Malformed id.', 'tokens', $segment))
        ->toThrow(VerificationException::class, 'Malformed id.');
})->with(['empty' => [''], 'a dot' => ['.'], 'two dots' => ['..']]);

it('returns what a lookup returns', function (): void {
    expect(StoreApi::lookUp(fn (): string => 'found', 'Rejected.'))->toBe('found');
});

it('turns a 4xx about the input into a verification exception, keeping the answer', function (int $status): void {
    $answer = storeAnswered($status);

    expect(fn () => StoreApi::lookUp(fn () => throw $answer, 'Rejected.'))
        ->toThrow(function (VerificationException $e) use ($answer): void {
            expect($e->getMessage())->toBe('Rejected.')
                ->and($e->getPrevious())->toBe($answer);
        });
})->with([400, 404, 409, 410, 422]);

it('leaves an answer that is not about the input as it is', function (int $status): void {
    $answer = storeAnswered($status);

    expect(fn () => StoreApi::lookUp(fn () => throw $answer, 'Rejected.'))
        ->toThrow(fn (RequestException $e) => expect($e)->toBe($answer));
})->with([401, 403, 429, 500, 502, 503]);

it('leaves a 4xx the store says is about the host as it is', function (int $status): void {
    $answer = storeAnswered($status);
    $asked = [];
    $aboutTheHost = function (Response $response) use (&$asked): bool {
        $asked[] = $response;

        return true;
    };

    expect(fn () => StoreApi::lookUp(fn () => throw $answer, 'Rejected.', $aboutTheHost))
        ->toThrow(fn (RequestException $e) => expect($e)->toBe($answer));

    expect($asked)->toBe([$answer->response]);
})->with([400, 404, 410]);

it('still refuses a 4xx the store does not say is about the host', function (): void {
    $answer = storeAnswered(404);

    expect(fn () => StoreApi::lookUp(fn () => throw $answer, 'Rejected.', fn (Response $response): bool => false))
        ->toThrow(function (VerificationException $e) use ($answer): void {
            expect($e->getMessage())->toBe('Rejected.')
                ->and($e->getPrevious())->toBe($answer);
        });
});

it('asks the store nothing about an answer it never blames on the input', function (int $status): void {
    $answer = storeAnswered($status);
    $asked = false;
    $aboutTheHost = function () use (&$asked): bool {
        $asked = true;

        return false;
    };

    expect(fn () => StoreApi::lookUp(fn () => throw $answer, 'Rejected.', $aboutTheHost))
        ->toThrow(fn (RequestException $e) => expect($e)->toBe($answer));

    expect($asked)->toBeFalse();
})->with([401, 403, 429, 500]);

it('leaves any other failure as it is', function (): void {
    expect(fn () => StoreApi::lookUp(fn () => throw new RuntimeException('boom'), 'Rejected.'))
        ->toThrow(RuntimeException::class, 'boom');
});
