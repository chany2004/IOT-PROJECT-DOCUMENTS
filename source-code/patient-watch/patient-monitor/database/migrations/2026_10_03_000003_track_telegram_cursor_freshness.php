<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_bot_cursors', fn (Blueprint $table) => $table->timestampTz('last_update_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('telegram_bot_cursors', fn (Blueprint $table) => $table->dropColumn('last_update_at'));
    }
};
