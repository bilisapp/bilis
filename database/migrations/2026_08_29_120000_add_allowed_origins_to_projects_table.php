<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The browser origins allowed to post to this project's ingest endpoints.
     *
     * A credential that runs in a browser is readable by anyone who opens the
     * page, so the origin is the only thing left that a stranger cannot
     * trivially forge: browsers set it themselves and refuse to let a script
     * change it. Null and the empty list both mean "no browser may post",
     * which is the right default for a project that only ships from servers —
     * a cross-origin request Bilis does not answer with a header never leaves
     * the browser.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('allowed_origins')->nullable()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('allowed_origins');
        });
    }
};
