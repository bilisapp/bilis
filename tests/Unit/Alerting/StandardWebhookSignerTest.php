<?php

use App\Services\Alerting\Webhooks\StandardWebhookSigner;

/*
 * The test vector from the official Standard Webhooks PHP library,
 * standard-webhooks/standard-webhooks libraries/php/tests/WebhookTest.php
 * (testSignFunctionWorks); the other official libraries share it.
 */
const SW_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const SW_MSG_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
const SW_TIMESTAMP = 1614265330;
const SW_BODY = '{"test": 2432232314}';
const SW_SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

it('matches the official Standard Webhooks test vector', function () {
    expect((new StandardWebhookSigner(SW_SECRET))->sign(SW_MSG_ID, SW_TIMESTAMP, SW_BODY))->toBe(SW_SIGNATURE);
});

it('accepts the secret without its whsec_ prefix', function () {
    expect((new StandardWebhookSigner(substr(SW_SECRET, 6)))->sign(SW_MSG_ID, SW_TIMESTAMP, SW_BODY))->toBe(SW_SIGNATURE);
});

it('builds the three Standard Webhooks headers', function () {
    expect((new StandardWebhookSigner(SW_SECRET))->headers(SW_MSG_ID, SW_TIMESTAMP, SW_BODY))->toBe([
        'webhook-id' => SW_MSG_ID,
        'webhook-timestamp' => '1614265330',
        'webhook-signature' => SW_SIGNATURE,
    ]);
});

it('verifies the published vector and its own signatures', function () {
    $signer = new StandardWebhookSigner(SW_SECRET);

    expect($signer->verify(SW_BODY, [
        'Webhook-Id' => SW_MSG_ID,
        'Webhook-Timestamp' => (string) SW_TIMESTAMP,
        'Webhook-Signature' => SW_SIGNATURE,
    ], SW_TIMESTAMP))->toBeTrue();

    $now = time();
    expect($signer->verify('{"a":1}', $signer->headers('msg_1', $now, '{"a":1}')))->toBeTrue();
});

it('finds a valid signature among several space-separated ones', function () {
    $signer = new StandardWebhookSigner(SW_SECRET);
    $headers = $signer->headers(SW_MSG_ID, SW_TIMESTAMP, SW_BODY);
    $headers['webhook-signature'] = 'v1a,ignored v1,Zm9yZ2VkCg== '.SW_SIGNATURE;

    expect($signer->verify(SW_BODY, $headers, SW_TIMESTAMP))->toBeTrue();
});

it('rejects a tampered body, id, signature or other secret', function () {
    $signer = new StandardWebhookSigner(SW_SECRET);
    $headers = $signer->headers(SW_MSG_ID, SW_TIMESTAMP, SW_BODY);

    expect($signer->verify(SW_BODY.' ', $headers, SW_TIMESTAMP))->toBeFalse()
        ->and($signer->verify(SW_BODY, ['webhook-id' => 'msg_other'] + $headers, SW_TIMESTAMP))->toBeFalse()
        ->and($signer->verify(SW_BODY, ['webhook-signature' => 'v1,'.base64_encode(str_repeat('x', 32))] + $headers, SW_TIMESTAMP))->toBeFalse()
        ->and($signer->verify(SW_BODY, ['webhook-signature' => substr(SW_SIGNATURE, 3)] + $headers, SW_TIMESTAMP))->toBeFalse()
        ->and((new StandardWebhookSigner(StandardWebhookSigner::generateSecret()))->verify(SW_BODY, $headers, SW_TIMESTAMP))->toBeFalse();
});

it('rejects a timestamp more than five minutes off, either way', function (int $skew, bool $valid) {
    $signer = new StandardWebhookSigner(SW_SECRET);

    expect($signer->verify(SW_BODY, $signer->headers(SW_MSG_ID, SW_TIMESTAMP, SW_BODY), SW_TIMESTAMP + $skew))->toBe($valid);
})->with([
    'on time' => [0, true],
    '5 min late' => [300, true],
    '5 min early' => [-300, true],
    'too old' => [301, false],
    'from the future' => [-301, false],
]);

it('rejects missing or malformed headers', function () {
    $signer = new StandardWebhookSigner(SW_SECRET);

    expect($signer->verify(SW_BODY, [], SW_TIMESTAMP))->toBeFalse()
        ->and($signer->verify(SW_BODY, ['webhook-id' => SW_MSG_ID, 'webhook-timestamp' => '1614265330.5', 'webhook-signature' => SW_SIGNATURE], SW_TIMESTAMP))->toBeFalse();
});

it('generates whsec_ secrets of 24 to 64 random bytes', function () {
    $secret = StandardWebhookSigner::generateSecret();

    expect($secret)->toStartWith('whsec_')
        ->and(strlen(base64_decode(substr($secret, 6), true)))->toBe(32)
        ->and(StandardWebhookSigner::generateSecret())->not->toBe($secret)
        ->and(strlen(base64_decode(substr(StandardWebhookSigner::generateSecret(64), 6), true)))->toBe(64);

    expect(fn () => StandardWebhookSigner::generateSecret(16))->toThrow(InvalidArgumentException::class);
});

it('refuses a secret that is not base64 of 24 to 64 bytes', function (string $secret) {
    expect(fn () => new StandardWebhookSigner($secret))->toThrow(InvalidArgumentException::class);
})->with([
    'not base64' => ['whsec_not base64!'],
    'too short' => ['whsec_'.base64_encode(str_repeat('a', 23))],
    'too long' => ['whsec_'.base64_encode(str_repeat('a', 65))],
]);
