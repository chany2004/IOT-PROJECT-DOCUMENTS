<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', fn (Blueprint $t) => $t->timestampTz('archived_at')->nullable()->index());
        Schema::table('rooms', fn (Blueprint $t) => $t->timestampTz('archived_at')->nullable()->index());

        // Preserve provenance and history. Never relabel a synthetic record as a real patient event.
        DB::table('devices')->where('is_simulated', true)->update(['is_active' => false, 'updated_at' => now()]);
        $legacyPatients = DB::table('patients as p')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('device_assignments as a')
                ->join('devices as d', 'd.id', '=', 'a.device_id')->whereColumn('a.patient_id', 'p.id')->where('d.is_simulated', true))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('device_assignments as a')
                ->join('devices as d', 'd.id', '=', 'a.device_id')->whereColumn('a.patient_id', 'p.id')->where('d.is_simulated', false))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fall_events as e')
                ->whereColumn('e.patient_id', 'p.id')->where('e.is_simulated', false))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sensor_readings as r')
                ->whereColumn('r.patient_id', 'p.id')->where('r.is_simulated', false))
            ->pluck('p.id');
        $legacyRooms = DB::table('patients')->whereIn('id', $legacyPatients)->whereNotNull('room_id')->pluck('room_id');
        DB::table('patients')->whereIn('id', $legacyPatients)->update(['is_active' => false, 'archived_at' => now(), 'updated_at' => now()]);
        DB::table('rooms')->whereIn('id', $legacyRooms)->whereNotIn('id',
            DB::table('fall_events')->where('is_simulated', false)->whereNotNull('room_id')->select('room_id'))
            ->update(['archived_at' => now(), 'updated_at' => now()]);
        // Only rename the known bootstrap label; preserve real user names and all passwords.
        DB::table('users')->where('email', 'caregiver@fallmonitor.local')->where('name', 'Demo Caregiver')
            ->update(['name' => 'Caregiver', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('archived_at'));
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('archived_at'));
        // Do not reactivate old devices or overwrite account names on rollback.
    }
};
