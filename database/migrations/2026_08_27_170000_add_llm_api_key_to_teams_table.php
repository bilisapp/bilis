<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bring-your-own-key, per team.
     *
     * The model credential is the one thing in a job spec that can cost money
     * rather than merely propose a patch, and a run's environment is readable
     * from the platform's console for as long as the run record is retained.
     * A key scoped to one customer bounds that to one customer's budget; a
     * shared organisation key would not, which is why there is a column here
     * rather than a single value in config.
     *
     * `text` rather than `string`: the value is encrypted at rest with the
     * application key, and a ciphertext is several times the length of the
     * token it hides.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->text('llm_api_key')->nullable()->after('is_personal');
            $table->string('llm_api_key_hint', 8)->nullable()->after('llm_api_key');
            $table->timestamp('llm_api_key_set_at')->nullable()->after('llm_api_key_hint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['llm_api_key', 'llm_api_key_hint', 'llm_api_key_set_at']);
        });
    }
};
