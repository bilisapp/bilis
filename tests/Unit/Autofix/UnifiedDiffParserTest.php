<?php

use App\Services\Autofix\DiffParseException;
use App\Services\Autofix\UnifiedDiffParser;

function parseDiff(string $diff): array
{
    return (new UnifiedDiffParser)->parse($diff);
}

test('it parses a single file edit with one hunk', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/app/Billing.php b/app/Billing.php
    index 83db48f..bf269f4 100644
    --- a/app/Billing.php
    +++ b/app/Billing.php
    @@ -1,3 +1,3 @@
     <?php
    -$total = $order['total'];
    +$total = $order['total'] ?? 0;
     return $total;
    DIFF);

    expect($files)->toHaveCount(1);

    $file = $files[0];

    expect($file->oldPath)->toBe('app/Billing.php')
        ->and($file->newPath)->toBe('app/Billing.php')
        ->and($file->isNew)->toBeFalse()
        ->and($file->isDeleted)->toBeFalse()
        ->and($file->isBinary)->toBeFalse()
        ->and($file->hunks)->toHaveCount(1)
        ->and($file->changedLines())->toBe(2)
        ->and($file->hunks[0]->oldStart)->toBe(1)
        ->and($file->hunks[0]->oldLines())->toBe(['<?php', "\$total = \$order['total'];", 'return $total;']);
});

test('it parses a new file', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/app/Guard.php b/app/Guard.php
    new file mode 100755
    index 0000000..e69de29
    --- /dev/null
    +++ b/app/Guard.php
    @@ -0,0 +1,2 @@
    +<?php
    +// guard
    DIFF);

    expect($files[0]->isNew)->toBeTrue()
        ->and($files[0]->oldPath)->toBeNull()
        ->and($files[0]->newPath)->toBe('app/Guard.php')
        ->and($files[0]->mode)->toBe('100755')
        ->and($files[0]->changedLines())->toBe(2);
});

test('it parses a deletion', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/legacy.php b/legacy.php
    deleted file mode 100644
    index e69de29..0000000
    --- a/legacy.php
    +++ /dev/null
    @@ -1,2 +0,0 @@
    -<?php
    -// gone
    DIFF);

    expect($files[0]->isDeleted)->toBeTrue()
        ->and($files[0]->newPath)->toBeNull()
        ->and($files[0]->oldPath)->toBe('legacy.php')
        ->and($files[0]->paths())->toBe(['legacy.php']);
});

test('it parses a rename with a hunk', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/app/Old.php b/app/New.php
    similarity index 87%
    rename from app/Old.php
    rename to app/New.php
    index 83db48f..bf269f4 100644
    --- a/app/Old.php
    +++ b/app/New.php
    @@ -1,2 +1,2 @@
     <?php
    -class Old {}
    +class NewClass {}
    DIFF);

    expect($files[0]->isRename)->toBeTrue()
        ->and($files[0]->oldPath)->toBe('app/Old.php')
        ->and($files[0]->newPath)->toBe('app/New.php')
        ->and($files[0]->paths())->toBe(['app/Old.php', 'app/New.php']);
});

test('it parses a pure rename with no hunks', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/a.txt b/b.txt
    similarity index 100%
    rename from a.txt
    rename to b.txt
    DIFF);

    expect($files[0]->isRename)->toBeTrue()
        ->and($files[0]->hunks)->toBe([])
        ->and($files[0]->changedLines())->toBe(0);
});

test('it parses multiple hunks across multiple files', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/one.txt b/one.txt
    --- a/one.txt
    +++ b/one.txt
    @@ -1,2 +1,2 @@
     alpha
    -beta
    +BETA
    @@ -10,2 +10,3 @@
     kilo
    +lima
     mike
    diff --git a/two.txt b/two.txt
    --- a/two.txt
    +++ b/two.txt
    @@ -1 +1 @@
    -old
    +new
    DIFF);

    expect($files)->toHaveCount(2)
        ->and($files[0]->hunks)->toHaveCount(2)
        ->and($files[0]->hunks[1]->newStart)->toBe(10)
        ->and($files[0]->changedLines())->toBe(3)
        ->and($files[1]->hunks[0]->oldCount)->toBe(1)
        ->and($files[1]->hunks[0]->newLines())->toBe(['new']);
});

test('it flags a git binary patch', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/logo.png b/logo.png
    index 0000000..1234567 100644
    GIT binary patch
    literal 8
    PcmZQzU|<4b0055*0AB
    DIFF);

    expect($files[0]->isBinary)->toBeTrue();
});

test('it flags a binary files differ marker', function () {
    $files = parseDiff(<<<'DIFF'
    diff --git a/logo.png b/logo.png
    index 1111111..2222222 100644
    Binary files a/logo.png and b/logo.png differ
    DIFF);

    expect($files[0]->isBinary)->toBeTrue();
});

test('it records a missing trailing newline', function () {
    $files = parseDiff("diff --git a/x.txt b/x.txt\n--- a/x.txt\n+++ b/x.txt\n@@ -1 +1 @@\n-old\n+new\n\\ No newline at end of file\n");

    expect($files[0]->hunks[0]->newSideEndsWithoutNewline())->toBeTrue();
});

test('it keeps a trailing newline when only the old side lost one', function () {
    $files = parseDiff("diff --git a/x.txt b/x.txt\n--- a/x.txt\n+++ b/x.txt\n@@ -1 +1 @@\n-old\n\\ No newline at end of file\n+new\n");

    expect($files[0]->hunks[0]->newSideEndsWithoutNewline())->toBeFalse();
});

test('it parses a patch with no diff --git header', function () {
    $files = parseDiff("--- a/x.txt\n+++ b/x.txt\n@@ -1 +1 @@\n-old\n+new\n");

    expect($files)->toHaveCount(1)
        ->and($files[0]->newPath)->toBe('x.txt');
});

test('it refuses a diff with no file changes', function () {
    parseDiff("no diff here\njust prose\n");
})->throws(DiffParseException::class);

test('it refuses a hunk whose body does not match its header', function () {
    parseDiff("--- a/x.txt\n+++ b/x.txt\n@@ -1,5 +1,5 @@\n-old\n+new\n");
})->throws(DiffParseException::class);

test('it refuses an unreadable hunk header', function () {
    parseDiff("--- a/x.txt\n+++ b/x.txt\n@@ nonsense @@\n-old\n+new\n");
})->throws(DiffParseException::class);
