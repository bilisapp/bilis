<?php

use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('project.api-key')->get('/test-ingest', function (Request $request) {
        return response()->json([
            'project_id' => AuthenticateProjectApiKey::project($request)?->id,
            'api_key_id' => AuthenticateProjectApiKey::apiKey($request)?->id,
        ]);
    });
});

test('a request without an api key is rejected', function () {
    $this->getJson('/test-ingest')
        ->assertUnauthorized()
        ->assertJson(['message' => 'API key missing.']);
});

test('a request with an invalid api key is rejected', function (array $headers) {
    ProjectApiKey::generate(Project::factory()->create(), 'Ingest Key');

    $this->getJson('/test-ingest', $headers)
        ->assertUnauthorized()
        ->assertJson(['message' => 'API key invalid.']);
})->with([
    'bearer' => [['Authorization' => 'Bearer bilis_invalid']],
    'custom header' => [['X-Bilis-Key' => 'bilis_invalid']],
]);

test('a request with a valid api key resolves the project', function (string $header) {
    $project = Project::factory()->create();
    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    $headers = $header === 'Authorization'
        ? ['Authorization' => 'Bearer '.$apiKey->plainTextKey]
        : ['X-Bilis-Key' => $apiKey->plainTextKey];

    $this->getJson('/test-ingest', $headers)
        ->assertOk()
        ->assertJson([
            'project_id' => $project->id,
            'api_key_id' => $apiKey->id,
        ]);

    expect($apiKey->fresh()->last_used_at)->not->toBeNull();
})->with(['Authorization', 'X-Bilis-Key']);

test('an api key for a deleted project is rejected', function () {
    $project = Project::factory()->create();
    $apiKey = ProjectApiKey::generate($project, 'Ingest Key');

    $project->delete();

    $this->getJson('/test-ingest', ['Authorization' => 'Bearer '.$apiKey->plainTextKey])
        ->assertUnauthorized();
});
