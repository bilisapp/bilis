<?php

use App\Services\Ingest\Envelope\Envelope;

test('a length prefixed payload may contain newlines', function () {
    $payload = '{"message":"line one\\nline two"}';
    $body = implode("\n", [
        '{"event_id":"aaaa"}',
        '{"type":"event","length":'.strlen($payload).'}',
        $payload,
        '{"type":"client_report","length":2}',
        '{}',
    ])."\n";

    $envelope = Envelope::parse($body);

    expect($envelope->malformed)->toBeFalse()
        ->and($envelope->items)->toHaveCount(2)
        ->and($envelope->itemsOfType('event'))->toHaveCount(1)
        ->and($envelope->itemsOfType('event')[0]->json())->toBe(['message' => "line one\nline two"]);
});

test('a payload without a declared length runs to the next newline', function () {
    $envelope = Envelope::parse("{\"event_id\":\"a\"}\n{\"type\":\"event\"}\n{\"message\":\"hi\"}\n{\"type\":\"session\"}\n{\"sid\":\"1\"}\n");

    expect($envelope->items)->toHaveCount(2)
        ->and($envelope->items[0]->json())->toBe(['message' => 'hi'])
        ->and($envelope->items[1]->type)->toBe('session');
});

test('a body with no trailing newline still yields its last item', function () {
    $envelope = Envelope::parse("{\"event_id\":\"a\"}\n{\"type\":\"event\"}\n{\"message\":\"hi\"}");

    expect($envelope->items)->toHaveCount(1)
        ->and($envelope->items[0]->json())->toBe(['message' => 'hi']);
});

test('a length longer than the body is clamped rather than refused', function () {
    $envelope = Envelope::parse("{\"event_id\":\"a\"}\n{\"type\":\"event\",\"length\":9000}\n{\"message\":\"truncated\"}");

    expect($envelope->items)->toHaveCount(1)
        ->and($envelope->items[0]->json())->toBe(['message' => 'truncated']);
});

test('an envelope header that is not JSON is malformed', function () {
    expect(Envelope::parse('not an envelope')->malformed)->toBeTrue()
        ->and(Envelope::parse('')->malformed)->toBeTrue()
        ->and(Envelope::parse(null)->malformed)->toBeTrue();
});

test('an item header that is not JSON stops parsing without losing earlier items', function () {
    $envelope = Envelope::parse("{\"event_id\":\"a\"}\n{\"type\":\"event\"}\n{\"message\":\"kept\"}\nnot json\n{\"message\":\"lost\"}\n");

    expect($envelope->malformed)->toBeTrue()
        ->and($envelope->items)->toHaveCount(1)
        ->and($envelope->items[0]->json())->toBe(['message' => 'kept']);
});

test('a binary attachment payload is kept as bytes and decodes to nothing', function () {
    $binary = "\x00\x01\x02\nnot\x00json";
    $body = "{\"event_id\":\"a\"}\n{\"type\":\"attachment\",\"length\":".strlen($binary)."}\n".$binary."\n";

    $envelope = Envelope::parse($body);

    expect($envelope->items)->toHaveCount(1)
        ->and($envelope->items[0]->payload)->toBe($binary)
        ->and($envelope->items[0]->json())->toBeNull();
});
