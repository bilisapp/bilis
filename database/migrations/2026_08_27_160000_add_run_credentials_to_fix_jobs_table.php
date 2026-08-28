<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ayos stopped being a service. One fix job is now one container run with
     * no inbound HTTP, which changes what a job row has to remember about it.
     *
     * `ayos_public_key` replaces the shared secret: Bilis mints an Ed25519
     * keypair per job, injects the private half into that one run, and keeps
     * the public half here. Everything the run posts back is verified against
     * this column and nothing else — a key recovered from a compromised run
     * authenticates exactly one job, which is already over.
     *
     * `ayos_run_id` is the only handle on a run once it has started: it is what
     * cancellation stops, and what reconciliation asks about when a run dies
     * without ever reporting.
     */
    public function up(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->string('ayos_public_key')->nullable()->after('base_sha');
            $table->string('ayos_run_id')->nullable()->after('ayos_public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->dropColumn(['ayos_public_key', 'ayos_run_id']);
        });
    }
};
