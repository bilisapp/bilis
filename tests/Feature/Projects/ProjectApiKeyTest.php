<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\Team;

test('projects belong to a team', function () {
    $team = Team::factory()->create();

    $project = Project::factory()->forTeam($team)->create();

    expect($project->team->is($team))->toBeTrue()
        ->and($team->projects->pluck('id')->all())->toBe([$project->id]);
});

test('project slugs are generated uniquely per team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $first = $team->projects()->create(['name' => 'Web App']);
    $second = $team->projects()->create(['name' => 'Web App']);
    $otherTeamProject = $otherTeam->projects()->create(['name' => 'Web App']);

    expect($first->slug)->toBe('web-app')
        ->and($second->slug)->toBe('web-app-1')
        ->and($otherTeamProject->slug)->toBe('web-app');
});

test('generating a key stores only the hash and display prefix', function () {
    $project = Project::factory()->create();

    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    expect($apiKey->plainTextKey)->toStartWith('bilis_')
        ->and($apiKey->plainTextKey)->toHaveLength(6 + ProjectApiKey::RANDOM_LENGTH)
        ->and($apiKey->key_hash)->toBe(hash('sha256', $apiKey->plainTextKey))
        ->and($apiKey->key_prefix)->toBe(substr($apiKey->plainTextKey, 0, ProjectApiKey::DISPLAY_PREFIX_LENGTH))
        ->and($apiKey->project->is($project))->toBeTrue();

    $this->assertDatabaseHas('project_api_keys', [
        'id' => $apiKey->id,
        'project_id' => $project->id,
        'name' => 'Ingest Key',
        'key_hash' => hash('sha256', $apiKey->plainTextKey),
    ]);

    $this->assertDatabaseMissing('project_api_keys', [
        'key_hash' => $apiKey->plainTextKey,
    ]);

    expect($apiKey->fresh()->getAttributes())->not->toContain($apiKey->plainTextKey)
        ->and($apiKey->toArray())->not->toHaveKey('key_hash');
});

test('generated keys are unique', function () {
    $project = Project::factory()->create();

    $first = ProjectApiKey::generate($project, 'One');
    $second = ProjectApiKey::generate($project, 'Two');

    expect($first->plainTextKey)->not->toBe($second->plainTextKey);
});

test('a key can be found by its plaintext value', function () {
    $project = Project::factory()->create();

    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    $found = ProjectApiKey::findByPlainKey($apiKey->plainTextKey);

    expect($found?->id)->toBe($apiKey->id)
        ->and($found?->project->id)->toBe($project->id);
});

test('an unknown or empty plaintext key resolves to null', function (string $key) {
    ProjectApiKey::generate(Project::factory()->create(), 'Ingest Key');

    expect(ProjectApiKey::findByPlainKey($key))->toBeNull();
})->with([
    'unknown key' => 'bilis_nope',
    'empty key' => '',
    'whitespace key' => '   ',
]);

test('the api key factory produces a usable key', function () {
    $apiKey = ProjectApiKey::factory()->withPlainKey('bilis_factorykey')->used()->create();

    expect($apiKey->project)->toBeInstanceOf(Project::class)
        ->and($apiKey->last_used_at)->not->toBeNull()
        ->and(ProjectApiKey::findByPlainKey('bilis_factorykey')?->id)->toBe($apiKey->id);
});

test('marking a key as used is throttled', function () {
    $apiKey = ProjectApiKey::factory()->create();

    $apiKey->markAsUsed();
    $firstUsedAt = $apiKey->fresh()->last_used_at;

    expect($firstUsedAt)->not->toBeNull();

    $this->travel(5)->seconds();
    $apiKey->markAsUsed();

    expect($apiKey->fresh()->last_used_at->timestamp)->toBe($firstUsedAt->timestamp);

    $this->travel(ProjectApiKey::LAST_USED_THROTTLE_SECONDS + 5)->seconds();
    $apiKey->markAsUsed();

    expect($apiKey->fresh()->last_used_at->timestamp)->toBeGreaterThan($firstUsedAt->timestamp);
});

test('generating a key issues a public half stored in plaintext', function () {
    $project = Project::factory()->create();

    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    expect($apiKey->public_key)->toStartWith(ProjectApiKey::PUBLIC_KEY_PREFIX)
        ->and($apiKey->public_key)->toHaveLength(strlen(ProjectApiKey::PUBLIC_KEY_PREFIX) + ProjectApiKey::RANDOM_LENGTH);

    // The DSN has to stay readable, so unlike the secret half it is stored as
    // it is handed out and survives a reload.
    $this->assertDatabaseHas('project_api_keys', [
        'id' => $apiKey->id,
        'public_key' => $apiKey->public_key,
    ]);

    expect($apiKey->fresh()->public_key)->toBe($apiKey->public_key);
});

test('a public key can never look like a secret key', function () {
    $project = Project::factory()->create();

    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    // `Str::random()` emits alphanumerics only, so `bilis_pk_` is a prefix no
    // secret key can be generated with — the two halves stay distinguishable.
    expect($apiKey->plainTextKey)->not->toStartWith(ProjectApiKey::PUBLIC_KEY_PREFIX)
        ->and(ProjectApiKey::findByPlainKey($apiKey->public_key))->toBeNull()
        ->and(ProjectApiKey::findByPublicKey($apiKey->plainTextKey))->toBeNull();
});

test('a key is found by its public half', function () {
    $project = Project::factory()->create();

    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    expect(ProjectApiKey::findByPublicKey($apiKey->public_key)?->is($apiKey))->toBeTrue()
        ->and(ProjectApiKey::findByPublicKey('  '.$apiKey->public_key.'  ')?->is($apiKey))->toBeTrue()
        ->and(ProjectApiKey::findByPublicKey('bilis_pk_nope'))->toBeNull()
        ->and(ProjectApiKey::findByPublicKey(''))->toBeNull();
});

test('the DSN carries the public key and the project id', function () {
    config(['app.url' => 'https://logs.example.com']);

    $project = Project::factory()->create();
    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    expect($apiKey->dsn())->toBe("https://{$apiKey->public_key}@logs.example.com/{$project->id}");
});

test('the DSN keeps a port and a path prefix from the app url', function () {
    config(['app.url' => 'http://localhost:8000/bilis/']);

    $project = Project::factory()->create();
    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    expect($apiKey->dsn())->toBe("http://{$apiKey->public_key}@localhost:8000/bilis/{$project->id}");
});
