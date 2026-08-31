<?php

use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Services\Autofix\RunDriver;
use App\Services\Ingest\Protobuf\ProtobufReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeRunDriver;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A response's HTML with every run of whitespace collapsed to one space.
 *
 * Blade sources are formatted to be read, so a tag whose attributes do not
 * fit on one line gets wrapped — which is invisible to a browser and to a
 * crawler, but breaks a test that asserts on the whole tag as a literal
 * string. Assert against this instead: what matters is that the tag is there
 * carrying the right values, not how the source was laid out.
 */
function html(TestResponse $response): string
{
    return preg_replace('/\s+/', ' ', (string) $response->getContent()) ?? '';
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Decode the rows of a JSONEachRow body sent to the ClickHouse HTTP interface.
 *
 * @return array<int, array<string, mixed>>
 */
function insertedRows(Request $request): array
{
    $lines = array_filter(
        explode("\n", trim($request->body())),
        fn (string $line): bool => trim($line) !== '',
    );

    return array_map(
        fn (string $line): array => json_decode($line, true),
        array_values($lines),
    );
}

/**
 * Decode an OTLP export *response* from its protobuf encoding.
 *
 * The response schema is the same two fields for every signal, so this reads
 * both `ExportLogsServiceResponse` and `ExportTraceServiceResponse`:
 *
 *     { partial_success = 1 { rejected = 1 (varint), error_message = 2 (string) } }
 *
 * A complete success is zero bytes and comes back as an empty array — asserting
 * that is asserting a protobuf export was answered in protobuf, which is what
 * stops a spec-compliant client logging a parse error after every batch.
 *
 * @return array{rejected?: int, errorMessage?: string}
 */
function decodeOtlpResponse(string $body): array
{
    if ($body === '') {
        return [];
    }

    $reader = new ProtobufReader($body);
    $out = [];

    while (! $reader->atEnd()) {
        [$field, $wireType] = $reader->readTag();

        if ($field !== 1 || $wireType !== 2) {
            $reader->skip($wireType);

            continue;
        }

        $partial = $reader->readMessage();

        while (! $partial->atEnd()) {
            [$innerField, $innerWireType] = $partial->readTag();

            match ($innerField) {
                1 => $out['rejected'] = $partial->readVarint(),
                2 => $out['errorMessage'] = $partial->readLengthDelimited(),
                default => $partial->skip($innerWireType),
            };
        }
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| Autofix helpers
|--------------------------------------------------------------------------
|
| Shared by the autofix feature tests: a fully wired pending fix job, a fake
| for the two GitHub endpoints the dispatch path talks to, and a stand-in for
| the platform that starts runs.
|
| Note what is NOT here any more: an Ayos endpoint. Dispatching is starting a
| container, not calling a service, so there is no HTTP to fake — `fakeRuns()`
| replaces it.
|
*/

/**
 * A pending fix job with a repository, installation and error context.
 *
 * @param  array<string, mixed>  $repositoryAttributes
 */
function ayosJob(array $repositoryAttributes = []): FixJob
{
    $team = Team::factory()->create(['slug' => 'acme']);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->create(['team_id' => $team->id, 'installation_id' => 4242]);

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->create([
            'repo_full_name' => 'acme/app',
            'default_branch' => 'main',
            'test_cmd' => 'php artisan test --compact',
            ...$repositoryAttributes,
        ]);

    return FixJob::factory()->forRepository($repository)->create([
        'error_context' => [
            'fingerprint' => str_repeat('a', 64),
            'service_name' => 'checkout',
            'exception' => 'App\\Exceptions\\PaymentFailed',
            'message' => 'Charge declined for order 4821',
            'stack' => "App\\Exceptions\\PaymentFailed: Charge declined\n#0 /var/www/app/Billing.php(12): charge()",
            'count' => 9,
            'first_seen' => '2026-08-27 09:00:00.000000000',
            'last_seen' => '2026-08-27 09:59:00.000000000',
            'samples' => [
                ['timestamp' => '2026-08-27 09:59:00.000000000', 'severity' => 'ERROR', 'body' => 'Charge declined for order 4821'],
            ],
        ],
    ]);
}

/**
 * Fake GitHub's token exchange and commit lookup.
 */
function fakeAyos(): void
{
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_readonly']),
        'api.github.com/repos/acme/app/commits/main' => Http::response(['sha' => 'c0ffee1234567890']),
    ]);
}

/**
 * Whether a binary is on the PATH, for tests that shell out for real.
 */
function commandExists(string $binary): bool
{
    exec('command -v '.escapeshellarg($binary), $output, $status);

    return $status === 0;
}

/**
 * Bind a recording run driver, and hand it back so a test can inspect it.
 */
function fakeRuns(?FakeRunDriver $driver = null): FakeRunDriver
{
    $driver ??= new FakeRunDriver;

    app()->instance(RunDriver::class, $driver);

    return $driver;
}

/**
 * Fake the GitHub REST endpoints the autofix write path talks to.
 *
 * `$files` maps a repository path to its content, or to a
 * `['content' => ..., 'mode' => ...]` pair when the file mode matters. Reads
 * are answered from that map; writes answer with predictable shas so the call
 * sequence can be asserted.
 *
 * @param  array<string, mixed>  $files
 * @param  array<string, mixed>  $overrides
 */
function fakeGitHubRepository(array $files = [], array $overrides = [], string $head = 'head1234567890'): void
{
    $tree = [];
    $blobs = [];

    foreach ($files as $path => $spec) {
        $content = is_array($spec) ? (string) ($spec['content'] ?? '') : (string) $spec;
        $mode = is_array($spec) ? (string) ($spec['mode'] ?? '100644') : '100644';
        $sha = 'blob'.md5((string) $path);

        $tree[] = ['path' => (string) $path, 'type' => 'blob', 'mode' => $mode, 'sha' => $sha];
        $blobs[$sha] = $content;
    }

    $defaults = [
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_scoped']),
        'api.github.com/repos/acme/app/commits/*' => Http::response(['sha' => $head]),
        'api.github.com/repos/acme/app/git/trees/'.$head.'*' => Http::response([
            'sha' => 'tree-of-'.$head,
            'truncated' => false,
            'tree' => $tree,
        ]),
        'api.github.com/repos/acme/app/git/blobs/blob*' => function (Request $request) use ($blobs) {
            $sha = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return array_key_exists($sha, $blobs)
                ? Http::response(['content' => base64_encode($blobs[$sha]), 'encoding' => 'base64'])
                : Http::response(['message' => 'Not Found'], 404);
        },
        'api.github.com/repos/acme/app/contents/*' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/acme/app/git/blobs' => Http::response(['sha' => 'created-blob'], 201),
        'api.github.com/repos/acme/app/git/trees' => Http::response(['sha' => 'created-tree'], 201),
        'api.github.com/repos/acme/app/git/commits' => Http::response(['sha' => 'created-commit'], 201),
        'api.github.com/repos/acme/app/git/refs*' => Http::response(['ref' => 'refs/heads/autofix'], 201),
        'api.github.com/repos/acme/app/pulls*' => Http::response([
            'number' => 42,
            'html_url' => 'https://github.com/acme/app/pull/42',
        ], 201),
    ];

    /*
     * Overrides come first so they are matched first, and any default they
     * name is dropped rather than left to overwrite them.
     */
    foreach (array_keys($overrides) as $key) {
        unset($defaults[$key]);
    }

    Http::fake([...$overrides, ...$defaults]);
}

/**
 * A diff that turns the tracked `app/Billing.php` into a guarded version.
 */
function billingDiff(): string
{
    return <<<'DIFF'
    diff --git a/app/Billing.php b/app/Billing.php
    index 83db48f..bf269f4 100644
    --- a/app/Billing.php
    +++ b/app/Billing.php
    @@ -1,3 +1,3 @@
     <?php
    -$total = $order['total'];
    +$total = $order['total'] ?? 0;
     return $total;
    DIFF;
}

/**
 * The repository content `billingDiff()` expects to find.
 *
 * @return array<string, mixed>
 */
function billingFiles(): array
{
    return ['app/Billing.php' => "<?php\n\$total = \$order['total'];\nreturn \$total;\n"];
}

/**
 * A ClickHouse request body with its SQL comments stripped.
 *
 * The schema files carry long comments explaining which constructs are
 * forbidden and why, so a test asserting that a statement does NOT contain
 * `ReplacingMergeTree` would fail on the comment saying never to use it.
 */
function clickHouseStatement(Request $request): string
{
    return preg_replace('/^\s*--.*$/m', '', $request->body()) ?? '';
}

/**
 * Pull the query string of a ClickHouse request as an array.
 *
 * Shared because both the logs and the traces tests assert on bound
 * `param_*` values rather than on interpolated SQL.
 *
 * @return array<string, string>
 */
function clickHouseQuery(Request $request): array
{
    $query = [];
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}
