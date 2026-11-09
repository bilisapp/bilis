<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where a team's alerts go: email, a Slack incoming webhook or a signed
     * generic webhook. `config` holds addresses, URLs and the `whsec_` secret,
     * so it is a text column written through the `encrypted:array` cast.
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name');
            $table->text('config');
            $table->boolean('enabled')->default(true);
            $table->boolean('issue_events')->default(false);
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
