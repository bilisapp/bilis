<?php

use App\Enums\NotificationChannelType;
use App\Services\Alerting\NotificationChannelConfig;
use App\Services\Alerting\Webhooks\StandardWebhookSigner;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The validation errors for a channel config, keyed by field.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, list<string>>
 */
function channelConfigErrors(NotificationChannelType $type, array $config): array
{
    return Validator::make(['config' => $config], NotificationChannelConfig::rules($type))->errors()->toArray();
}

it('accepts members or a list of addresses for email', function (array $config) {
    expect(channelConfigErrors(NotificationChannelType::Email, $config))->toBe([]);
})->with([
    'members' => [['recipients' => 'members']],
    'addresses' => [['addresses' => ['oncall@acme.test', 'ops@acme.test']]],
]);

it('refuses email configs without a valid recipient', function (array $config, string $field) {
    expect(channelConfigErrors(NotificationChannelType::Email, $config))->toHaveKey($field);
})->with([
    'nothing' => [[], 'config.recipients'],
    'unknown recipients' => [['recipients' => 'everyone'], 'config.recipients'],
    'both' => [['recipients' => 'members', 'addresses' => ['a@acme.test']], 'config.recipients'],
    'empty list' => [['addresses' => []], 'config.addresses'],
    'bad address' => [['addresses' => ['not-an-email']], 'config.addresses.0'],
    'duplicate' => [['addresses' => ['a@acme.test', 'A@acme.test']], 'config.addresses.1'],
    'too many' => [['addresses' => array_map(fn (int $i): string => "u{$i}@acme.test", range(1, 21))], 'config.addresses'],
]);

it('requires Slack URLs on hooks.slack.com', function () {
    expect(channelConfigErrors(NotificationChannelType::Slack, ['url' => 'https://hooks.slack.com/services/T0/B0/abc']))->toBe([])
        ->and(channelConfigErrors(NotificationChannelType::Slack, ['url' => 'https://example.com/services/T0/B0/abc'])['config.url'])
        ->toBe(['The URL must be on hooks.slack.com.'])
        ->and(channelConfigErrors(NotificationChannelType::Slack, []))->toHaveKey('config.url');
});

it('validates webhook URLs through the SSRF guard', function (string $url) {
    expect(channelConfigErrors(NotificationChannelType::Webhook, ['url' => $url]))->toHaveKey('config.url');
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'private' => 'https://10.0.0.8/hook',
    'localhost' => 'https://localhost/hook',
    'odd port' => 'https://hooks.acme.test:8443/hook',
    'userinfo' => 'https://user:pass@hooks.acme.test/hook',
    'not a url' => 'hooks.acme.test',
]);

it('accepts a public webhook URL with or without a secret, and refuses a bad secret', function () {
    expect(channelConfigErrors(NotificationChannelType::Webhook, ['url' => 'https://hooks.acme.test/bilis']))->toBe([])
        ->and(channelConfigErrors(NotificationChannelType::Webhook, ['url' => 'https://hooks.acme.test/bilis', 'secret' => StandardWebhookSigner::generateSecret()]))->toBe([])
        ->and(channelConfigErrors(NotificationChannelType::Webhook, ['url' => 'https://hooks.acme.test/bilis', 'secret' => 'hunter2']))->toHaveKey('config.secret')
        ->and(channelConfigErrors(NotificationChannelType::Webhook, ['url' => 'https://hooks.acme.test/bilis', 'secret' => 'whsec_c2hvcnQ=']))->toHaveKey('config.secret');
});

it('generates a whsec_ secret for a webhook without one, and keeps a given one', function () {
    $generated = NotificationChannelConfig::prepare(NotificationChannelType::Webhook, ['url' => 'https://hooks.acme.test/bilis']);
    $given = StandardWebhookSigner::generateSecret();

    expect($generated['secret'])->toStartWith('whsec_')
        ->and(fn () => new StandardWebhookSigner($generated['secret']))->not->toThrow(InvalidArgumentException::class)
        ->and(NotificationChannelConfig::prepare(NotificationChannelType::Webhook, ['url' => 'x', 'secret' => $given])['secret'])->toBe($given)
        ->and(NotificationChannelConfig::prepare(NotificationChannelType::Webhook, ['url' => 'x', 'secret' => ' '])['secret'])->toStartWith('whsec_')
        ->and(NotificationChannelConfig::prepare(NotificationChannelType::Slack, ['url' => 'x']))->toBe(['url' => 'x']);
});
