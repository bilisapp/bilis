<?php

use App\Services\Autofix\DiffValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $privatePem);

    config([
        'autofix.enabled' => true,
        'autofix.github.app_id' => '123456',
        'autofix.github.private_key' => base64_encode($privatePem),
    ]);
});

test('a diff that applies to the current head is valid', function () {
    fakeGitHubRepository(billingFiles());

    $job = ayosJob();
    $job->forceFill(['diff' => billingDiff(), 'report' => ['tests' => ['passed' => true]]])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isValid())->toBeTrue()
        ->and($result->applied()->headSha)->toBe('head1234567890')
        ->and($result->applied()->treeSha)->toBe('tree-of-head1234567890')
        ->and($result->applied()->paths())->toBe(['app/Billing.php'])
        ->and($result->applied()->changes[0]->content)->toBe("<?php\n\$total = \$order['total'] ?? 0;\nreturn \$total;\n")
        ->and($result->applied()->changes[0]->mode)->toBe('100644');
});

test('validation reads the repository with a read only token', function () {
    fakeGitHubRepository(billingFiles());

    $job = ayosJob();
    $job->forceFill(['diff' => billingDiff()])->save();

    app(DiffValidator::class)->validate($job);

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'access_tokens')) {
            return false;
        }

        expect($request->data()['permissions'])->toBe(['contents' => 'read']);

        return true;
    });
});

test('an executable file keeps its mode', function () {
    fakeGitHubRepository(['bin/run' => ['content' => "#!/bin/sh\necho old\n", 'mode' => '100755']]);

    $job = ayosJob();
    $job->forceFill(['diff' => "--- a/bin/run\n+++ b/bin/run\n@@ -1,2 +1,2 @@\n #!/bin/sh\n-echo old\n+echo new\n"])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isValid())->toBeTrue()
        ->and($result->applied()->changes[0]->mode)->toBe('100755');
});

test('a rename deletes the old path and writes the new one', function () {
    fakeGitHubRepository(['app/Old.php' => "<?php\nclass Old {}\n"]);

    $job = ayosJob();
    $job->forceFill(['diff' => <<<'DIFF'
    diff --git a/app/Old.php b/app/New.php
    similarity index 87%
    rename from app/Old.php
    rename to app/New.php
    --- a/app/Old.php
    +++ b/app/New.php
    @@ -1,2 +1,2 @@
     <?php
    -class Old {}
    +class NewClass {}
    DIFF])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isValid())->toBeTrue()
        ->and($result->applied()->paths())->toBe(['app/New.php', 'app/Old.php'])
        ->and($result->applied()->changes[1]->isDeletion())->toBeTrue();
});

test('an empty diff is rejected', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "   \n\n  "])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isRejected())->toBeTrue()
        ->and($result->reason)->toBe('empty_diff');

    Http::assertNothingSent();
});

test('a diff that cannot be parsed is rejected', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "here is my change, trust me\n"])->save();

    expect(app(DiffValidator::class)->validate($job)->reason)->toStartWith('unreadable_diff');
});

test('a diff touching .github is rejected', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "--- a/.github/workflows/ci.yml\n+++ b/.github/workflows/ci.yml\n@@ -1 +1 @@\n-on: push\n+on: [push, pull_request]\n"])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isRejected())->toBeTrue()
        ->and($result->reason)->toBe('denylisted_path: .github/workflows/ci.yml');
});

test('a diff smuggling .github behind a traversal is rejected', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "--- a/app/../.github/workflows/ci.yml\n+++ b/app/../.github/workflows/ci.yml\n@@ -1 +1 @@\n-on: push\n+on: [push]\n"])->save();

    expect(app(DiffValidator::class)->validate($job)->reason)->toBe('denylisted_path: .github/workflows/ci.yml');
});

test('a diff leaving the repository is rejected', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "--- a/../secrets.php\n+++ b/../secrets.php\n@@ -1 +1 @@\n-a\n+b\n"])->save();

    expect(app(DiffValidator::class)->validate($job)->reason)->toStartWith('path_traversal');
});

test('a diff touching an env file is rejected', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "--- a/.env.production\n+++ b/.env.production\n@@ -1 +1 @@\n-APP_DEBUG=false\n+APP_DEBUG=true\n"])->save();

    expect(app(DiffValidator::class)->validate($job)->reason)->toBe('denylisted_path: .env.production');
});

test('a rename away from a denylisted path is rejected on the old side', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "diff --git a/.env b/config/env.txt\nsimilarity index 100%\nrename from .env\nrename to config/env.txt\n"])->save();

    expect(app(DiffValidator::class)->validate($job)->reason)->toBe('denylisted_path: .env');
});

test('a configured denylist entry is enforced too', function () {
    config(['autofix.defaults.path_denylist' => ['.github/**', '.env*', 'infra/**']]);

    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "--- a/infra/terraform/main.tf\n+++ b/infra/terraform/main.tf\n@@ -1 +1 @@\n-a\n+b\n"])->save();

    expect(app(DiffValidator::class)->validate($job)->reason)->toBe('denylisted_path: infra/terraform/main.tf');
});

test('a diff over the line budget is rejected', function () {
    config(['autofix.defaults.max_diff_lines' => 3]);

    fakeGitHubRepository(billingFiles());

    $job = ayosJob();
    $job->forceFill(['diff' => "--- a/app/Billing.php\n+++ b/app/Billing.php\n@@ -1,3 +1,3 @@\n-a\n-b\n-c\n+x\n+y\n+z\n"])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isRejected())->toBeTrue()
        ->and($result->reason)->toBe('diff_too_large: 6 changed lines, limit 3');
});

test('a failing test run is rejected when a test command is configured', function () {
    fakeGitHubRepository(billingFiles());

    $job = ayosJob();
    $job->forceFill([
        'diff' => billingDiff(),
        'report' => ['tests' => ['cmd' => 'php artisan test', 'passed' => false]],
    ])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isRejected())->toBeTrue()
        ->and($result->reason)->toBe('tests_failed');
});

test('a failing test run is ignored when no test command is configured', function () {
    fakeGitHubRepository(billingFiles());

    $job = ayosJob(['test_cmd' => null]);
    $job->forceFill([
        'diff' => billingDiff(),
        'report' => ['tests' => ['passed' => false]],
    ])->save();

    expect(app(DiffValidator::class)->validate($job)->isValid())->toBeTrue();
});

test('a binary change is rejected', function () {
    fakeGitHubRepository();

    $job = ayosJob();
    $job->forceFill(['diff' => "diff --git a/logo.png b/logo.png\nindex 111..222 100644\nGIT binary patch\nliteral 8\nPcmZQzU|<4b\n"])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isRejected())->toBeTrue()
        ->and($result->reason)->toBe('binary_change: logo.png');
});

test('a diff that no longer applies asks for one re-dispatch', function () {
    fakeGitHubRepository(['app/Billing.php' => "<?php\n// somebody else fixed it\nreturn 0;\n"]);

    $job = ayosJob();
    $job->forceFill(['diff' => billingDiff()])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isRedispatch())->toBeTrue()
        ->and($result->reason)->toStartWith('stale_base');
});

test('a diff that no longer applies is rejected once it has been re-dispatched', function () {
    fakeGitHubRepository(['app/Billing.php' => "<?php\n// somebody else fixed it\nreturn 0;\n"]);

    $job = ayosJob();
    $job->forceFill(['diff' => billingDiff(), 'redispatch_count' => 1])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isRejected())->toBeTrue()
        ->and($result->reason)->toStartWith('diff_does_not_apply');
});

test('a head that has moved but still takes the patch is valid', function () {
    fakeGitHubRepository([
        'app/Billing.php' => "// an unrelated upstream line\n<?php\n\$total = \$order['total'];\nreturn \$total;\n",
    ]);

    $job = ayosJob();
    $job->forceFill(['diff' => billingDiff()])->save();

    $result = app(DiffValidator::class)->validate($job);

    expect($result->isValid())->toBeTrue()
        ->and($result->applied()->changes[0]->content)
        ->toBe("// an unrelated upstream line\n<?php\n\$total = \$order['total'] ?? 0;\nreturn \$total;\n");
});
