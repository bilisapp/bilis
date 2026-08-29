<?php

use App\Models\ProjectApiKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The public half of the credential, stored in plaintext on purpose.
     *
     * A secret key is shown once and kept as a sha256 because knowing it is
     * the whole authorisation. A public key is the opposite: it is meant to be
     * pasted into a DSN, which lands in a container environment, a deploy
     * config and — in a browser — the page source. It has to be readable from
     * the UI forever, so hashing it would only make the DSN unrecoverable
     * without buying any secrecy the DSN does not already give away.
     */
    public function up(): void
    {
        Schema::table('project_api_keys', function (Blueprint $table) {
            $table->string('public_key', 64)->nullable()->unique()->after('key_hash');
        });

        // Every existing key gets its public half, so a project that predates
        // this migration has a working DSN without being re-issued.
        DB::table('project_api_keys')->whereNull('public_key')->orderBy('id')->each(function (object $key): void {
            DB::table('project_api_keys')
                ->where('id', $key->id)
                ->update(['public_key' => ProjectApiKey::PUBLIC_KEY_PREFIX.Str::random(ProjectApiKey::RANDOM_LENGTH)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_api_keys', function (Blueprint $table) {
            $table->dropUnique(['public_key']);
            $table->dropColumn('public_key');
        });
    }
};
