<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A diff that no longer applies is usually a branch that moved while the
     * agent worked, so the job earns one fresh run against a new `base_sha`
     * before it is rejected. The counter is what keeps "one" from becoming a
     * loop between the validator and the dispatcher.
     */
    public function up(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('redispatch_count')->default(0)->after('base_sha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->dropColumn('redispatch_count');
        });
    }
};
