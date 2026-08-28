<?php

use App\Enums\LlmProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A team held one Anthropic key. It now holds as many as it likes, one row
     * each, and every job records which one it ran on.
     *
     * The reason for a table rather than three more columns on `teams` is the
     * reason there was a column at all: the model credential is the one thing
     * in a job spec that can cost money, and what bounds the damage is that a
     * key belongs to one customer with a budget at one provider. A customer who
     * wants an OpenRouter key for experiments and an Anthropic key for the work
     * that matters is asking for exactly that boundary, twice.
     *
     * `api_key` is `text` because the value is encrypted at rest with the
     * application key and a ciphertext is several times the length of the token
     * it hides. `hint` is the last four characters, kept in the clear so the
     * settings page can name a key without ever decrypting one.
     *
     * Exactly one credential per team carries `is_default`. That is enforced in
     * `TeamLlmCredential::makeDefault()` rather than by a partial unique index,
     * which SQLite and MySQL spell differently and neither spells well.
     */
    public function up(): void
    {
        Schema::create('team_llm_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('label');
            $table->text('api_key');
            $table->string('hint', 8)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Every read is "this team's credentials", and the picker and the
            // scan both want the default first.
            $table->index(['team_id', 'is_default']);
        });

        $this->carryExistingKeysOver();

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['llm_api_key', 'llm_api_key_hint', 'llm_api_key_set_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * The columns come back empty. Reversing this cannot recover a key the
     * ciphertext for which now lives in a dropped table, and pretending
     * otherwise would be worse than saying so.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->text('llm_api_key')->nullable()->after('is_personal');
            $table->string('llm_api_key_hint', 8)->nullable()->after('llm_api_key');
            $table->timestamp('llm_api_key_set_at')->nullable()->after('llm_api_key_hint');
        });

        Schema::dropIfExists('team_llm_credentials');
    }

    /**
     * Move each team's existing key into a credential row.
     *
     * The ciphertext is moved verbatim rather than decrypted and re-encrypted:
     * both columns are encrypted with the same application key and the same
     * cast, so the value the model reads back is identical, and the plaintext
     * never has to exist in a migration's memory to get there.
     */
    private function carryExistingKeysOver(): void
    {
        if (! Schema::hasColumn('teams', 'llm_api_key')) {
            return;
        }

        DB::table('teams')
            ->whereNotNull('llm_api_key')
            ->orderBy('id')
            ->each(function (object $team): void {
                DB::table('team_llm_credentials')->insert([
                    'team_id' => $team->id,
                    'provider' => LlmProvider::Anthropic->value,
                    'label' => 'Anthropic',
                    'api_key' => $team->llm_api_key,
                    'hint' => $team->llm_api_key_hint,
                    'is_default' => true,
                    'last_used_at' => null,
                    'created_at' => $team->llm_api_key_set_at ?? now(),
                    'updated_at' => now(),
                ]);
            });
    }
};
