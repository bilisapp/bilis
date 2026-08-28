<?php

use App\Services\Autofix\DiffApplier;
use App\Services\Autofix\DiffApplyException;
use App\Services\Autofix\UnifiedDiffParser;

function applyDiff(?string $original, string $diff): ?string
{
    $files = (new UnifiedDiffParser)->parse($diff);

    return (new DiffApplier)->apply($original, $files[0]);
}

test('it applies a hunk at the position it claims', function () {
    $original = "alpha\nbeta\ngamma\n";

    $result = applyDiff($original, "--- a/x.txt\n+++ b/x.txt\n@@ -1,3 +1,3 @@\n alpha\n-beta\n+BETA\n gamma\n");

    expect($result)->toBe("alpha\nBETA\ngamma\n");
});

test('it finds a hunk that has drifted because the branch moved', function () {
    $original = "header\nadded upstream\nalpha\nbeta\ngamma\n";

    $result = applyDiff($original, "--- a/x.txt\n+++ b/x.txt\n@@ -1,3 +1,3 @@\n alpha\n-beta\n+BETA\n gamma\n");

    expect($result)->toBe("header\nadded upstream\nalpha\nBETA\ngamma\n");
});

test('it refuses a hunk whose context is gone', function () {
    applyDiff("alpha\nsomething else\ngamma\n", "--- a/x.txt\n+++ b/x.txt\n@@ -1,3 +1,3 @@\n alpha\n-beta\n+BETA\n gamma\n");
})->throws(DiffApplyException::class);

test('it creates a new file', function () {
    $result = applyDiff(null, "diff --git a/n.txt b/n.txt\nnew file mode 100644\n--- /dev/null\n+++ b/n.txt\n@@ -0,0 +1,2 @@\n+one\n+two\n");

    expect($result)->toBe("one\ntwo\n");
});

test('it refuses to create a file that already exists', function () {
    applyDiff("taken\n", "diff --git a/n.txt b/n.txt\nnew file mode 100644\n--- /dev/null\n+++ b/n.txt\n@@ -0,0 +1,1 @@\n+one\n");
})->throws(DiffApplyException::class);

test('it deletes a file', function () {
    $result = applyDiff("gone\n", "diff --git a/d.txt b/d.txt\ndeleted file mode 100644\n--- a/d.txt\n+++ /dev/null\n@@ -1 +0,0 @@\n-gone\n");

    expect($result)->toBeNull();
});

test('it refuses to delete a file that is not there', function () {
    applyDiff(null, "diff --git a/d.txt b/d.txt\ndeleted file mode 100644\n--- a/d.txt\n+++ /dev/null\n@@ -1 +0,0 @@\n-gone\n");
})->throws(DiffApplyException::class);

test('it applies several hunks and keeps the shift straight', function () {
    $original = implode("\n", ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])."\n";

    $result = applyDiff($original, "--- a/x.txt\n+++ b/x.txt\n@@ -1,2 +1,3 @@\n a\n+a2\n b\n@@ -6,2 +7,1 @@\n f\n-g\n");

    expect($result)->toBe("a\na2\nb\nc\nd\ne\nf\nh\n");
});

test('it honours a missing trailing newline', function () {
    $result = applyDiff("old\n", "--- a/x.txt\n+++ b/x.txt\n@@ -1 +1 @@\n-old\n+new\n\\ No newline at end of file\n");

    expect($result)->toBe('new');
});

test('it keeps the trailing newline the original had', function () {
    $result = applyDiff('old', "--- a/x.txt\n+++ b/x.txt\n@@ -1 +1 @@\n-old\n\\ No newline at end of file\n+new\n");

    expect($result)->toBe("new\n");
});

test('it leaves content alone for a pure rename', function () {
    $result = applyDiff("body\n", "diff --git a/a.txt b/b.txt\nsimilarity index 100%\nrename from a.txt\nrename to b.txt\n");

    expect($result)->toBe("body\n");
});
