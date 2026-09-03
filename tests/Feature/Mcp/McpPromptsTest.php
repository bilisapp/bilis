<?php

use App\Mcp\Prompts\DebugWithBilisPrompt;
use App\Mcp\Prompts\InstrumentWithBilisPrompt;
use App\Mcp\Servers\BilisServer;
use App\Models\User;

test('instrument-with-bilis points at the guide rather than restating it', function () {
    $user = User::factory()->create();

    BilisServer::actingAs($user)
        ->prompt(InstrumentWithBilisPrompt::class, ['guide' => 'claude-code'])
        ->assertOk()
        // The raw markdown URL, so the agent reads the current guide, not a copy.
        ->assertSee(url('/docs/ingestion/claude-code.md'))
        ->assertSee('bilis_YOUR_API_KEY')
        // This server cannot mint a key, and the prompt has to say where one comes from.
        ->assertSee('web app');
});

test('an unknown guide falls back to the endpoint contract instead of failing', function () {
    $user = User::factory()->create();

    BilisServer::actingAs($user)
        ->prompt(InstrumentWithBilisPrompt::class, ['guide' => 'cobol'])
        ->assertOk()
        ->assertSee(url('/docs/ingestion/endpoints.md'));
});

test('debug-with-bilis carries the symptom into the plan', function () {
    $user = User::factory()->create();

    BilisServer::actingAs($user)
        ->prompt(DebugWithBilisPrompt::class, ['symptom' => 'checkout 500s since the deploy'])
        ->assertOk()
        ->assertSee('checkout 500s since the deploy')
        ->assertSee('error-summary')
        ->assertSee('get-trace');
});
