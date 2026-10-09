<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('watcher_telegram_enabled')->default(false);
            $table->string('watcher_telegram_chat_id', 20)->nullable();
            $table->string('watcher_telegram_bot_id', 20)->nullable();
            $table->string('watcher_telegram_username', 64)->nullable();
            $table->timestampTz('watcher_telegram_linked_at')->nullable();
        });
        Schema::create('watcher_telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('bot_id', 20);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('watcher_telegram_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fall_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->string('contact_name', 150)->nullable();
            $table->string('chat_id', 20)->nullable();
            $table->string('bot_id', 20)->nullable();
            $table->text('message');
            $table->string('status', 24)->default('pending')->index();
            $table->text('status_reason')->nullable();
            $table->string('telegram_message_id', 30)->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('telegram_bot_cursors', function (Blueprint $table) {
            $table->string('bot_id', 20)->primary();
            $table->unsignedBigInteger('update_offset')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_cursors');
        Schema::dropIfExists('watcher_telegram_notifications');
        Schema::dropIfExists('watcher_telegram_links');
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn([
            'watcher_telegram_enabled', 'watcher_telegram_chat_id', 'watcher_telegram_bot_id',
            'watcher_telegram_username', 'watcher_telegram_linked_at',
        ]));
    }
};
