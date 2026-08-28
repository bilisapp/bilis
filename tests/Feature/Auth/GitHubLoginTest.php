<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config()->set('services.github', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect' => '/auth/github/callback',
    ]);
});

test('the login page offers GitHub when the instance is configured', function () {
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('github', true),
    );
});

test('the login page hides GitHub when the instance has no credentials', function () {
    config()->set('services.github.client_id', null);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('github', false),
    );
});

test('the redirect route sends the visitor to GitHub', function () {
    Socialite::fake('github');

    $this->get(route('github.redirect'))->assertRedirect();
});

test('the redirect route refuses when the instance has no credentials', function () {
    config()->set('services.github.client_id', null);

    $this->get(route('github.redirect'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
});

test('a known github id logs the matching user in', function () {
    $user = User::factory()->create(['github_id' => 'github-123']);

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-123',
        'name' => 'Someone Else',
        'email' => 'someone-else@example.com',
    ]));

    $response = $this->get(route('github.callback'));

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard'));

    expect($user->fresh()->email)->toBe($user->email)
        ->and(User::count())->toBe(1);
});

test('a verified github email links the existing account and verifies it', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'sam@example.com',
        'github_id' => null,
    ]);

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-456',
        'name' => 'Sam',
        'email' => 'sam@example.com',
    ]));

    $response = $this->get(route('github.callback'));

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->github_id)->toBe('github-456')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(User::count())->toBe(1);
});

test('an unknown github account registers a user with a personal team', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-789',
        'name' => 'Jason Beggs',
        'email' => 'jason@example.com',
    ]));

    $response = $this->get(route('github.callback'));

    $user = User::where('email', 'jason@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard'));

    expect($user->github_id)->toBe('github-789')
        ->and($user->name)->toBe('Jason Beggs')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBeEmpty();

    $team = $user->fresh()->personalTeam();

    expect($team)->not->toBeNull()
        ->and($team->name)->toBe("Jason Beggs's Team")
        ->and($team->is_personal)->toBeTrue()
        ->and($user->fresh()->current_team_id)->toBe($team->id);
});

test('a github account without a name falls back to its handle', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-790',
        'name' => null,
        'nickname' => 'octocat',
        'email' => 'octocat@example.com',
    ]));

    $this->get(route('github.callback'));

    expect(User::where('email', 'octocat@example.com')->value('name'))->toBe('octocat');
});

test('a github account with no email is refused rather than half registered', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'github-791',
        'name' => 'No Email',
        'email' => null,
    ]));

    $response = $this->get(route('github.callback'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');

    expect(Auth::check())->toBeFalse()
        ->and(User::count())->toBe(0);
});

test('a declined authorization returns to the login page', function () {
    $response = $this->get(route('github.callback', ['error' => 'access_denied']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error', 'GitHub sign-in was cancelled.');

    expect(Auth::check())->toBeFalse()
        ->and(User::count())->toBe(0);
});

test('a failing exchange with GitHub returns to the login page', function () {
    Socialite::fake('github', function () {
        throw new RuntimeException('Invalid state.');
    });

    $response = $this->get(route('github.callback', ['code' => 'abc', 'state' => 'stale']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');

    expect(Auth::check())->toBeFalse()
        ->and(User::count())->toBe(0);
});

test('the login page renders the error left behind by a failed sign in', function () {
    $this->withSession(['error' => 'GitHub sign-in was cancelled.'])
        ->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('error', 'GitHub sign-in was cancelled.'),
        );
});
