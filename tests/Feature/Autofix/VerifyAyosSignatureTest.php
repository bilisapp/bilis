<?php

use App\Http\Middleware\VerifyAyosSignature;
use App\Models\FixJob;
use App\Services\Autofix\RunKeyPair;
use Illuminate\Support\Facades\Route;

/**
 * Register a throwaway endpoint behind the signature middleware.
 */
function ayosSignedRoute(): string
{
    Route::post('/_test/ayos', function () {
        return response()->json([
            'ok' => true,
            // Proves the middleware handed the verified job downstream, so the
            // controller never has to look it up from an unverified body.
            'job' => request()->attributes->get(VerifyAyosSignature::JOB_ATTRIBUTE)?->uuid,
        ]);
    })->middleware('ayos.signature');

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
 * Sign a body the way a run would.
 *
 * @return array<string, string>
 */
function ayosHeaders(string $body, ?string $signingKey = null, ?int $timestamp = null): array
{
    $timestamp = (string) ($timestamp ?? now()->getTimestamp());
    $seed = base64_decode($signingKey ?? test()->keys->signingKey, true);
    $secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));

    return [
        VerifyAyosSignature::SIGNATURE_HEADER => VerifyAyosSignature::SIGNATURE_PREFIX.base64_encode(
            sodium_crypto_sign_detached($timestamp.'.'.$body, $secretKey),
        ),
        VerifyAyosSignature::TIMESTAMP_HEADER => $timestamp,
    ];
}

/**
 * A body naming the job under test. The job id is what selects the key.
 */
function ayosBody(array $extra = []): string
{
    return (string) json_encode(['job_id' => test()->job->uuid, ...$extra]);
}

beforeEach(function () {
    $this->keys = RunKeyPair::mint();
    $this->job = FixJob::factory()->create(['ayos_public_key' => $this->keys->publicKey]);
});

test('a correctly signed request passes, and carries the job it was signed for', function () {
    $url = ayosSignedRoute();
    $body = ayosBody(['status' => 'done']);

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body)), $body)
        ->assertOk()
        ->assertJson(['ok' => true, 'job' => $this->job->uuid]);
});

test('a request with no signature header is rejected', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body);
    unset($headers[VerifyAyosSignature::SIGNATURE_HEADER]);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('a request with no timestamp header is rejected', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body);
    unset($headers[VerifyAyosSignature::TIMESTAMP_HEADER]);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

/*
 * The heart of the per-run scheme. A key that is perfectly valid — for some
 * other job — must not authenticate this one. Under the old shared secret every
 * key was every job's key, and this test could not have been written.
 */
test('a signature made with another run key is rejected', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $other = RunKeyPair::mint();

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body, signingKey: $other->signingKey)), $body)
        ->assertUnauthorized();
});

test('a signature for a job that has no key yet is rejected', function () {
    $this->job->forceFill(['ayos_public_key' => null])->save();

    $url = ayosSignedRoute();
    $body = ayosBody();

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body)), $body)
        ->assertUnauthorized();
});

test('a request naming a job that does not exist is rejected', function () {
    $url = ayosSignedRoute();
    $body = (string) json_encode(['job_id' => '00000000-0000-4000-8000-000000000000']);

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body)), $body)
        ->assertUnauthorized();
});

test('a request with no job id is rejected', function () {
    $url = ayosSignedRoute();
    $body = '{}';

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body)), $body)
        ->assertUnauthorized();
});

test('a signature that does not cover the body sent is rejected', function () {
    $url = ayosSignedRoute();

    $headers = ayosHeaders(ayosBody(['status' => 'done']));

    $this->call('POST', $url, [], [], [], ayosServer($headers), ayosBody(['status' => 'failed']))
        ->assertUnauthorized();
});

/*
 * `sha256=` named the retired shared-secret HMAC. Renaming the prefix means an
 * old caller fails closed and legibly rather than having its digest compared
 * against a key of an entirely different kind.
 */
test('a signature carrying the retired hmac prefix is rejected', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body);
    $headers[VerifyAyosSignature::SIGNATURE_HEADER] = 'sha256='.hash_hmac(
        'sha256',
        $headers[VerifyAyosSignature::TIMESTAMP_HEADER].'.'.$body,
        'shared-secret',
    );

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('an unprefixed signature is rejected', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body);
    $headers[VerifyAyosSignature::SIGNATURE_HEADER] = substr(
        $headers[VerifyAyosSignature::SIGNATURE_HEADER],
        strlen(VerifyAyosSignature::SIGNATURE_PREFIX),
    );

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('a signature of the wrong length is rejected', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body);
    $headers[VerifyAyosSignature::SIGNATURE_HEADER] = VerifyAyosSignature::SIGNATURE_PREFIX.base64_encode('short');

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('a stale timestamp is rejected', function (int $offset) {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body, timestamp: now()->getTimestamp() + $offset);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
})->with([
    'far in the past' => -(VerifyAyosSignature::TOLERANCE_SECONDS + 60),
    'far in the future' => VerifyAyosSignature::TOLERANCE_SECONDS + 60,
]);

test('a captured signature cannot be replayed under a fresh timestamp', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    // What an attacker holds: a body and the signature that was valid for it an
    // hour ago. Only the timestamp is theirs to choose — and it is inside the
    // signed string, so choosing it invalidates the signature.
    $captured = ayosHeaders($body, timestamp: now()->subHour()->getTimestamp());
    $captured[VerifyAyosSignature::TIMESTAMP_HEADER] = (string) now()->getTimestamp();

    $this->call('POST', $url, [], [], [], ayosServer($captured), $body)
        ->assertUnauthorized();
});

test('a timestamp inside the window is accepted in either direction', function (int $offset) {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body, timestamp: now()->getTimestamp() + $offset);

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertOk();
})->with([
    'just inside the past' => -(VerifyAyosSignature::TOLERANCE_SECONDS - 10),
    'just inside the future' => VerifyAyosSignature::TOLERANCE_SECONDS - 10,
]);

test('a non numeric timestamp is rejected', function () {
    $url = ayosSignedRoute();
    $body = ayosBody();

    $headers = ayosHeaders($body);
    $headers[VerifyAyosSignature::TIMESTAMP_HEADER] = 'yesterday';

    $this->call('POST', $url, [], [], [], ayosServer($headers), $body)
        ->assertUnauthorized();
});

test('a body that is not json is rejected', function () {
    $url = ayosSignedRoute();
    $body = 'not json at all';

    $this->call('POST', $url, [], [], [], ayosServer(ayosHeaders($body)), $body)
        ->assertUnauthorized();
});
