<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 16)->nullable();
            $table->string('emergency_contact_relationship', 80)->nullable();
            $table->boolean('emergency_sms_enabled')->default(false);
        });

        Schema::create('watcher_sms_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fall_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_phone', 16)->nullable();
            $table->text('message');
            $table->string('status', 24)->default('pending')->index();
            $table->string('provider_message_id', 100)->nullable();
            $table->string('provider_status', 24)->nullable();
            $table->text('status_reason')->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watcher_sms_notifications');
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn([
            'emergency_contact_name', 'emergency_contact_phone',
            'emergency_contact_relationship', 'emergency_sms_enabled',
        ]));
    }
};
