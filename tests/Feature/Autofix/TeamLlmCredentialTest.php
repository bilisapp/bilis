<?php

use App\Enums\LlmProvider;
use App\Enums\TeamRole;
use App\Models\FixJob;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use App\Models\User;
use App\Services\Autofix\AyosClient;
use App\Services\Autofix\AyosException;
use App\Services\Autofix\LlmCredentials;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Bring-your-own-key, scoped to a team, and now more than one key per team.
 *
 * The model credential is the only thing in a job spec that can cost money
 * rather than merely propose a patch, and it reaches the runner in the clear —
 * the platform has no per-run secret channel. A key belonging to one customer
 * bounds the worst case to that customer's own budget, which is the entire
 * reason it lives on the team rather than in config. Several keys per team is
 * the same argument again: an experiments budget at OpenRouter and the real
 * budget at Anthropic are two boundaries, not one.
 */
function teamWith(TeamRole $role = TeamRole::Owner): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return [$team, $user];
}

/* ------------------------------------------------------------------ storage */

test('a key is encrypted at rest and never stored in the clear', function () {
    [$team] = teamWith();

    $credential = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'Production', 'sk-ant-super-secret-value-1234');

    $stored = DB::table('team_llm_credentials')->where('id', $credential->id)->value('api_key');

    expect($stored)->not->toContain('sk-ant-super-secret-value-1234')
        ->and($credential->fresh()->api_key)->toBe('sk-ant-super-secret-value-1234');
});

test('only the last four characters are kept in the clear, as a hint', function () {
    [$team] = teamWith();

    $credential = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'Production', 'sk-ant-super-secret-value-1234');

    expect($credential->hint)->toBe('1234');
});

/*
 * Not fillable, deliberately. A mass-assigned credential is one stray
 * `update($request->all())` away from being set by anyone who can rename a team.
 */
test('a key cannot be mass assigned', function () {
    [$team] = teamWith();

    $credential = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'Production', 'sk-ant-the-real-value-0000');

    $credential->update(['label' => 'Renamed', 'api_key' => 'sk-ant-injected']);

    expect($credential->fresh()->label)->toBe('Renamed')
        ->and($credential->fresh()->api_key)->toBe('sk-ant-the-real-value-0000');
});

/*
 * "The default" is a property of the team, not of a row: two of them, or none,
 * and the scheduled scan has no answer to which key it should spend.
 */
test('the first key a team adds becomes its default', function () {
    [$team] = teamWith();

    $first = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'First', 'sk-ant-the-first-key-1111');
    $second = TeamLlmCredential::add($team, LlmProvider::OpenAi, 'Second', 'sk-openai-the-second-key-22');

    expect($first->is_default)->toBeTrue()
        ->and($second->is_default)->toBeFalse()
        ->and($team->defaultLlmCredential()->id)->toBe($first->id);
});

test('making one key the default demotes the previous one', function () {
    [$team] = teamWith();

    $first = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'First', 'sk-ant-the-first-key-1111');
    $second = TeamLlmCredential::add($team, LlmProvider::OpenRouter, 'Second', 'sk-or-v1-the-second-key-22');

    $second->makeDefault();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($team->llmCredentials()->where('is_default', true)->count())->toBe(1);
});

/* ---------------------------------------------------------------- resolution */

test('a team key wins over the instance key', function () {
    config(['autofix.llm.api_key' => 'sk-ant-instance']);
    [$team] = teamWith();
    TeamLlmCredential::add($team, LlmProvider::Anthropic, 'Customer', 'sk-ant-customer');

    expect(app(LlmCredentials::class)->forTeam($team)->key)->toBe('sk-ant-customer');
});

/*
 * The instance key stays for single-tenant and self-hosted deployments, where
 * "the customer" and "the operator" are the same party.
 */
test('the instance key is the fallback when a team brought none', function () {
    config(['autofix.llm.api_key' => 'sk-ant-instance', 'autofix.llm.provider' => 'anthropic']);
    [$team] = teamWith();

    $resolved = app(LlmCredentials::class)->forTeam($team);

    expect($resolved->key)->toBe('sk-ant-instance')
        ->and($resolved->provider)->toBe(LlmProvider::Anthropic);
});

test('the instance key can name a provider other than Anthropic', function () {
    config(['autofix.llm.api_key' => 'sk-or-v1-instance', 'autofix.llm.provider' => 'openrouter']);
    [$team] = teamWith();

    expect(app(LlmCredentials::class)->forTeam($team)->provider)->toBe(LlmProvider::OpenRouter);
});

test('with neither key the failure names the team, not a config path', function () {
    config(['autofix.llm.api_key' => null]);
    [$team] = teamWith();

    expect(fn () => app(LlmCredentials::class)->forTeam($team))
        ->toThrow(AyosException::class, $team->name);
});

test('a resolved credential carries the host its key is valid at', function () {
    config(['autofix.llm.api_key' => null]);
    [$team] = teamWith();
    TeamLlmCredential::add($team, LlmProvider::OpenRouter, 'Routed', 'sk-or-v1-routed-key-1234');

    expect(app(LlmCredentials::class)->forTeam($team)->host())->toBe('openrouter.ai');
});

test('a job resolves the key through its own team', function () {
    config(['autofix.llm.api_key' => null]);
    $job = ayosJob();
    TeamLlmCredential::add($job->project->team, LlmProvider::Anthropic, 'Team', 'sk-ant-this-teams-key');

    expect(app(LlmCredentials::class)->forJob($job)->key)->toBe('sk-ant-this-teams-key');
});

test('configuredFor answers without throwing', function () {
    config(['autofix.llm.api_key' => null]);
    [$team] = teamWith();

    expect(app(LlmCredentials::class)->configuredFor($team))->toBeFalse();

    TeamLlmCredential::add($team, LlmProvider::Anthropic, 'Now there is one', 'sk-ant-now-there-is-one');

    expect(app(LlmCredentials::class)->configuredFor($team->fresh()))->toBeTrue();
});

/* ------------------------------------------------------------------ endpoint */

test('an owner can add a key', function () {
    [$team, $user] = teamWith();

    $this->actingAs($user)
        ->post(route('teams.llm-credentials.store', ['team' => $team->slug]), [
            'provider' => 'anthropic',
            'label' => 'Production',
            'api_key' => 'sk-ant-a-perfectly-good-key-9999',
        ])
        ->assertRedirect(route('teams.edit', ['team' => $team->slug]));

    expect($team->defaultLlmCredential()->api_key)->toBe('sk-ant-a-perfectly-good-key-9999');
});

test('a team can hold keys for several providers at once', function () {
    [$team, $user] = teamWith();

    foreach (['anthropic', 'openai', 'openrouter'] as $provider) {
        $this->actingAs($user)
            ->post(route('teams.llm-credentials.store', ['team' => $team->slug]), [
                'provider' => $provider,
                'label' => ucfirst($provider),
                'api_key' => 'sk-a-perfectly-good-key-'.$provider,
            ])
            ->assertRedirect();
    }

    expect($team->llmCredentials()->pluck('provider')->map->value->sort()->values()->all())
        ->toBe(['anthropic', 'openai', 'openrouter']);
});

test('a provider the runner has no catalogue for is refused', function () {
    [$team, $user] = teamWith();

    $this->actingAs($user)
        ->from(route('teams.edit', ['team' => $team->slug]))
        ->post(route('teams.llm-credentials.store', ['team' => $team->slug]), [
            'provider' => 'mistral',
            'label' => 'Nope',
            'api_key' => 'sk-a-perfectly-good-key-1234',
        ])
        ->assertSessionHasErrors('provider');

    expect($team->hasLlmCredential())->toBeFalse();
});

test('an obviously-not-a-key value is refused', function () {
    [$team, $user] = teamWith();

    $this->actingAs($user)
        ->from(route('teams.edit', ['team' => $team->slug]))
        ->post(route('teams.llm-credentials.store', ['team' => $team->slug]), [
            'provider' => 'anthropic',
            'label' => 'Production',
            'api_key' => 'nope',
        ])
        ->assertSessionHasErrors('api_key');

    expect($team->hasLlmCredential())->toBeFalse();
});

test('an owner can promote another key to the default', function () {
    [$team, $user] = teamWith();

    $first = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'First', 'sk-ant-the-first-key-1111');
    $second = TeamLlmCredential::add($team, LlmProvider::OpenAi, 'Second', 'sk-openai-the-second-key-22');

    $this->actingAs($user)
        ->patch(route('teams.llm-credentials.update', ['team' => $team->slug, 'credential' => $second->id]))
        ->assertRedirect(route('teams.edit', ['team' => $team->slug]));

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

test('an owner can remove a key', function () {
    [$team, $user] = teamWith();
    $credential = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'Production', 'sk-ant-a-perfectly-good-key-9999');

    $this->actingAs($user)
        ->delete(route('teams.llm-credentials.destroy', ['team' => $team->slug, 'credential' => $credential->id]))
        ->assertRedirect();

    expect($team->hasLlmCredential())->toBeFalse();
});

/*
 * A team left with keys but no default is a team whose scheduled scan silently
 * stops running.
 */
test('removing the default promotes a remaining key', function () {
    [$team, $user] = teamWith();
    $default = TeamLlmCredential::add($team, LlmProvider::Anthropic, 'First', 'sk-ant-the-first-key-1111');
    $other = TeamLlmCredential::add($team, LlmProvider::OpenAi, 'Second', 'sk-openai-the-second-key-22');

    $this->actingAs($user)
        ->delete(route('teams.llm-credentials.destroy', ['team' => $team->slug, 'credential' => $default->id]))
        ->assertRedirect();

    expect($other->fresh()->is_default)->toBeTrue()
        ->and($team->defaultLlmCredential()->id)->toBe($other->id);
});

/*
 * A credential id is not a capability. Resolving one through the team rather
 * than by id alone is what keeps one customer's key out of another's run.
 */
test('a team cannot touch another team\'s key', function () {
    [$team, $user] = teamWith();
    [$otherTeam] = teamWith();
    $theirs = TeamLlmCredential::add($otherTeam, LlmProvider::Anthropic, 'Theirs', 'sk-ant-belongs-to-them-9999');

    $this->actingAs($user)
        ->delete(route('teams.llm-credentials.destroy', ['team' => $team->slug, 'credential' => $theirs->id]))
        ->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
});

test('an ordinary member cannot add a key', function () {
    [$team, $member] = teamWith(TeamRole::Member);

    $this->actingAs($member)
        ->post(route('teams.llm-credentials.store', ['team' => $team->slug]), [
            'provider' => 'anthropic',
            'label' => 'Production',
            'api_key' => 'sk-ant-a-perfectly-good-key-9999',
        ])
        ->assertForbidden();

    expect($team->hasLlmCredential())->toBeFalse();
});

test('someone outside the team cannot add a key', function () {
    [$team] = teamWith();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('teams.llm-credentials.store', ['team' => $team->slug]), [
            'provider' => 'anthropic',
            'label' => 'Production',
            'api_key' => 'sk-ant-a-perfectly-good-key-9999',
        ])
        ->assertForbidden();
});

test('a guest cannot add a key', function () {
    [$team] = teamWith();

    $this->post(route('teams.llm-credentials.store', ['team' => $team->slug]), [
        'provider' => 'anthropic',
        'label' => 'Production',
        'api_key' => 'sk-ant-a-perfectly-good-key-9999',
    ])->assertRedirect(route('login'));
});

/*
 * The page has to say WHICH keys are configured without ever handling one.
 */
test('the settings page shows the hint but never the key', function () {
    [$team, $user] = teamWith();
    TeamLlmCredential::add($team, LlmProvider::Anthropic, 'Production', 'sk-ant-super-secret-value-1234');

    $response = $this->actingAs($user)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('llmCredentials', 1)
            ->where('llmCredentials.0.hint', '1234')
            ->where('llmCredentials.0.providerLabel', 'Anthropic')
            ->where('llmCredentials.0.isDefault', true)
            ->etc(),
        );

    expect($response->getContent())->not->toContain('sk-ant-super-secret-value-1234');
});

test('a team without a key sends an empty list', function () {
    [$team, $user] = teamWith();

    $this->actingAs($user)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('llmCredentials', 0)
            ->has('llmProviders', 3)
            ->etc(),
        );
});

/* ------------------------------------------------------------------ leakage */

/*
 * The key must not reach the runner's transcript either. Ayos redacts
 * `sk-ant-` patterns on its side; this checks nothing on ours writes it to a
 * job row on the way past.
 */
test('the key never lands on a fix job row', function () {
    config(['autofix.llm.api_key' => null]);
    fakeAyos();
    $runs = fakeRuns();

    $job = ayosJob();
    TeamLlmCredential::add($job->project->team, LlmProvider::Anthropic, 'Production', 'sk-ant-super-secret-value-1234');

    app(AyosClient::class)->dispatch($job);

    $row = json_encode(FixJob::query()->find($job->id)->getAttributes());

    expect($row)->not->toContain('sk-ant-super-secret-value-1234')
        ->and($runs->lastSpec()['llm_key'])->toBe('sk-ant-super-secret-value-1234');
});
