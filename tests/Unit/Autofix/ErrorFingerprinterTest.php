<?php

use App\Services\Autofix\ErrorFingerprinter;

/**
 * Build a log row the way LogQuery hands them out.
 *
 * @param  array<string, string>  $logAttributes
 * @return array<string, mixed>
 */
function errorRow(string $body, array $logAttributes = [], string $service = 'checkout'): array
{
    return [
        'projectId' => '1',
        'timestamp' => '2026-08-27 10:00:00.000000000',
        'severityText' => 'ERROR',
        'severityNumber' => 17,
        'serviceName' => $service,
        'body' => $body,
        'resourceAttributes' => ['host' => 'web-1'],
        'logAttributes' => $logAttributes,
    ];
}

/**
 * A realistic PHP stack trace, parameterised by the noise that shifts between
 * two occurrences of the same bug.
 */
function phpStack(string $root, int $line, string $requestId): string
{
    return implode("\n", [
        'App\\Exceptions\\PaymentFailed: Charge declined for order 4821',
        sprintf('#0 %s/app/Services/Billing/Charger.php(%d): App\\Services\\Billing\\Gateway->charge(Array)', $root, $line),
        sprintf('#1 %s/app/Http/Controllers/CheckoutController.php(%d): App\\Services\\Billing\\Charger->run(Object(App\\Models\\Order))', $root, $line + 12),
        sprintf('#2 %s/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\CheckoutController->store(Object(Illuminate\\Http\\Request))', $root),
        sprintf('#3 %s/vendor/laravel/framework/src/Illuminate/Routing/Route.php(43): Illuminate\\Routing\\Controller->callAction()', $root),
        sprintf('#4 {main} request %s', $requestId),
    ]);
}

/**
 * A realistic V8 stack trace, parameterised the same way.
 */
function jsStack(string $root, int $line): string
{
    return implode("\n", [
        'TypeError: Cannot read properties of undefined (reading "total")',
        sprintf('    at summarise (%s/src/cart/summary.js:%d:19)', $root, $line),
        sprintf('    at renderCart (%s/src/cart/render.js:%d:7)', $root, $line + 30),
        sprintf('    at %s/node_modules/react-dom/cjs/react-dom.production.js:118:24', $root),
    ]);
}

beforeEach(function () {
    $this->fingerprinter = new ErrorFingerprinter;
});

test('the same php error fingerprints identically across deploy paths and line numbers', function () {
    $first = errorRow(phpStack('/var/www/releases/20260827093000', 118, 'req-8f2c1a9b'));
    $second = errorRow(phpStack('/home/sam/code/checkout', 131, 'req-0d41a3ff'));

    expect($this->fingerprinter->fingerprint($first))
        ->toBe($this->fingerprinter->fingerprint($second));
});

test('the same javascript error fingerprints identically across paths and line numbers', function () {
    $first = errorRow(jsStack('/srv/app/current', 44));
    $second = errorRow(jsStack('/builds/acme/web', 61));

    expect($this->fingerprinter->fingerprint($first))
        ->toBe($this->fingerprinter->fingerprint($second));
});

test('a different call site fingerprints differently', function () {
    $first = errorRow(phpStack('/var/www', 118, 'req-1'));
    $second = errorRow(str_replace('Charger.php', 'Refunder.php', phpStack('/var/www', 118, 'req-2')));

    expect($this->fingerprinter->fingerprint($first))
        ->not->toBe($this->fingerprinter->fingerprint($second));
});

test('the same stack from a different service fingerprints differently', function () {
    $stack = phpStack('/var/www', 118, 'req-1');

    expect($this->fingerprinter->fingerprint(errorRow($stack, service: 'checkout')))
        ->not->toBe($this->fingerprinter->fingerprint(errorRow($stack, service: 'billing')));
});

test('a different exception class fingerprints differently', function () {
    $stack = phpStack('/var/www', 118, 'req-1');

    $attributed = errorRow($stack, ['exception.type' => 'App\\Exceptions\\PaymentFailed']);
    $other = errorRow($stack, ['exception.type' => 'App\\Exceptions\\CardExpired']);

    expect($this->fingerprinter->fingerprint($attributed))
        ->not->toBe($this->fingerprinter->fingerprint($other));
});

test('attribute carried stacks and body only stacks are read the same way', function () {
    $stack = phpStack('/var/www/releases/20260827093000', 118, 'req-1');

    $attributed = errorRow('Charge declined', [
        'exception.type' => 'App\\Exceptions\\PaymentFailed',
        'exception.message' => 'Charge declined for order 4821',
        'exception.stacktrace' => $stack,
    ]);

    expect($this->fingerprinter->fingerprint($attributed))
        ->toBe($this->fingerprinter->fingerprint(errorRow($stack)));
});

test('hex addresses uuids and hashes are normalised out of frames', function () {
    $frame = '#0 /app/src/Runtime.php(12): Closure(0x7ffee3bff5c0, 4f9d2c1e-9a02-4c2f-8f31-3f6d6b1c9d77)';
    $other = '#0 /app/src/Runtime.php(99): Closure(0x00007f8b12345678, 8b7c6d5e-1111-4222-9333-444455556666)';

    expect($this->fingerprinter->fingerprint(errorRow("RuntimeException: boom\n".$frame)))
        ->toBe($this->fingerprinter->fingerprint(errorRow("RuntimeException: boom\n".$other)));
});

test('records with no stack fall back to a normalised message', function () {
    $first = errorRow('Undefined array key "total" for user 4192');
    $second = errorRow('Undefined array key "total" for user 88213');

    expect($this->fingerprinter->fingerprint($first))
        ->toBe($this->fingerprinter->fingerprint($second));

    expect($this->fingerprinter->fingerprint($first))
        ->not->toBe($this->fingerprinter->fingerprint(errorRow('Undefined array key "subtotal" for user 4192')));
});

test('the fingerprint is a sha256 hex digest', function () {
    expect($this->fingerprinter->fingerprint(errorRow('boom')))->toMatch('/^[0-9a-f]{64}$/');
});

test('the exception class is read from attributes or the body', function () {
    expect($this->fingerprinter->exceptionClass(errorRow('x', ['exception.type' => 'App\\Exceptions\\PaymentFailed'])))
        ->toBe('App\\Exceptions\\PaymentFailed');

    expect($this->fingerprinter->exceptionClass(errorRow('TypeError: Cannot read properties of undefined')))
        ->toBe('TypeError');

    expect($this->fingerprinter->exceptionClass(errorRow('just a message')))->toBe('');
});

test('the message prefers the exception attribute over the body', function () {
    expect($this->fingerprinter->message(errorRow("first line\nsecond line", ['exception.message' => 'Charge declined'])))
        ->toBe('Charge declined');

    expect($this->fingerprinter->message(errorRow("first line\nsecond line")))->toBe('first line');
});

test('only the top frames take part in the identity', function () {
    $deep = phpStack('/var/www', 118, 'req-1')."\n#5 /var/www/vendor/other/File.php(9): Something->else()";

    expect($this->fingerprinter->stackFrames(errorRow($deep)))
        ->toHaveCount(ErrorFingerprinter::FRAME_DEPTH);

    expect($this->fingerprinter->fingerprint(errorRow($deep)))
        ->toBe($this->fingerprinter->fingerprint(errorRow(phpStack('/var/www', 118, 'req-1'))));
});
