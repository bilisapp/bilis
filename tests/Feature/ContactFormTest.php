<?php

declare(strict_types=1);

use App\Enums\ContactTopic;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('');
});

/**
 * A valid submission, so each test states only what it is about.
 *
 * @return array<string, string>
 */
function contactPayload(array $overrides = []): array
{
    return [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'topic' => ContactTopic::Upgrade->value,
        'message' => 'Six services, about 40 million lines a week, and we need 90 days.',
        ...$overrides,
    ];
}

it('renders the contact form to a logged-out visitor', function () {
    get(route('contact.show'))
        ->assertOk()
        ->assertSee('<title>Contact — '.config('app.name').'</title>', false)
        ->assertSee('Tell us what you run.')
        ->assertSee(config('legal.contact.general'))
        ->assertDontSee('data-page=', false);
});

it('preselects the topic named in the query string', function () {
    $response = get(route('contact.show', ['topic' => 'upgrade']))->assertOk();

    expect(html($response))->toContain('value="upgrade" selected');
});

it('falls back to a general enquiry for an unknown topic', function () {
    $response = get(route('contact.show', ['topic' => 'nonsense']))->assertOk();

    expect(html($response))->toContain('value="general" selected');
});

it('prefills the name and email of a signed-in visitor', function () {
    $user = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

    $this->actingAs($user)
        ->get(route('contact.show'))
        ->assertOk()
        ->assertSee('value="Grace Hopper"', false)
        ->assertSee('value="grace@example.com"', false);
});

it('stores a message and queues the notification', function () {
    post(route('contact.store'), contactPayload())
        ->assertRedirect(route('contact.show'))
        ->assertSessionHas('contact.sent', true);

    $message = ContactMessage::sole();

    expect($message->name)->toBe('Ada Lovelace')
        ->and($message->email)->toBe('ada@example.com')
        ->and($message->topic)->toBe(ContactTopic::Upgrade)
        ->and($message->user_id)->toBeNull()
        ->and($message->team_id)->toBeNull();

    Mail::assertQueued(ContactMessageReceived::class, function (ContactMessageReceived $mail) {
        return $mail->hasTo(config('legal.contact.general'))
            && $mail->hasReplyTo('ada@example.com')
            && str_contains($mail->envelope()->subject, '[Bilis contact]');
    });
});

it('shows the thank-you state after a successful send', function () {
    $this->followingRedirects()
        ->post(route('contact.store'), contactPayload())
        ->assertOk()
        ->assertSee('Got it.')
        ->assertDontSee('Send message');
});

it('records the account and team of a signed-in sender', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->post(route('contact.store'), contactPayload())->assertRedirect();

    $message = ContactMessage::sole();

    expect($message->user_id)->toBe($user->id)
        ->and($message->team_id)->toBe($team->id);
});

it('validates what it is given', function (array $payload, string $field) {
    post(route('contact.store'), contactPayload($payload))
        ->assertSessionHasErrors($field);

    expect(ContactMessage::count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'no name' => [['name' => ''], 'name'],
    'a name that is a paragraph' => [['name' => str_repeat('a', 121)], 'name'],
    'not an email' => [['email' => 'not-an-email'], 'email'],
    'an unknown topic' => [['topic' => 'billing'], 'topic'],
    'a message too short to act on' => [['message' => 'help'], 'message'],
    'a message longer than the column' => [['message' => str_repeat('a', 5001)], 'message'],
]);

it('swallows a honeypot submission without storing or mailing anything', function () {
    // Answered exactly like a success: telling a script it was caught is
    // telling it what to change.
    post(route('contact.store'), contactPayload(['website' => 'https://spam.example']))
        ->assertRedirect(route('contact.show'))
        ->assertSessionHas('contact.sent', true);

    expect(ContactMessage::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('still records the message when the mailer throws', function () {
    // The row is the record; the email is only a notification. A mailer that
    // is not configured yet must not turn a question into a 500.
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('no mailer'));

    post(route('contact.store'), contactPayload())
        ->assertRedirect(route('contact.show'))
        ->assertSessionHas('contact.sent', true);

    expect(ContactMessage::count())->toBe(1);
});

it('throttles the form after five submissions', function () {
    foreach (range(1, 5) as $attempt) {
        post(route('contact.store'), contactPayload())->assertRedirect();
    }

    post(route('contact.store'), contactPayload())->assertStatus(429);

    expect(ContactMessage::count())->toBe(5);
});
