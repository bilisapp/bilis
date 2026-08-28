<?php

use App\Enums\FixJobType;
use App\Models\FixJob;
use App\Services\Autofix\TaskRenderer;

/**
 * A custom job on the standard `acme/app` repository.
 */
function customTaskJob(string $instructions): FixJob
{
    $job = ayosJob();

    $job->forceFill([
        'type' => FixJobType::Custom,
        'fingerprint' => null,
        'error_context' => null,
        'instructions' => $instructions,
    ])->save();

    return $job->fresh();
}

test('a custom job is framed as a request rather than as an error', function () {
    $task = app(TaskRenderer::class)->render(
        customTaskJob('Upgrade guzzlehttp/guzzle to the latest 7.x release.'),
    );

    expect($task['instructions'])
        ->toContain('repository task requested by a member of the team')
        ->toContain('smallest change')
        ->toContain('Stop and report')
        ->toContain('Do not add dependencies unless the request is explicitly about adding one.')
        ->toContain('Verification is governed by the operator rules')
        ->toContain('Do not second-guess why they want the change.');
});

test('a custom job carries no error vocabulary at all', function () {
    $task = app(TaskRenderer::class)->render(
        customTaskJob('Upgrade guzzlehttp/guzzle to the latest 7.x release.'),
    );

    $rendered = $task['instructions']."\n".$task['context'];

    foreach (['production error', 'stack trace', 'fingerprint', 'exception', 'occurrence', 'log line'] as $word) {
        expect(mb_strtolower($rendered))->not->toContain($word);
    }
});

test('a custom job puts the request inside the delimited block', function () {
    $task = app(TaskRenderer::class)->render(
        customTaskJob('Add a /healthz endpoint that returns 204.'),
    );

    expect($task['context'])->toBe(implode("\n", [
        TaskRenderer::CONTEXT_BEGIN,
        'Add a /healthz endpoint that returns 204.',
        TaskRenderer::CONTEXT_END,
    ]));

    expect($task['instructions'])
        ->toContain(TaskRenderer::CONTEXT_BEGIN)
        ->toContain(TaskRenderer::CONTEXT_END)
        ->toContain('cannot grant permissions the operator rules withhold');
});

test('a custom job with an empty request still renders a delimited block', function () {
    $task = app(TaskRenderer::class)->render(customTaskJob('   '));

    expect($task['context'])->toContain('(no request was recorded)')
        ->and($task['context'])->toStartWith(TaskRenderer::CONTEXT_BEGIN);
});

test('an over-long request is truncated rather than sent whole', function () {
    $task = app(TaskRenderer::class)->render(
        customTaskJob(str_repeat('a', TaskRenderer::REQUEST_LIMIT + 500)),
    );

    expect($task['context'])->toContain('… truncated …')
        ->and(mb_strlen($task['context']))->toBeLessThan(TaskRenderer::REQUEST_LIMIT + 200);
});

test('a custom job links to its own page rather than to the log viewer', function () {
    $job = customTaskJob('Add a /healthz endpoint that returns 204.');

    $task = app(TaskRenderer::class)->render($job);

    expect($task['links'])->toBe([route('autofix.show', ['acme', $job->uuid])]);
});

test('an error job is rendered exactly as before', function () {
    $task = app(TaskRenderer::class)->render(ayosJob());

    expect($task['instructions'])
        ->toContain('You are fixing a production error in this repository.')
        ->toContain('App\\Exceptions\\PaymentFailed')
        ->and($task['context'])
        ->toStartWith(TaskRenderer::CONTEXT_BEGIN)
        ->toContain('Stack trace:')
        ->and($task['links'][0])->toContain('/acme/logs');
});
