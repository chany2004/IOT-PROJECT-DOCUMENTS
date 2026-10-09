<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fall_events', function (Blueprint $table) {
            $table->string('verification_status', 32)->default('not_required')->index();
            $table->text('verification_reason')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('caregiver_outcome', 32)->nullable();
        });
        Schema::table('camera_verifications', function (Blueprint $table) {
            $table->string('verdict', 20)->nullable();
            $table->timestampTz('verified_at')->nullable();
        });

        DB::table('fall_events')->where('event_type', 'possible_fall')->update([
            'verification_status' => 'inconclusive',
            'verification_reason' => 'Historical event imported without a structured camera verdict.',
            'verified_at' => now(),
        ]);

        DB::statement("ALTER TABLE fall_events ADD CONSTRAINT fall_events_verification_status_check CHECK (verification_status IN ('verifying', 'likely_fall', 'unlikely_fall', 'inconclusive', 'not_required'))");
        DB::statement("ALTER TABLE fall_events ADD CONSTRAINT fall_events_caregiver_outcome_check CHECK (caregiver_outcome IS NULL OR caregiver_outcome IN ('confirmed_fall', 'escalated', 'patient_safe', 'false_alarm'))");
        DB::statement("ALTER TABLE camera_verifications ADD CONSTRAINT camera_verifications_verdict_check CHECK (verdict IS NULL OR verdict IN ('fall', 'no_fall', 'uncertain'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE camera_verifications DROP CONSTRAINT IF EXISTS camera_verifications_verdict_check');
        DB::statement('ALTER TABLE fall_events DROP CONSTRAINT IF EXISTS fall_events_caregiver_outcome_check');
        DB::statement('ALTER TABLE fall_events DROP CONSTRAINT IF EXISTS fall_events_verification_status_check');
        Schema::table('camera_verifications', function (Blueprint $table) {
            $table->dropColumn(['verdict', 'verified_at']);
        });
        Schema::table('fall_events', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'verification_reason', 'verified_at', 'caregiver_outcome']);
        });
    }
};
