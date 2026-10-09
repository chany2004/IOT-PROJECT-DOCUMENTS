<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->enum('role', ['administrator', 'caregiver'])->default('caregiver');
            $t->boolean('is_active')->default(true);
        });
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('number')->unique();
            $t->string('floor')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
        });
        Schema::create('patients', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('full_name');
            $t->date('birth_date')->nullable();
            $t->string('gender')->nullable();
            $t->text('medical_notes')->nullable();
            $t->string('emergency_contact')->nullable();
            $t->foreignId('room_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('caregiver_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->enum('type', ['esp32', 'esp32_cam']);
            $t->char('key_hash', 64)->unique();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_simulated')->default(false);
            $t->ipAddress('camera_ip')->nullable();
            $t->unsignedSmallInteger('camera_port')->default(80);
            $t->text('camera_key')->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->boolean('sensor_ok')->nullable();
            $t->timestampsTz();
        });
        Schema::create('device_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->enum('device_type', ['esp32', 'esp32_cam']);
            $t->timestampTz('assigned_at');
            $t->timestampTz('unassigned_at')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX assignments_device_current ON device_assignments (device_id) WHERE unassigned_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX assignments_patient_type_current ON device_assignments (patient_id, device_type) WHERE unassigned_at IS NULL');
        Schema::create('sensor_readings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->double('acceleration_g');
            $t->double('gyro_rad_s');
            $t->jsonb('axes')->nullable();
            $t->boolean('is_simulated');
            $t->timestampTz('recorded_at')->index();
        });
        Schema::create('fall_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('event_key', 128);
            $t->char('payload_hash', 64);
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('reading_id')->nullable()->constrained('sensor_readings')->restrictOnDelete();
            $t->enum('event_type', ['possible_fall', 'manual_emergency']);
            $t->enum('status', ['pending', 'acknowledged', 'resolved', 'false_alarm'])->default('pending')->index();
            $t->boolean('is_simulated');
            $t->timestampTz('detected_at')->index();
            $t->timestampTz('received_at');
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('acknowledged_at')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->unique(['device_id', 'event_key']);
            $t->index(['patient_id', 'detected_at']);
        });
        DB::statement("ALTER TABLE fall_events ADD CONSTRAINT fall_requires_reading CHECK (event_type <> 'possible_fall' OR reading_id IS NOT NULL)");
        Schema::create('camera_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fall_event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('camera_device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $t->enum('status', ['pending', 'captured', 'timeout', 'unavailable', 'simulated'])->default('pending');
            $t->boolean('is_simulated');
            $t->string('image_path')->nullable();
            $t->decimal('confidence', 5, 4)->nullable();
            $t->text('result')->nullable();
            $t->timestampTz('captured_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE camera_verifications ADD CONSTRAINT simulated_camera_has_no_evidence CHECK (NOT is_simulated OR (image_path IS NULL AND confidence IS NULL))');
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fall_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('channel')->default('dashboard');
            $t->boolean('is_simulated');
            $t->timestampTz('created_at');
            $t->timestampTz('seen_at')->nullable();
            $t->unique(['fall_event_id', 'user_id', 'channel']);
        });
        Schema::create('event_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fall_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('from_status');
            $t->string('to_status');
            $t->text('note');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['event_updates', 'notification_deliveries', 'camera_verifications', 'fall_events', 'sensor_readings', 'device_assignments', 'devices', 'patients', 'rooms'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'is_active']));
    }
};
