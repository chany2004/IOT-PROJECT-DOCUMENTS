<?php
namespace App\Http\Controllers;

use App\Models\{Device, DeviceAssignment, Patient, Room, User};
use App\Services\WatcherTelegramService;
use App\Support\PhilippineMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ManagementController extends Controller
{
    public function patients(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = Patient::visibleTo($request->user())->with(['room', 'caregiver', 'currentAssignments.device']);
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            // Group matches so room, caregiver, and device searches cannot
            // return patients outside the signed-in user's care assignments.
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw("LOWER(full_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('room', fn ($query) =>
                        $query->whereRaw("LOWER(CAST(number AS TEXT)) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('caregiver', fn ($query) =>
                        $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('currentAssignments.device', fn ($query) =>
                        $query->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        $patients = $query->orderBy('full_name')->paginate(20)->withQueryString();
        if ($request->user()->isAdmin()) {
            $newPatient = new Patient(['is_active' => true]);
            $newPatient->setRelation('currentAssignments', collect());
            return view('management.patients', ['patients' => $patients, 'newPatient' => $newPatient]
                + $this->patientFormOptions($newPatient));
        }
        return view('management.patients', compact('patients'));
    }
    public function patient(Request $request, Patient $patient)
    {
        abort_unless(Patient::visibleTo($request->user())->whereKey($patient->id)->exists(), 404);
        $patient->load(['room', 'caregiver', 'currentAssignments.device']);
        $events = $patient->events()->with(['camera', 'device'])->orderByDesc('detected_at')->limit(10)->get();
        $readings = $patient->readings()->orderByDesc('recorded_at')->limit(20)->get();
        $assignableDevices = $request->user()->isAdmin()
            ? Device::where('is_simulated', false)->where('is_active', true)
                ->with('currentAssignment.patient')->orderBy('code')->get()
            : collect();
        return view('management.patient', compact('patient', 'events', 'readings', 'assignableDevices'));
    }

    public function patientForm(?Patient $patient = null)
    {
        $patient ??= new Patient(['is_active' => true]);
        $patient->load(['currentAssignments.device']);
        return view('management.patient-form', ['patient' => $patient] + $this->patientFormOptions($patient));
    }
    private function patientFormOptions(Patient $patient): array
    {
        $rooms = Room::whereDoesntHave('patient')->orWhere('id', $patient->room_id)->orderBy('number')->get();
        $caregivers = User::where('role', 'caregiver')->where('is_active', true)->orderBy('name')->get();
        $devices = Device::where('is_simulated', false)->where('is_active', true)
            ->with('currentAssignment.patient')->orderBy('code')->get();
        return compact('rooms', 'caregivers', 'devices');
    }
    public function savePatient(Request $request, WatcherTelegramService $telegram, ?Patient $patient = null)
    {
        $patient ??= new Patient();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('patients')->ignore($patient->id)],
            'full_name' => ['required', 'string', 'max:150'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other', 'unspecified'])],
            'medical_notes' => ['nullable', 'string', 'max:4000'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'required_with:emergency_contact_phone', 'required_if:emergency_sms_enabled,1', 'required_if:watcher_telegram_enabled,1', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'required_if:emergency_sms_enabled,1', 'string', 'max:32', function ($attribute, $value, $fail) {
                if (!PhilippineMobile::normalize($value)) $fail('Enter a Philippine mobile number such as 09171234567 or +639171234567.');
            }],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
            'emergency_sms_enabled' => ['sometimes', 'boolean'],
            'watcher_telegram_enabled' => ['sometimes', 'boolean'],
            'replace_watcher_account' => ['sometimes', 'boolean'],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->whereNull('archived_at'), Rule::unique('patients')->ignore($patient->id)],
            'caregiver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'caregiver')->where('is_active', true)],
            'device_id' => ['nullable', 'integer', Rule::exists('devices', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_simulated', false))],
            'unassign_device' => ['nullable', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
        if (array_key_exists('emergency_contact_phone', $data)) {
            $data['emergency_contact_phone'] = PhilippineMobile::normalize($data['emergency_contact_phone']);
        }
        // An existing profile may be saved by an older client. Preserve its SMS
        // preference unless that client explicitly submits the new field.
        if (!array_key_exists('emergency_sms_enabled', $data) && !$patient->exists) {
            $data['emergency_sms_enabled'] = false;
        }
        if (!array_key_exists('watcher_telegram_enabled', $data) && !$patient->exists) {
            $data['watcher_telegram_enabled'] = false;
        }
        $deviceId = $data['device_id'] ?? null;
        $unassignDevice = (bool) ($data['unassign_device'] ?? false);
        $replacingAccount = (bool) ($data['replace_watcher_account'] ?? false);
        unset($data['device_id']);
        unset($data['unassign_device']);
        unset($data['replace_watcher_account']);
        $telegram->withConnectionLock($patient->id, fn () => DB::transaction(function () use ($patient, $data, $deviceId, $unassignDevice, $request, $telegram, $replacingAccount) {
            if ($patient->exists) {
                $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
                $changedWatcher = collect(['emergency_contact_name', 'emergency_contact_phone'])
                    ->contains(fn ($key) => array_key_exists($key, $data) && $patient->$key !== $data[$key]);
                if ($changedWatcher || $replacingAccount || (array_key_exists('watcher_telegram_enabled', $data) && !$data['watcher_telegram_enabled'])) {
                    $telegram->disconnect($patient, $replacingAccount, $request->user());
                    $request->session()->forget('watcher_telegram_links.'.$patient->id);
                }
            }
            if (!empty($data['room_id'])) {
                Room::whereKey($data['room_id'])->lockForUpdate()->firstOrFail();
                if (Patient::where('room_id', $data['room_id'])->where('id', '!=', $patient->id ?? 0)->exists()) {
                    throw ValidationException::withMessages(['room_id' => 'That room is already occupied.']);
                }
            }
            $patient->fill($data)->save();

            // Serialise edits for this patient so selecting a replacement device
            // cannot race with another assignment made from the devices page.
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $currentPatientAssignments = DeviceAssignment::where('patient_id', $patient->id)
                ->whereNull('unassigned_at')->lockForUpdate()->get();

            if ($unassignDevice) {
                $currentPatientDeviceIds = $currentPatientAssignments->pluck('device_id');
                $currentPatientAssignments->each(fn (DeviceAssignment $assignment) => $assignment->update(['unassigned_at' => now()]));
                if ($deviceId && $currentPatientDeviceIds->contains((int) $deviceId)) {
                    $deviceId = null;
                }
                $currentPatientAssignments = collect();
            }
            if ($deviceId) {
                $device = Device::whereKey($deviceId)->where('is_active', true)
                    ->where('is_simulated', false)->lockForUpdate()->firstOrFail();
                $currentDeviceAssignment = DeviceAssignment::where('device_id', $device->id)
                    ->whereNull('unassigned_at')->lockForUpdate()->first();
                if ($currentDeviceAssignment && $currentDeviceAssignment->patient_id !== $patient->id) {
                    throw ValidationException::withMessages(['device_id' => 'That device is already assigned to another patient.']);
                }

                // The patient form is the quick setup path: choosing another
                // controller/camera automatically replaces the old device of
                // the same type while preserving its assignment history.
                $sameTypeAssignment = $currentPatientAssignments->first(
                    fn (DeviceAssignment $assignment) => $assignment->device_type === $device->type
                        && (int) $assignment->device_id !== (int) $device->id
                );
                if ($sameTypeAssignment) {
                    $sameTypeAssignment->update(['unassigned_at' => now()]);
                }
                if (!$currentDeviceAssignment) {
                    DeviceAssignment::create([
                        'device_id' => $device->id,
                        'patient_id' => $patient->id,
                        'device_type' => $device->type,
                        'assigned_at' => now(),
                        'assigned_by' => $request->user()->id,
                    ]);
                }
            }
        }));
        return redirect()->route('patients.show', $patient)->with('success', $replacingAccount
            ? 'Patient record saved. Previous watcher disconnected. Create a private link and open it using the NEW Telegram account.'
            : 'Patient record saved.');
    }
    public function rooms()
    {
        $rooms = Room::with(['patient.caregiver', 'patient.currentAssignments.device'])
            ->withExists(['patient as is_occupied' => fn ($query) => $query->withoutGlobalScope('not_archived')])
            ->get()->sort(fn ($a, $b) => strnatcasecmp($a->number, $b->number))->values();
        $unassignedPatients = Patient::where('is_active', true)->whereNull('room_id')->orderBy('full_name')->get();
        return view('management.rooms', compact('rooms', 'unassignedPatients'));
    }
    public function saveRoom(Request $request, ?Room $room = null)
    {
        $isNew = !$room?->exists;
        $room ??= new Room();
        $data = $request->validate(['name' => ['required', 'string', 'max:100'],
            'number' => ['required', 'string', 'max:40', Rule::unique('rooms')->ignore($room->id)],
            'floor' => ['nullable', 'string', 'max:40'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $room->fill($data)->save();
        return redirect()->route('rooms.index')->with('success', $isNew ? 'Room created. It is ready for patient assignment.' : 'Room details updated. The patient assignment is unchanged.');
    }
    public function assignRoom(Request $request, Room $room)
    {
        $data = $request->validate(['patient_id' => ['required', 'integer',
            Rule::exists('patients', 'id')->where('is_active', true)->whereNull('archived_at')]]);
        DB::transaction(function () use ($room, $data) {
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $patient = Patient::whereKey($data['patient_id'])->lockForUpdate()->firstOrFail();
            if (!$patient->is_active) {
                throw ValidationException::withMessages(['patient_id' => 'Select an active patient.']);
            }
            if ($patient->room_id === $lockedRoom->id) return;
            if ($patient->room_id !== null) {
                throw ValidationException::withMessages(['patient_id' => 'This patient already has a room. Move them from their patient profile.']);
            }
            // Historical records also reserve the unique room_id until released.
            if (Patient::withoutGlobalScope('not_archived')->where('room_id', $lockedRoom->id)->exists()) {
                throw ValidationException::withMessages(['patient_id' => 'This room is no longer available. Refresh the room list before assigning.']);
            }
            $patient->update(['room_id' => $lockedRoom->id]);
        }, 3);
        return redirect()->route('rooms.index')->with('success', 'Patient assigned to room '.$room->number.'.');
    }
    public function devices(Request $request)
    {
        $query = Device::where('is_simulated', false)->with('currentAssignment.patient')->orderBy('code');
        if (!$request->user()->isAdmin()) {
            $query->whereHas('currentAssignment.patient', fn ($q) => $q->visibleTo($request->user()));
        }
        return view('management.devices', ['devices' => $query->get(),
            'patients' => Patient::visibleTo($request->user())->where('is_active', true)->orderBy('full_name')->get(),
            'assignments' => $request->user()->isAdmin() ? DeviceAssignment::whereHas('device', fn ($q) => $q->where('is_simulated', false))
                ->whereHas('patient')->with(['device', 'patient'])->orderByDesc('id')->limit(30)->get() : collect()]);
    }
    public function saveDevice(Request $request, ?Device $device = null)
    {
        $isNew = !$device?->exists;
        $device ??= new Device();
        abort_if($device->exists && $device->is_simulated, 404);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('devices')->ignore($device->id)],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['esp32', 'esp32_cam'])],
            'is_active' => ['required', 'boolean'],
            'is_simulated' => ['prohibited'],
            'camera_ip' => ['exclude_unless:type,esp32_cam', 'nullable', 'ipv4', function ($attribute, $value, $fail) {
                $private = preg_match('/^10\./', $value) || preg_match('/^192\.168\./', $value) || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $value);
                if (!$private) $fail('Use the camera private LAN IPv4 address (10.x, 172.16-31.x, or 192.168.x).');
            }],
            'camera_port' => ['exclude_unless:type,esp32_cam', 'required', 'integer', 'between:1,65535'],
            'camera_key' => ['exclude_unless:type,esp32_cam', 'nullable', 'string', 'min:32', 'max:256'],
        ]);
        if (!$isNew && $device->type !== $data['type']) {
            throw ValidationException::withMessages(['type' => 'Device type cannot change. Register a separate device instead.']);
        }
        $data['is_simulated'] = false;
        if (empty($data['camera_key'])) unset($data['camera_key']);
        $key = $isNew ? Str::random(64) : null;
        if ($key) $data['key_hash'] = hash('sha256', $key);
        $device->fill($data)->save();
        return redirect()->route('devices.index')->with('success', 'Device saved.')->with('device_key', $key)->with('device_code', $device->code);
    }
    public function rotate(Request $request, Device $device)
    {
        abort_if($device->is_simulated, 404);
        $request->validate(['confirm' => ['accepted']]);
        $key = Str::random(64);
        $device->update(['key_hash' => hash('sha256', $key)]);
        $redirect = $request->input('_device_form') === 'security-'.$device->id
            ? redirect()->route('devices.index') : back();
        return $redirect->with('device_key', $key)->with('device_code', $device->code)
            ->with('success', 'Device key rotated. Update the firmware or gateway with the new key.');
    }
    public function assign(Request $request, Device $device)
    {
        abort_if($device->is_simulated, 404);
        $data = $request->validate(['patient_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->where('is_active', true)->whereNull('archived_at')]]);
        DB::transaction(function () use ($device, $data, $request) {
            $locked = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $patientId = $data['patient_id'] ?? null;
            $replacement = collect();
            if ($patientId) {
                Patient::whereKey($patientId)->lockForUpdate()->firstOrFail();
                if (!$locked->is_active) {
                    throw ValidationException::withMessages(['patient_id' => 'Enable the device in settings before assigning a patient.']);
                }
                // A patient may have one device of each type. Selecting a new
                // one from Connected devices replaces the old one and keeps
                // the old row as assignment history.
                $replacement = DeviceAssignment::where('patient_id', $patientId)
                    ->where('device_type', $locked->type)->whereNull('unassigned_at')
                    ->where('device_id', '!=', $locked->id)->lockForUpdate()->get();
            }
            $current = $locked->currentAssignment;
            if ($current && (int) $patientId === $current->patient_id) return;
            $current?->update(['unassigned_at' => now()]);
            if ($patientId) {
                $replacement->each(fn (DeviceAssignment $assignment) => $assignment->update(['unassigned_at' => now()]));
                DeviceAssignment::create(['device_id' => $locked->id, 'patient_id' => $patientId,
                    'device_type' => $locked->type, 'assigned_at' => now(), 'assigned_by' => $request->user()->id]);
            }
        }, 3);
        $redirect = $request->input('_device_form') === 'assign-'.$device->id
            ? redirect()->route('devices.index') : back();
        return $redirect->with('success', 'Assignment updated. Previous assignments remain in history.');
    }
    public function users(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['administrator', 'caregiver'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $query = User::query()->withCount([
            'patients',
            'patients as active_patients_count' => fn ($query) => $query->where('is_active', true),
        ]);
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(fn ($query) => $query
                ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (!empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }
        if (!empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return view('management.users', [
            'users' => $query->orderBy('name')->paginate(12)->withQueryString(),
            'activeCaregivers' => User::where('role', 'caregiver')->where('is_active', true)->count(),
            'activeAdministrators' => User::where('role', 'administrator')->where('is_active', true)->count(),
            'unassignedPatients' => Patient::where('is_active', true)->whereNull('caregiver_id')->count(),
        ]);
    }
    public function saveUser(Request $request, ?User $user = null)
    {
        $user ??= new User();
        $creating = !$user->exists;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', Rule::in(['administrator', 'caregiver'])],
            'is_active' => ['required', 'boolean'],
            'password' => [$user->exists ? 'nullable' : 'required', 'confirmed', Password::min(12)],
        ]);
        if (empty($data['password'])) unset($data['password']);
        DB::transaction(function () use ($request, $user, $data) {
            User::where('role', 'administrator')->orderBy('id')->lockForUpdate()->get();
            if ($user->exists && $request->user()->id === $user->id &&
                ($data['role'] !== $user->role || (bool) $data['is_active'] !== $user->is_active)) {
                throw ValidationException::withMessages([
                    'role' => 'Another administrator must change your own role or account status.',
                ]);
            }
            if ($user->exists && $user->isAdmin() && (!$data['is_active'] || $data['role'] !== 'administrator')) {
                if (!User::where('role', 'administrator')->where('is_active', true)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages(['role' => 'Keep at least one active administrator.']);
                }
            }
            if ($user->exists && (!$data['is_active'] || $data['role'] !== 'caregiver') && $user->patients()->exists()) {
                throw ValidationException::withMessages(['role' => 'Reassign this caregiver\'s patients first.']);
            }
            $accessChanged = $user->exists &&
                ($user->role !== $data['role'] || $user->email !== $data['email']);
            $user->fill($data)->save();
            if (isset($data['password']) || !$data['is_active'] || $accessChanged) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        }, 3);
        if ($creating) {
            return redirect()->route('users.index')->with('success', 'Account created.');
        }
        if ($request->input('_user_form') === 'edit-'.$user->id) {
            $directoryQuery = array_filter([
                'q' => is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : null,
                'role' => in_array($request->query('role'), ['administrator', 'caregiver'], true) ? $request->query('role') : null,
                'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null,
                'page' => filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
            ], fn ($value) => $value !== null && $value !== '');
            return redirect()->route('users.index', $directoryQuery)->with('success', 'Account updated.');
        }
        return back()->with('success', 'Account updated.');
    }
}
