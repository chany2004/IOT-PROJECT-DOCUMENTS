<?php

namespace App\Http\Controllers;

use App\Models\CameraVerification;
use App\Models\Device;
use App\Support\CameraJpeg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CameraCaptureController extends Controller
{
    public function pending(Request $request)
    {
        $device = $this->camera($request);
        if (!config('monitor.camera_push_enabled', false)) {
            return $this->pendingResponse(0);
        }

        $window = max(1, (int) config('monitor.camera_push_window_seconds', 45));
        $assignment = $device->currentAssignment()->first();
        if (!$assignment) return $this->pendingResponse(0);

        $record = CameraVerification::query()
            ->where('camera_device_id', $device->id)
            ->where('status', 'pending')->where('is_simulated', false)
            ->where('result', CameraVerification::PUSH_PENDING_RESULT)
            ->where('created_at', '>=', now()->subSeconds($window))
            ->whereHas('event', fn ($query) => $query
                ->where('event_type', 'manual_emergency')
                ->where('patient_id', $assignment->patient_id)
                ->where('is_simulated', false))
            ->oldest('id')->first();

        return $this->pendingResponse($record?->id ?? 0);
    }

    public function store(Request $request, int $verification)
    {
        $device = $this->camera($request);
        abort_unless(config('monitor.camera_push_enabled', false), 404);

        $maxBytes = max(1, (int) config('monitor.camera_max_image_bytes', 500000));
        $declaredLength = $request->header('Content-Length');
        abort_if($declaredLength !== null && ctype_digit($declaredLength)
            && (int) $declaredLength > $maxBytes, 413, 'JPEG exceeds the size limit.');
        abort_unless(str_starts_with(strtolower($request->header('Content-Type', '')), 'image/jpeg'),
            415, 'Expected image/jpeg.');
        abort_unless(function_exists('imagecreatefromstring'), 503,
            'Camera image validation is unavailable.');
        $jpeg = $request->getContent();
        abort_if(strlen($jpeg) > $maxBytes, 413, 'JPEG exceeds the size limit.');
        abort_if($declaredLength !== null && (!ctype_digit($declaredLength)
            || (int) $declaredLength !== strlen($jpeg)), 422, 'Incomplete JPEG upload.');
        abort_unless(CameraJpeg::isUsable($jpeg), 422, 'Incomplete or corrupt JPEG.');

        return DB::transaction(function () use ($device, $verification, $jpeg) {
            $record = CameraVerification::with('event')->lockForUpdate()->findOrFail($verification);
            abort_unless($record->camera_device_id === $device->id
                && !$record->is_simulated && $record->event
                && !$record->event->is_simulated
                && $record->event->event_type === 'manual_emergency', 404);
            if ($record->status === 'captured' && $record->image_path) {
                return response()->json(['success' => true, 'duplicate' => true]);
            }
            $window = max(1, (int) config('monitor.camera_push_window_seconds', 45));
            abort_unless($record->status === 'pending'
                && $record->result === CameraVerification::PUSH_PENDING_RESULT
                && $record->created_at->gte(now()->subSeconds($window)),
                409, 'Capture request has expired.');
            $assignment = $device->currentAssignment()->first();
            abort_unless($assignment
                && $assignment->patient_id === $record->event->patient_id
                && $record->event->patient?->is_active, 409, 'Camera assignment changed.');

            $path = 'captures/'.$record->event->public_id.'/'.$record->id.'.jpg';
            abort_unless(Storage::disk('local')->put($path, $jpeg), 500,
                'Unable to save camera evidence.');
            $record->update([
                'status' => 'captured', 'image_path' => $path,
                'captured_at' => now(),
                'result' => 'Emergency snapshot saved for caregiver review.',
            ]);
            $device->update(['last_seen_at' => now()]);

            return response()->json(['success' => true, 'duplicate' => false], 201);
        });
    }

    private function camera(Request $request): Device
    {
        $device = $request->attributes->get('device');
        abort_unless($device instanceof Device && $device->type === 'esp32_cam', 403);
        return $device;
    }

    private function pendingResponse(int $id)
    {
        return response((string) $id, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }
}
