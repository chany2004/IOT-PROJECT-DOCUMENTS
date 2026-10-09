<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LocalSetup extends Command
{
    protected $signature = 'monitor:local-setup';
    protected $description = 'Create initial local administrator and caregiver credentials';
    public function handle(): int
    {
        if (!app()->environment('local')) { $this->error('Local environment only.'); return self::FAILURE; }
        if (User::exists()) { $this->info('Existing users preserved; initial setup skipped.'); return self::SUCCESS; }
        $adminPassword = Str::random(24);
        $carePassword = Str::random(24);
        $admin = User::create(['name' => 'System Administrator', 'email' => 'admin@fallmonitor.local',
            'password' => $adminPassword, 'role' => 'administrator', 'is_active' => true]);
        $caregiver = User::create(['name' => 'Caregiver', 'email' => 'caregiver@fallmonitor.local',
            'password' => $carePassword, 'role' => 'caregiver', 'is_active' => true]);
        $file = base_path('.runtime/local-access.txt');
        file_put_contents($file, "PATIENT WATCH ACCESS\nURL: http://127.0.0.1:8085\n\nAdministrator\nEmail: admin@fallmonitor.local\nPassword: ".$adminPassword.
            "\n\nCaregiver\nEmail: caregiver@fallmonitor.local\nPassword: ".$carePassword.
            "\n\nRegister a physical device in Connected devices to generate its one-time X-Device-Key.\nChange passwords from Account after first login. Keep this file private.\n");
        $this->info('Local accounts created. Credentials are in .runtime/local-access.txt.');
        return self::SUCCESS;
    }
}
