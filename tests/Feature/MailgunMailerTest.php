<?php

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunHttpTransport;

/*
 * Hosted Bilis sends through Mailgun's EU region. An EU domain is unknown to
 * the US API, so the endpoint must default to EU without anyone setting it.
 */
beforeEach(function () {
    config([
        'mail.default' => 'mailgun',
        'mail.from' => ['address' => 'alerts@mail.bilis.app', 'name' => 'Bilis'],
        'services.mailgun.domain' => 'mail.bilis.app',
        'services.mailgun.secret' => 'key-test',
    ]);
});

test('the mailgun mailer uses the EU API by default', function () {
    expect(config('services.mailgun.endpoint'))->toBe('api.eu.mailgun.net')
        ->and(Mail::mailer('mailgun')->getSymfonyTransport())->toBeInstanceOf(MailgunHttpTransport::class)
        ->and((string) Mail::mailer('mailgun')->getSymfonyTransport())->toBe('mailgun+https://api.eu.mailgun.net?domain=mail.bilis.app');
});

test('a message is posted to the EU messages endpoint of the Mailgun domain', function () {
    $requests = [];

    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
        $requests[] = compact('method', 'url');

        return new MockResponse('{"id":"<1@mail.bilis.app>","message":"Queued. Thank you."}', ['http_code' => 200]);
    });

    $transport = new MailgunHttpTransport('key-test', 'mail.bilis.app', 'eu', $client);
    $transport->setHost('api.eu.mailgun.net');
    Mail::mailer('mailgun')->setSymfonyTransport($transport);

    Mail::mailer('mailgun')->raw('Disk is 93% full on web-1.', function (Message $message) {
        $message->to('ops@example.com')->subject('[FIRING] Disk full — web-1');
    });

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['method'])->toBe('POST')
        ->and($requests[0]['url'])->toBe('https://api.eu.mailgun.net/v3/mail.bilis.app/messages.mime');
});
