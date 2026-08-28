<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `failure_reason` was `string` — `varchar(255)` — while every writer
     * truncates to 1000. The two never agreed, and nothing noticed because the
     * development and test database is SQLite, which does not enforce a
     * `varchar` length at all. Postgres does, so the mismatch surfaced only in
     * production, as `SQLSTATE[22001] value too long`.
     *
     * It surfaced in the worst possible place: the failure handler. A dispatch
     * that failed with a long message — an upstream API returning a JSON error
     * body, which is exactly when the message is long — could not record why.
     * The write threw, the queue job failed, and it retried eight times before
     * giving up, leaving the row in the state it was already in.
     *
     * `text` because a failure reason is diagnostic prose quoting an upstream
     * response. There is no length that is obviously enough, and the writers'
     * 1000-character cap is the real bound.
     */
    public function up(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately not narrowing back to `varchar(255)`: on Postgres that would
     * fail outright on any row this migration made possible, and on the rows it
     * did not it would silently re-introduce the bug.
     */
    public function down(): void
    {
        // Nothing to do.
    }
};
