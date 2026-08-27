<?php

use App\Http\Middleware\VerifyAyosSignature;
use Illuminate\Support\Facades\Route;

/**
 * Register a throwaway endpoint behind the signature middleware.
 */
function ayosSignedRoute(): string
{
    Route::post('/_test/ayos', fn () => response()->json(['ok' => true]))
        ->middleware('ayos.signature');

    return '/_test/ayos';
}

/**
 * Turn header names into the server variables `call()` reads them from.
 *
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function ayosServer(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $server;
}

/**
 * Build the headers Ayos would sign a body with.
 *
 * @return array<string, string>
 */
function ayosHeaders(string $body, ?string $secret = null, ?int $timestamp = null): array
{
    $timestamp = (string) ($timestamp ?? now()->getTimestamp());

    return [
        VerifyAyosSignature::SIGNATURE_HEADER => VerifyAyosSignature::signature($timestamp, $body, $secret ?? 'shared-secret'),
        VerifyAyosSignature::TIMESTAMP_HEADER => $timestamp,
    ];
}

beforeEach(function () {
    config()->set('autofix.ayos.shared_secret', 'shared-secret');
});

test('a correctly signed request passes', function () {
    $url = ayosSignedRoute();
    $body = json_encode(['job_id' => 'abc', 'status' => 'done']);

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body)), $body)
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('a request with no signature header is rejected', function () {
    $url = ayosSignedRoute();
    $body = '{}';

    $headers = ayosHeaders($body);
    unset($headers[VerifyAyosSignature::SIGNATURE_HEADER]);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('a request with no timestamp header is rejected', function () {
    $url = ayosSignedRoute();
    $body = '{}';

    $headers = ayosHeaders($body);
    unset($headers[VerifyAyosSignature::TIMESTAMP_HEADER]);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('a signature made with the wrong secret is rejected', function () {
    $url = ayosSignedRoute();
    $body = '{}';

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body, secret: 'other-secret')), $body)
        ->assertUnauthorized();
});

test('a signature that does not cover the body sent is rejected', function () {
    $url = ayosSignedRoute();

    $headers = ayosHeaders('{"job_id":"abc"}');

    $this->call('POST', $url, [], [], [], ayosServer($headers), '{"job_id":"tampered"}')
        ->assertUnauthorized();
});

test('a signature without the sha256 prefix is rejected', function () {
    $url = ayosSignedRoute();
    $body = '{}';

    $headers = ayosHeaders($body);
    $headers[VerifyAyosSignature::SIGNATURE_HEADER] = hash_hmac('sha256', $headers[VerifyAyosSignature::TIMESTAMP_HEADER].'.'.$body, 'shared-secret');

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('a stale timestamp is rejected', function (int $offset) {
    $url = ayosSignedRoute();
    $body = '{}';

    $headers = ayosHeaders($body, timestamp: now()->getTimestamp() + $offset);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
})->with([
    'far in the past' => -(VerifyAyosSignature::TOLERANCE_SECONDS + 60),
    'far in the future' => VerifyAyosSignature::TOLERANCE_SECONDS + 60,
]);

test('a captured signature cannot be replayed under a fresh timestamp', function () {
    $url = ayosSignedRoute();
    $body = '{}';

    // What an attacker holds: a body and the signature that was valid for it
    // an hour ago. Only the timestamp is theirs to choose.
    $captured = ayosHeaders($body, timestamp: now()->subHour()->getTimestamp());
    $captured[VerifyAyosSignature::TIMESTAMP_HEADER] = (string) now()->getTimestamp();

    $this->call('POST', $url, [], [], [], ayosServer($captured), $body)
        ->assertUnauthorized();
});

test('a timestamp inside the window is accepted in either direction', function (int $offset) {
    $url = ayosSignedRoute();
    $body = '{}';

    $headers = ayosHeaders($body, timestamp: now()->getTimestamp() + $offset);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertOk();
})->with([
    'just inside the past' => -(VerifyAyosSignature::TOLERANCE_SECONDS - 10),
    'just inside the future' => VerifyAyosSignature::TOLERANCE_SECONDS - 10,
]);

test('a non numeric timestamp is rejected', function () {
    $url = ayosSignedRoute();
    $body = '{}';

    $headers = ayosHeaders($body);
    $headers[VerifyAyosSignature::TIMESTAMP_HEADER] = 'yesterday';

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('an unconfigured shared secret rejects everything', function () {
    config()->set('autofix.ayos.shared_secret', '');

    $url = ayosSignedRoute();
    $body = '{}';

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body, secret: '')), $body)
        ->assertUnauthorized();
});
