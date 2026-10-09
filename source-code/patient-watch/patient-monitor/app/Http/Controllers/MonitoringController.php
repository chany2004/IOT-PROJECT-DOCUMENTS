<?php
namespace App\Http\Controllers;

use App\Models\{Device, FallEvent, Patient};
use App\Services\WatcherSmsService;
use App\Services\WatcherTelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MonitoringController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $patients = Patient::visibleTo($user)->where('is_active', true)
            ->with(['room', 'caregiver', 'currentAssignments.device'])
            ->withCount([
                'events as urgent_events_count' => fn ($query) => $query->where('status', 'pending')
                    ->where(fn ($query) => $query->where('event_type', 'manual_emergency')
                        ->orWhereIn('verification_status', ['likely_fall', 'inconclusive'])),
                'events as verifying_events_count' => fn ($query) => $query->where('status', 'pending')
                    ->where('event_type', 'possible_fall')->where('verification_status', 'verifying'),
                'events as review_events_count' => fn ($query) => $query->where(fn ($query) =>
                    $query->where('status', 'acknowledged')->orWhere(fn ($query) =>
                        $query->where('status', 'pending')->where('verification_status', 'unlikely_fall'))),
            ])->orderBy('full_name')->get();
        $events = FallEvent::visibleTo($user)->with(['patient.room', 'camera', 'device'])
            ->whereIn('status', ['pending', 'acknowledged'])->where('is_simulated', false)
            ->orderByDesc('detected_at')->limit(12)->get();
        $all = FallEvent::visibleTo($user);
        $verifying = (clone $all)->where('status', 'pending')->where('verification_status', 'verifying')->count();
        $needsAttention = (clone $all)->where('status', 'pending')->where(function ($query) {
            $query->where('event_type', 'manual_emergency')
                ->orWhereIn('verification_status', ['likely_fall', 'inconclusive']);
        })->count();
        $cameraCleared = (clone $all)->where('status', 'pending')
            ->where('verification_status', 'unlikely_fall')->count();
        $acknowledged = (clone $all)->where('status', 'acknowledged')->count();
        $stats = [
            'patients' => $patients->count(),
            'verifying' => $verifying,
            'pending' => $needsAttention,
            'acknowledged' => $acknowledged,
            'review' => $acknowledged + $cameraCleared,
            'today' => (clone $all)->where('received_at', '>=', now()->timezone(config('monitor.display_timezone'))->startOfDay()->utc())->count(),
        ];
        $devices = $patients->flatMap(fn ($p) => $p->currentAssignments->pluck('device'));
        $stats['online'] = $devices->filter(fn ($d) => $d->isOnline())->count();
        $stats['devices'] = $devices->count();

        $timezone = config('monitor.display_timezone');
        $chartStart = now($timezone)->startOfDay()->subDays(6);
        $chartEvents = (clone $all)->where('detected_at', '>=', $chartStart->copy()->utc())
            ->get(['detected_at', 'event_type', 'status', 'verification_status']);
        $activityChart = collect(range(0, 6))->map(function (int $offset) use ($chartEvents, $chartStart, $timezone) {
            $day = $chartStart->copy()->addDays($offset);
            $eventsForDay = $chartEvents->filter(fn (FallEvent $event) =>
                $event->detected_at->copy()->timezone($timezone)->toDateString() === $day->toDateString());
            $possibleFalls = $eventsForDay->where('event_type', 'possible_fall')->count();
            $manualEmergencies = $eventsForDay->where('event_type', 'manual_emergency')->count();

            return [
                'label' => $day->format('D'),
                'date' => $day->format('M j'),
                'possible_fall' => $possibleFalls,
                'manual_emergency' => $manualEmergencies,
                'total' => $possibleFalls + $manualEmergencies,
            ];
        });
        $activityMax = max(1, (int) $activityChart->max('total'));

        $statusChart = collect([
            'verifying' => ['label' => 'Camera verifying', 'color' => '#5b9bd5'],
            'pending' => ['label' => 'Awaiting review', 'color' => '#f1bf55'],
            'acknowledged' => ['label' => 'Under review', 'color' => '#31bfa4'],
            'resolved' => ['label' => 'Resolved', 'color' => '#67c7aa'],
            'false_alarm' => ['label' => 'False alarm', 'color' => '#9aa9bc'],
        ])->map(function (array $item, string $status) use ($chartEvents) {
            $item['status'] = $status;
            $item['count'] = match ($status) {
                'verifying' => $chartEvents->where('status', 'pending')
                    ->where('verification_status', 'verifying')->count(),
                'pending' => $chartEvents->where('status', 'pending')
                    ->where('verification_status', '!=', 'verifying')->count(),
                default => $chartEvents->where('status', $status)->count(),
            };
            return $item;
        })->values();
        $statusTotal = $statusChart->sum('count');
        $angle = 0;
        $statusSegments = $statusChart->filter(fn (array $item) => $item['count'] > 0)
            ->map(function (array $item) use (&$angle, $statusTotal) {
                $start = $angle;
                $angle += ($item['count'] / $statusTotal) * 360;
                return $item['color'].' '.round($start, 2).'deg '.round($angle, 2).'deg';
            });
        $statusGradient = $statusSegments->isEmpty()
            ? '#e8ede9 0deg 360deg'
            : $statusSegments->implode(', ');
        $chartPeriod = $chartStart->format('M j').' - '.now($timezone)->format('M j');

        return view('dashboard', compact(
            'patients', 'events', 'stats', 'activityChart', 'activityMax',
            'statusChart', 'statusTotal', 'statusGradient', 'chartPeriod'
        ));
    }

    public function feed(Request $request)
    {
        $events = FallEvent::visibleTo($request->user())
            ->with(['patient.room', 'patient.caregiver', 'room', 'device', 'camera.device', 'acknowledger'])
            ->whereIn('status', ['pending', 'acknowledged'])->where('is_simulated', false)
            ->orderByDesc('id')->limit(50)->get()
            ->map(function (FallEvent $event) {
                $camera = $event->camera;
                $hasImage = $camera?->hasDisplayableImage() ?? false;
                $cameraResult = $camera?->result;
                // Firmware frame-acquisition errors are stored as a timeout so
                // the existing alert lifecycle remains intact. Keep their
                // presentation as unavailable even after the heartbeat goes
                // stale; a real transport timeout keeps the timeout label.
                $cameraFrameFailed = $camera?->isFrameAcquisitionFailure() ?? false;
                $cameraFrameStalled = $camera?->status === 'timeout'
                    && $camera?->device?->isOnline();
                $cameraDisplayStatus = $camera?->status === 'captured' && !$hasImage
                    ? 'incomplete'
                    : (($cameraFrameFailed || $cameraFrameStalled)
                        ? 'unavailable' : ($camera?->status ?? 'not_requested'));
                if ($cameraFrameStalled && !$cameraResult) {
                    $cameraResult = 'The camera snapshot did not arrive in time. Check the camera connection and power. Check the patient now.';
                }
                $popupKind = match (true) {
                    $event->event_type === 'manual_emergency' => 'manual_emergency',
                    $event->verification_status === 'likely_fall' => 'likely_fall',
                    $event->verification_status === 'inconclusive' => 'review_required',
                    default => null,
                };
                $patientStatus = match (true) {
                    $event->status === 'acknowledged' => 'Caregiver responding',
                    $event->event_type === 'manual_emergency' => 'Immediate patient check required',
                    $event->verification_status === 'likely_fall' => 'Likely fall — immediate check required',
                    $event->verification_status === 'inconclusive' => 'Camera inconclusive — check patient',
                    default => 'Camera verification in progress',
                };

                return [
                    'public_id' => $event->public_id,
                    'status' => $event->status,
                    'event_type' => $event->event_type,
                    'verification_status' => $event->verification_status,
                    'requires_attention' => $event->requiresImmediateAttention(),
                    'popup_kind' => $popupKind,
                    'assessment' => $event->assessmentLabel(),
                    'patient_status' => $patientStatus,
                    'detected_at' => $event->detected_at?->toIso8601String(),
                    'received_at' => $event->received_at?->toIso8601String(),
                    'event_url' => route('events.show', $event),
                    'acknowledge_url' => route('events.acknowledge', $event),
                    'patient' => [
                        'name' => $event->patient->full_name,
                        'room' => $event->room?->number ?? $event->patient->room?->number,
                        'caregiver' => $event->patient->caregiver?->name,
                    ],
                    'device' => [
                        'code' => $event->device->code,
                        'online' => $event->device->isOnline(),
                        'sensor_ok' => $event->device->sensor_ok,
                    ],
                    'camera' => [
                        'status' => $camera?->status ?? 'not_requested',
                        'display_status' => $cameraDisplayStatus,
                        'device_code' => $camera?->device?->code,
                        'result' => $cameraResult,
                        // Version the private image URL so a browser that saw a
                        // transient 404 while capture was finishing cannot keep
                        // serving that failed response after the JPEG is ready.
                        'image_url' => $hasImage
                            ? route('events.image', $event).'?v='.($camera->captured_at ?? $camera->updated_at)?->getTimestamp()
                            : null,
                        'verdict' => $camera?->verdict,
                    ],
                    'acknowledged_by' => $event->acknowledger?->name,
                ];
            })->values();
        return response()->json(['events' => $events, 'server_time' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function events(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'patient_id' => ['nullable', 'integer'], 'type' => ['nullable', Rule::in(['possible_fall', 'manual_emergency'])],
            'status' => ['nullable', Rule::in(['pending', 'acknowledged', 'resolved', 'false_alarm'])],
            'assessment' => ['nullable', Rule::in(['verifying', 'likely_fall', 'unlikely_fall', 'inconclusive', 'not_required'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = FallEvent::visibleTo($request->user())->with(['patient.room', 'device', 'camera']);
        $search = trim(ltrim(trim($filters['q'] ?? ''), '#'));
        if ($search !== '') {
            // Treat wildcard characters literally and keep every OR inside
            // the user's authorized event scope.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw("LOWER(CAST(public_id AS TEXT)) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('patient', fn ($query) =>
                        $query->whereRaw("LOWER(full_name) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('device', fn ($query) =>
                        $query->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        foreach (['patient_id' => 'patient_id', 'type' => 'event_type', 'status' => 'status'] as $filter => $column) {
            if (!empty($filters[$filter])) $query->where($column, $filters[$filter]);
        }
        if (!empty($filters['assessment'])) $query->where('verification_status', $filters['assessment']);
        if (!empty($filters['from'])) $query->where('detected_at', '>=', \Carbon\Carbon::parse($filters['from'], config('monitor.display_timezone'))->startOfDay()->utc());
        if (!empty($filters['to'])) $query->where('detected_at', '<=', \Carbon\Carbon::parse($filters['to'], config('monitor.display_timezone'))->endOfDay()->utc());
        $events = $query->orderByDesc('detected_at')->paginate(20)->withQueryString();
        $patients = Patient::visibleTo($request->user())->orderBy('full_name')->get();
        return view('events.index', compact('events', 'patients'));
    }

    public function show(Request $request, FallEvent $event)
    {
        $this->authorizeEvent($request, $event);
        $event->load(['patient.room', 'device', 'room', 'reading', 'camera.device', 'updates.user', 'acknowledger', 'watcherSms.confirmer', 'watcherTelegram.confirmer']);
        DB::table('notification_deliveries')->where('fall_event_id', $event->id)
            ->where('user_id', $request->user()->id)->whereNull('seen_at')->update(['seen_at' => now()]);
        return view('events.show', compact('event'));
    }

    public function update(Request $request, FallEvent $event, WatcherSmsService $watcherSms, WatcherTelegramService $watcherTelegram)
    {
        $this->authorizeEvent($request, $event);
        $data = $request->validate([
            'status' => ['required', Rule::in(['acknowledged', 'resolved', 'false_alarm'])],
            'caregiver_outcome' => ['nullable', Rule::in(['confirmed_fall', 'escalated', 'patient_safe'])],
            'note' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        $notification = DB::transaction(function () use ($request, $event, $data, $watcherSms, $watcherTelegram) {
            $locked = FallEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['resolved', 'false_alarm'])) {
                throw ValidationException::withMessages(['status' => 'This incident is already closed. Its history is preserved.']);
            }
            $outcome = $data['status'] === 'false_alarm'
                ? 'false_alarm'
                : ($data['caregiver_outcome'] ?? ($data['status'] === 'resolved'
                    ? ($locked->caregiver_outcome ?? 'patient_safe') : $locked->caregiver_outcome));
            $auditNote = ($outcome ? 'Caregiver outcome: '.str_replace('_', ' ', $outcome).".\n" : '').$data['note'];
            $locked->updates()->create(['user_id' => $request->user()->id,
                'from_status' => $locked->status, 'to_status' => $data['status'], 'note' => $auditNote, 'created_at' => now()]);
            $locked->status = $data['status'];
            $locked->caregiver_outcome = $outcome;
            $locked->acknowledged_by ??= $request->user()->id;
            $locked->acknowledged_at ??= now();
            if (in_array($data['status'], ['resolved', 'false_alarm'])) $locked->resolved_at = now();
            $locked->save();
            if (config('watcher.channel') === 'semaphore') {
                $watcherSms->queueForConfirmedFall($locked, $request->user());
                return null;
            }
            return $watcherTelegram->queueForConfirmedFall($locked, $request->user());
        });
        $message = 'Incident updated. Your response is recorded in the history.';
        if (!$notification) return back()->with('success', $message);

        $feedback = match ($notification->status) {
            'pending' => 'Watcher Telegram notification queued automatically. Check the notification status below.',
            'processing' => 'The watcher Telegram notification is being sent. No duplicate was queued.',
            'submitted' => 'This incident was already sent to Telegram. No duplicate was sent.',
            default => null,
        };
        $response = back()->with('success', $message.($feedback ? ' '.$feedback : ''));
        if (!$feedback) {
            $response->with('warning', 'Watcher notification: '.($notification->status_reason ?? $notification->statusLabel()));
        }
        return $response;
    }

    public function retryWatcherSms(Request $request, FallEvent $event, WatcherSmsService $watcherSms)
    {
        abort_unless(config('watcher.channel') === 'semaphore', 422, 'SMS is disabled. The selected watcher channel is Telegram.');
        $this->authorizeEvent($request, $event);
        DB::transaction(function () use ($request, $event, $watcherSms) {
            $locked = FallEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->event_type === 'possible_fall' && $locked->caregiver_outcome === 'confirmed_fall'
                && $locked->status !== 'false_alarm', 422, 'Only a confirmed fall can notify the watcher.');
            $record = $locked->watcherSms()->lockForUpdate()->firstOrFail();
            if (!$record->canRetry()) {
                throw ValidationException::withMessages(['sms' => 'This SMS is already queued, submitted, or has an uncertain result. Check its status before sending again.']);
            }
            if ($reason = $watcherSms->blockedReason($locked->patient)) {
                throw ValidationException::withMessages(['sms' => $reason]);
            }
            $watcherSms->prepare($locked, $request->user(), $record);
            $locked->updates()->create(['user_id' => $request->user()->id, 'from_status' => $locked->status,
                'to_status' => $locked->status, 'note' => 'Watcher SMS retry requested after reviewing the contact and provider setup.', 'created_at' => now()]);
        });

        return back()->with('success', 'Watcher SMS queued. Review the SMS status below for the result.');
    }

    public function retryWatcherTelegram(Request $request, FallEvent $event, WatcherTelegramService $service)
    {
        $this->authorizeEvent($request, $event);
        DB::transaction(function () use ($request, $event, $service) {
            $locked = FallEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($service->canNotify($locked), 422, 'Only a confirmed fall can notify the watcher.');
            $record = $locked->watcherTelegram()->lockForUpdate()->firstOrFail();
            if (!$record->canRetry()) {
                throw ValidationException::withMessages(['telegram' => 'This alert is already queued, submitted, cancelled or uncertain. Check the watcher chat before sending again.']);
            }
            if ($reason = $service->blockedReason($locked->patient)) {
                throw ValidationException::withMessages(['telegram' => $reason]);
            }
            $service->prepare($locked, $request->user(), $record);
            $locked->updates()->create(['user_id' => $request->user()->id, 'from_status' => $locked->status,
                'to_status' => $locked->status, 'note' => 'Watcher Telegram retry requested after reviewing the watcher connection.', 'created_at' => now()]);
        });
        return back()->with('success', 'Watcher Telegram alert queued. Review the notification status below.');
    }

    public function acknowledge(Request $request, FallEvent $event)
    {
        $this->authorizeEvent($request, $event);
        $result = DB::transaction(function () use ($request, $event) {
            $locked = FallEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'pending') {
                $locked->updates()->create([
                    'user_id' => $request->user()->id,
                    'from_status' => 'pending',
                    'to_status' => 'acknowledged',
                    'note' => 'Emergency alert acknowledged from the live popup. Caregiver response started.',
                    'created_at' => now(),
                ]);
                $locked->status = 'acknowledged';
                $locked->acknowledged_by = $request->user()->id;
                $locked->acknowledged_at = now();
                $locked->save();
            }
            return $locked->fresh('acknowledger');
        });

        return response()->json([
            'status' => $result->status,
            'acknowledged_by' => $result->acknowledger?->name,
            'message' => $result->status === 'acknowledged'
                ? 'Response recorded. Check the patient and complete the incident report.'
                : 'This incident is already closed.',
        ]);
    }

    public function image(Request $request, FallEvent $event)
    {
        $this->authorizeEvent($request, $event);
        $camera = $event->camera;
        abort_unless($camera?->hasDisplayableImage(), 404);
        return response()->file(Storage::disk('local')->path($camera->image_path),
            ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizeEvent(Request $request, FallEvent $event): void
    {
        abort_unless(FallEvent::visibleTo($request->user())->whereKey($event->id)->exists(), 404);
    }
}
