<?php
namespace App\Services;

use App\Jobs\CaptureEventImage;
use App\Jobs\ExpirePendingCameraCapture;
use App\Models\{CameraVerification, Device, FallEvent, Patient, SensorReading, User};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventIngestion
{
    public function accept(Device $device, array $input): array
    {
        $data = Validator::make($input, [
            'event_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'event_type' => ['required', Rule::in(['possible_fall', 'manual_emergency'])],
            'detected_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(2)->toIso8601String()],
            // Legacy clients may send false. There is no switch that permits true.
            'is_simulated' => ['sometimes', 'boolean'],
            'sensor' => ['required_if:event_type,possible_fall', 'nullable', 'array:acceleration_g,gyro_rad_s,axes'],
            'sensor.acceleration_g' => ['required_with:sensor', 'numeric', 'between:0,32'],
            'sensor.gyro_rad_s' => ['required_with:sensor', 'numeric', 'between:0,100'],
            'sensor.axes' => ['nullable', 'array:ax,ay,az,gx,gy,gz'],
            'sensor.axes.*' => ['numeric', 'between:-400,400'],
            'camera_status' => ['prohibited'],
        ])->validate();
        abort_unless($device->type === 'esp32', 422, 'Only the main ESP32 reports events.');
        abort_if($device->is_simulated || (bool) ($data['is_simulated'] ?? false), 403, 'Only physical-device readings are accepted.');
        $data['is_simulated'] = false;
        $fingerprint = hash('sha256', json_encode($this->canonical($data), JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($device, $data, $fingerprint) {
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_unless($device->is_active && !$device->is_simulated, 401);
            $existing = FallEvent::where('device_id', $device->id)->where('event_key', $data['event_id'])->first();
            if ($existing) {
                abort_if($existing->is_simulated, 409, 'This identifier belongs to an archived record.');
                abort_unless(hash_equals($existing->payload_hash, $fingerprint), 409, 'Event ID already used with a different payload.');
                return [$existing, false];
            }
            $detectedAt = empty($data['detected_at']) ? now() : Carbon::parse($data['detected_at'])->utc();
            $assignment = $device->assignments()->where('assigned_at', '<=', $detectedAt)
                ->where(fn ($q) => $q->whereNull('unassigned_at')->orWhere('unassigned_at', '>', $detectedAt))->first();
            abort_unless($assignment, 422, 'No patient assignment exists for this event time.');
            $patient = Patient::findOrFail($assignment->patient_id);
            abort_unless($patient->is_active, 422, 'The assigned patient is inactive.');
            $reading = !empty($data['sensor']) ? SensorReading::create([
                'patient_id' => $patient->id, 'device_id' => $device->id,
                'acceleration_g' => $data['sensor']['acceleration_g'],
                'gyro_rad_s' => $data['sensor']['gyro_rad_s'],
                'axes' => $data['sensor']['axes'] ?? null,
                'is_simulated' => false, 'recorded_at' => $detectedAt,
            ]) : null;
            $event = FallEvent::create([
                'public_id' => (string) Str::uuid(), 'event_key' => $data['event_id'],
                'payload_hash' => $fingerprint, 'patient_id' => $patient->id,
                'device_id' => $device->id, 'room_id' => $patient->room_id,
                'reading_id' => $reading?->id, 'event_type' => $data['event_type'],
                'status' => 'pending', 'is_simulated' => false,
                'verification_status' => $data['event_type'] === 'possible_fall' ? 'verifying' : 'not_required',
                'verification_reason' => $data['event_type'] === 'possible_fall'
                    ? 'Waiting for the assigned camera and pose verifier.'
                    : 'Manual emergencies bypass camera verification.',
                'detected_at' => $detectedAt, 'received_at' => now(),
            ]);
            $device->update(['last_seen_at' => now()]);
            // Both automatic falls and manual emergency-button alerts request
            // a snapshot asynchronously. Event acceptance never waits for the
            // camera, so the emergency alert still reaches caregivers first.
            $cameraAssignment = $patient->assignments()->where('device_type', 'esp32_cam')
                ->where('assigned_at', '<=', $detectedAt)
                ->where(fn ($q) => $q->whereNull('unassigned_at')->orWhere('unassigned_at', '>', $detectedAt))->first();
            $cameraDevice = $cameraAssignment?->device;
            // Never capture a different occupant after a device has been reassigned.
            $sameOccupant = $cameraDevice?->currentAssignment?->patient_id === $patient->id;
            // Fall assessment keeps its established pull-and-verify workflow.
            // Direct upload currently applies only to manual emergencies.
            $pushEnabled = (bool) config('monitor.camera_push_enabled', false)
                && $data['event_type'] === 'manual_emergency';
            $canCapture = $cameraDevice?->is_active && !$cameraDevice->is_simulated
                && ($pushEnabled || $cameraDevice->camera_ip)
                && $sameOccupant && $detectedAt->gt(now()->subSeconds(10));
            $status = $canCapture ? 'pending' : 'unavailable';
            $camera = CameraVerification::create([
                'fall_event_id' => $event->id, 'camera_device_id' => $cameraDevice?->id,
                'status' => $status, 'is_simulated' => false,
                'result' => $canCapture
                    ? ($pushEnabled
                        ? CameraVerification::PUSH_PENDING_RESULT
                        : 'Capture queued for automated pose verification and caregiver review.')
                    : 'No eligible camera for this event. Human review is required.',
            ]);
            if ($data['event_type'] === 'possible_fall' && !$canCapture) {
                $event->update([
                    'verification_status' => 'inconclusive',
                    'verification_reason' => 'No eligible camera was available. Treat this as a possible fall until a caregiver checks the patient.',
                    'verified_at' => now(),
                ]);
            }
            if ($canCapture) {
                if ($pushEnabled) {
                    ExpirePendingCameraCapture::dispatch($camera->id)
                        ->delay(now()->addSeconds(max(1, (int) config('monitor.camera_push_window_seconds', 45)) + 1))
                        ->afterCommit();
                } else {
                    CaptureEventImage::dispatch($camera->id)->afterCommit();
                }
            }
            $recipients = User::where('is_active', true)
                ->where(fn ($q) => $q->where('role', 'administrator')->orWhere('id', $patient->caregiver_id))->pluck('id');
            foreach ($recipients as $recipient) {
                DB::table('notification_deliveries')->insert([
                    'fall_event_id' => $event->id, 'user_id' => $recipient,
                    'channel' => 'dashboard', 'is_simulated' => false, 'created_at' => now(),
                ]);
            }
            return [$event, true];
        }, 3);
    }

    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }
        return $data;
    }
}
