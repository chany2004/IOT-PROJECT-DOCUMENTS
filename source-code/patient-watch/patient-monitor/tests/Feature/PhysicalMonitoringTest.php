<?php

namespace Tests\Feature;

use App\Jobs\CaptureEventImage;
use App\Jobs\ExpirePendingCameraCapture;
use App\Models\{Device, DeviceAssignment, FallEvent, Patient, Room, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{Http, Queue, Route, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class PhysicalMonitoringTest extends TestCase
{
    use DatabaseTransactions;

    private Device $device;
    private Patient $patient;
    private User $caregiver;
    private User $administrator;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests must never create events in the actual monitoring database.
        $this->assertSame('patient_monitor_test', config('database.connections.pgsql.database'));
        Queue::fake();
        Http::preventStrayRequests();
        $this->caregiver = $this->user('caregiver');
        $this->administrator = $this->user('administrator');
        $this->patient = Patient::create(['code' => Str::uuid(), 'full_name' => 'Integration test patient',
            'caregiver_id' => $this->caregiver->id, 'is_active' => true]);
        $this->key = Str::random(64);
        $this->device = Device::create(['code' => Str::uuid(), 'name' => 'Test physical controller',
            'type' => 'esp32', 'key_hash' => hash('sha256', $this->key),
            'is_active' => true, 'is_simulated' => false]);
        $this->assign($this->device);
    }

    private function user(string $role): User
    {
        return User::create(['name' => 'Integration test user', 'email' => Str::uuid().'@example.test',
            'password' => Str::random(32), 'role' => $role, 'is_active' => true]);
    }

    private function assign(Device $device): void
    {
        DeviceAssignment::create(['device_id' => $device->id, 'patient_id' => $this->patient->id,
            'device_type' => $device->type, 'assigned_at' => now()->subMinute()]);
    }

    private function payload(): array
    {
        return ['event_id' => 'physical-test-1', 'event_type' => 'possible_fall', 'is_simulated' => false,
            'sensor' => ['acceleration_g' => 3.1, 'gyro_rad_s' => 0.2]];
    }

    private function tinyJpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD8qqKKKAP/2Q==');
    }

    private function submit(array $payload)
    {
        return $this->postJson('/api/v1/events', $payload, ['X-Device-Key' => $this->key]);
    }

    public function test_alert_history_search_matches_records_and_preserves_access_scope(): void
    {
        $this->patient->update(['full_name' => 'Searchable Patient', 'code' => 'SEARCH_%']);
        $this->device->update(['code' => 'SEARCH-NODE-01']);
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');

        foreach (['searchable', 'SEARCH_%', 'search-node-01', '#'.substr($id, 0, 8)] as $term) {
            $this->actingAs($this->caregiver)->get('/events?'.http_build_query(['q' => $term]))
                ->assertOk()->assertViewHas('events', fn ($events) =>
                    $events->total() === 1 && $events->first()->public_id === $id);
        }
        $this->actingAs($this->caregiver)->get('/events?q=nonexistent')
            ->assertOk()->assertViewHas('events', fn ($events) => $events->total() === 0);
        $this->actingAs($this->caregiver)->get('/events?q=searchable&status=resolved')
            ->assertOk()->assertViewHas('events', fn ($events) => $events->total() === 0);
        $this->actingAs($this->user('caregiver'))->get('/events?q=search-node-01')
            ->assertOk()->assertViewHas('events', fn ($events) => $events->total() === 0);
    }

    public function test_alert_history_filter_ui_shows_active_filters_and_preserves_search_scope(): void
    {
        $this->patient->update(['full_name' => 'Scoped Search Patient']);
        $this->submit($this->payload())->assertCreated();

        $response = $this->actingAs($this->caregiver)->get(route('events.index', [
            'q' => 'Scoped Search Patient',
            'patient_id' => $this->patient->id,
            'status' => 'pending',
        ]))->assertOk()->assertViewHas('events', fn ($events) => $events->total() === 1)
            ->assertSeeText('Showing filtered results')
            ->assertSeeText('Search: Scoped Search Patient')
            ->assertSeeText('Status: Pending')
            ->assertDontSee('aria-label="Quick status filters"', false)
            ->assertSee('class="event-query-form"', false)
            ->assertSee('class="panel event-search-panel"', false)
            ->assertSee('class="panel event-filter-panel"', false)
            ->assertSeeText('Filter alert records')
            ->assertSeeText('Apply filters')
            ->assertDontSee('class="event-advanced-filters"', false)
            ->assertSee('event-history.css', false);

        $this->assertSame(1, substr_count($response->getContent(), 'class="event-query-form"'));
        $response->assertSee('aria-label="Remove Search: Scoped Search Patient filter"', false);
    }

    public function test_patient_directory_search_matches_assignments_and_preserves_access_scope(): void
    {
        $room = Room::create(['name' => 'Directory search room', 'number' => 'DIRECTORY-307']);
        $this->patient->update(['full_name' => 'Directory Patient', 'code' => 'DIRECTORY_%', 'room_id' => $room->id]);
        $this->caregiver->update(['name' => 'Directory Caregiver']);
        $this->device->update(['code' => 'DIRECTORY-NODE-01']);

        foreach (['directory patient', 'DIRECTORY_%', 'directory-307', 'directory caregiver', 'directory-node-01'] as $term) {
            $this->actingAs($this->caregiver)->get('/patients?'.http_build_query(['q' => $term]))
                ->assertOk()->assertViewHas('patients', fn ($patients) =>
                    $patients->total() === 1 && $patients->first()->id === $this->patient->id);
        }
        $this->actingAs($this->caregiver)->get('/patients?q=nonexistent')
            ->assertOk()->assertViewHas('patients', fn ($patients) => $patients->total() === 0)
            ->assertSee('No matching patients.');
        $this->actingAs($this->user('caregiver'))->get('/patients?q=directory-node-01')
            ->assertOk()->assertViewHas('patients', fn ($patients) => $patients->total() === 0);
    }

    public function test_events_require_an_active_device_key(): void
    {
        $this->postJson('/api/v1/events', $this->payload())->assertUnauthorized();
        $this->device->update(['is_active' => false]);
        $this->submit($this->payload())->assertUnauthorized();
    }

    public function test_physical_event_is_persisted_and_duplicate_is_idempotent(): void
    {
        $id = $this->submit($this->payload())->assertCreated()->assertJsonPath('duplicate', false)->json('event_id');
        $this->submit($this->payload())->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('event_id', $id);
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $this->assertFalse($event->is_simulated);
        $this->assertSame('pending', $event->status);
        $this->assertSame('inconclusive', $event->verification_status);
        $this->assertEquals(3.1, $event->reading->acceleration_g);
        $this->assertSame('unavailable', $event->camera->status);
        $this->assertSame(1, $this->device->assignments()->count());
        $this->assertSame(1, FallEvent::where('device_id', $this->device->id)->count());
        foreach ([$this->administrator, $this->caregiver] as $recipient) {
            $this->assertDatabaseHas('notification_deliveries', ['fall_event_id' => $event->id, 'user_id' => $recipient->id]);
        }
        $changed = $this->payload();
        $changed['sensor']['acceleration_g'] = 4.2;
        $this->submit($changed)->assertConflict();
    }

    public function test_simulation_and_missing_fall_sensor_data_are_rejected(): void
    {
        $data = $this->payload();
        $data['is_simulated'] = true;
        $this->submit($data)->assertForbidden();
        $data = $this->payload();
        unset($data['sensor']);
        $this->submit($data)->assertUnprocessable();
        $this->actingAs($this->administrator)->get('/simulation')->assertNotFound();
    }

    public function test_manual_emergency_does_not_wait_for_camera_or_sensor(): void
    {
        $id = $this->submit(['event_id' => 'emergency-1', 'event_type' => 'manual_emergency',
            'is_simulated' => false])->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $this->assertNull($event->reading_id);
        $this->assertSame('unavailable', $event->camera->status);
        $this->assertNull($event->camera->camera_device_id);
        $this->assertSame('pending', $event->status);
        $this->assertSame('not_required', $event->verification_status);
        Queue::assertNothingPushed();
    }

    public function test_manual_emergency_queues_an_assigned_camera_snapshot(): void
    {
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Manual emergency camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.137.108',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);

        $id = $this->submit(['event_id' => 'emergency-with-camera', 'event_type' => 'manual_emergency',
            'is_simulated' => false])->assertCreated()->json('event_id');

        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $this->assertNull($event->reading_id);
        $this->assertSame('pending', $event->camera->status);
        $this->assertSame($camera->id, $event->camera->camera_device_id);
        $this->assertSame('pending', $event->status);
        $this->assertSame('not_required', $event->verification_status);
        Queue::assertPushed(CaptureEventImage::class);
    }

    public function test_manual_emergency_camera_can_upload_a_private_snapshot_directly(): void
    {
        Storage::fake('local');
        config(['monitor.camera_push_enabled' => true]);
        $cameraKey = Str::random(64);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Push camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', $cameraKey),
            'is_active' => true, 'is_simulated' => false]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'push-emergency',
            'event_type' => 'manual_emergency'])->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $verificationId = $event->camera->id;
        Queue::assertPushed(ExpirePendingCameraCapture::class);
        Queue::assertNotPushed(CaptureEventImage::class);

        $this->get('/api/v1/camera/pending', ['X-Device-Key' => $this->key])->assertForbidden();
        $this->get('/api/v1/camera/pending', ['X-Device-Key' => $cameraKey])
            ->assertOk()->assertSee((string) $verificationId);
        $jpeg = $this->tinyJpeg();
        $headers = ['HTTP_X_DEVICE_KEY' => $cameraKey, 'CONTENT_TYPE' => 'image/jpeg'];
        $this->call('POST', '/api/v1/camera/captures/'.$verificationId,
            [], [], [], $headers, $jpeg)->assertCreated()->assertJsonPath('duplicate', false);
        $snapshot = $event->camera->fresh();
        $this->assertSame('captured', $snapshot->status);
        $this->assertSame('pending', $event->fresh()->status);
        Storage::disk('local')->assertExists($snapshot->image_path);
        $this->assertSame($jpeg, Storage::disk('local')->get($snapshot->image_path));
        $this->call('POST', '/api/v1/camera/captures/'.$verificationId,
            [], [], [], $headers, $jpeg)->assertOk()->assertJsonPath('duplicate', true);
        $this->get('/api/v1/camera/pending', ['X-Device-Key' => $cameraKey])
            ->assertOk()->assertSee('0');
    }

    public function test_direct_upload_rejects_invalid_image_wrong_camera_and_stale_assignment(): void
    {
        Storage::fake('local');
        config(['monitor.camera_push_enabled' => true]);
        $cameraKey = Str::random(64);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Assigned push camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', $cameraKey),
            'is_active' => true, 'is_simulated' => false]);
        $this->assign($camera);
        $otherKey = Str::random(64);
        Device::create(['code' => Str::uuid(), 'name' => 'Different push camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', $otherKey),
            'is_active' => true, 'is_simulated' => false]);
        $id = $this->submit(['event_id' => 'push-security',
            'event_type' => 'manual_emergency'])->assertCreated()->json('event_id');
        $record = FallEvent::where('public_id', $id)->firstOrFail()->camera;
        $url = '/api/v1/camera/captures/'.$record->id;
        $headers = ['HTTP_X_DEVICE_KEY' => $cameraKey, 'CONTENT_TYPE' => 'image/jpeg'];
        $this->call('POST', $url, [], [], [], $headers, "\xFF\xD8broken\xFF\xD9")
            ->assertUnprocessable();
        $this->call('POST', $url, [], [], [],
            ['HTTP_X_DEVICE_KEY' => $otherKey, 'CONTENT_TYPE' => 'image/jpeg'],
            $this->tinyJpeg())->assertNotFound();
        $this->assertSame('pending', $record->fresh()->status);
        $camera->currentAssignment()->firstOrFail()->update(['unassigned_at' => now()]);
        $this->call('POST', $url, [], [], [], $headers, $this->tinyJpeg())->assertStatus(409);
        $this->assertNull($record->fresh()->image_path);
    }

    public function test_direct_upload_expires_without_changing_the_emergency_alert(): void
    {
        config(['monitor.camera_push_enabled' => true]);
        $cameraKey = Str::random(64);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Late push camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', $cameraKey),
            'is_active' => true, 'is_simulated' => false]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'push-expire',
            'event_type' => 'manual_emergency'])->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $record = $event->camera;
        $record->update(['created_at' => now()->subSeconds(50)]);
        (new ExpirePendingCameraCapture($record->id))->handle();
        $this->assertSame('timeout', $record->fresh()->status);
        $this->assertSame('pending', $event->fresh()->status);
        $this->get('/api/v1/camera/pending', ['X-Device-Key' => $cameraKey])
            ->assertOk()->assertSee('0');
    }

    public function test_push_rollout_never_competes_with_legacy_or_possible_fall_capture(): void
    {
        $cameraKey = Str::random(64);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Mixed mode camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', $cameraKey),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.91',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $legacyId = $this->submit(['event_id' => 'legacy-before-push',
            'event_type' => 'manual_emergency'])->assertCreated()->json('event_id');
        $legacy = FallEvent::where('public_id', $legacyId)->firstOrFail()->camera;
        Queue::assertPushed(CaptureEventImage::class);

        config(['monitor.camera_push_enabled' => true]);
        $this->get('/api/v1/camera/pending', ['X-Device-Key' => $cameraKey])
            ->assertOk()->assertSee('0');
        $this->call('POST', '/api/v1/camera/captures/'.$legacy->id,
            [], [], [], ['HTTP_X_DEVICE_KEY' => $cameraKey,
                'CONTENT_TYPE' => 'image/jpeg'], $this->tinyJpeg())->assertStatus(409);

        $fallId = $this->submit(['event_id' => 'fall-stays-pull',
            'event_type' => 'possible_fall',
            'sensor' => ['acceleration_g' => 3.1, 'gyro_rad_s' => 0.2]])
            ->assertCreated()->json('event_id');
        $fall = FallEvent::where('public_id', $fallId)->firstOrFail();
        Queue::assertPushed(CaptureEventImage::class, 2);
        $this->get('/api/v1/camera/pending', ['X-Device-Key' => $cameraKey])
            ->assertOk()->assertSee('0');
        $this->assertSame('pending', $fall->camera->status);
    }

    public function test_manual_emergency_saves_snapshot_without_waiting_for_pose_verification(): void
    {
        Storage::fake('local');
        config(['monitor.camera_verifier_url' => 'http://verifier.test/verify-fall',
            'monitor.camera_verifier_key' => Str::random(64)]);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Emergency snapshot camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.90',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'emergency-snapshot', 'event_type' => 'manual_emergency',
            'is_simulated' => false])->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $this->assertSame('pending', $event->camera->status);
        $jpeg = $this->tinyJpeg();
        Http::fake([
            'http://192.168.1.90/capture' => Http::response($jpeg, 200,
                ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg)]),
            'http://192.168.1.90:80/capture' => Http::response($jpeg, 200,
                ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg)]),
        ]);

        (new CaptureEventImage($event->camera->id))->handle();

        $snapshot = $event->camera->fresh();
        $this->assertSame('captured', $snapshot->status, $snapshot->result ?? 'No camera result');
        Http::assertSentCount(1);
        $this->assertSame('Emergency snapshot saved for caregiver review.', $snapshot->result);
        $this->assertNull($snapshot->verified_at);
        Storage::disk('local')->assertExists($snapshot->image_path);
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame('not_required', $event->fresh()->verification_status);
    }

    public function test_dashboard_charts_use_the_signed_in_users_event_scope(): void
    {
        $this->submit($this->payload())->assertCreated();
        $this->submit(['event_id' => 'caregiver-emergency', 'event_type' => 'manual_emergency',
            'is_simulated' => false])->assertCreated();

        $otherCaregiver = $this->user('caregiver');
        $otherPatient = Patient::create(['code' => Str::uuid(), 'full_name' => 'Other caregiver patient',
            'caregiver_id' => $otherCaregiver->id, 'is_active' => true]);
        $otherKey = Str::random(64);
        $otherDevice = Device::create(['code' => Str::uuid(), 'name' => 'Other physical controller',
            'type' => 'esp32', 'key_hash' => hash('sha256', $otherKey),
            'is_active' => true, 'is_simulated' => false]);
        DeviceAssignment::create(['device_id' => $otherDevice->id, 'patient_id' => $otherPatient->id,
            'device_type' => $otherDevice->type, 'assigned_at' => now()->subMinute()]);
        $this->postJson('/api/v1/events', ['event_id' => 'other-caregiver-emergency',
            'event_type' => 'manual_emergency', 'is_simulated' => false],
            ['X-Device-Key' => $otherKey])->assertCreated();

        $caregiverDashboard = $this->actingAs($this->caregiver)->get('/dashboard')
            ->assertOk()->assertSeeText('Monitoring activity')
            ->assertSeeText('Alerts in the last 7 days')->assertSeeText('Incident status')
            ->assertSee('dashboard-charts.css', false)
            ->assertSee('dashboard-charts.js', false);
        $this->assertSame(2, $caregiverDashboard->viewData('statusTotal'));
        $this->assertSame(2, $caregiverDashboard->viewData('activityChart')->sum('total'));

        $adminDashboard = $this->actingAs($this->administrator)->get('/dashboard')->assertOk();
        $this->assertSame(3, $adminDashboard->viewData('statusTotal'));
        $this->assertSame(3, $adminDashboard->viewData('activityChart')->sum('total'));
    }

    public function test_inactive_patient_cannot_receive_new_events(): void
    {
        $this->patient->update(['is_active' => false]);
        $this->submit($this->payload())->assertUnprocessable();
    }

    public function test_heartbeat_updates_health_without_creating_fall_events(): void
    {
        $this->postJson('/api/v1/heartbeat', ['sensor_ok' => true],
            ['X-Device-Key' => $this->key])->assertOk();
        $this->assertTrue($this->device->fresh()->isOnline());
        $this->assertTrue($this->device->fresh()->sensor_ok);
        $this->postJson('/api/v1/heartbeat', ['sensor_ok' => false],
            ['X-Device-Key' => $this->key])->assertOk();
        $this->assertFalse($this->device->fresh()->sensor_ok);
        $this->assertSame(0, FallEvent::where('device_id', $this->device->id)->count());
    }

    public function test_camera_heartbeat_refreshes_its_private_capture_ip(): void
    {
        $key = Str::random(64);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Heartbeat camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', $key),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.137.20',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.137.108'])
            ->postJson('/api/v1/heartbeat', ['sensor_ok' => true],
                ['X-Device-Key' => $key])->assertOk();

        $camera->refresh();
        $this->assertSame('192.168.137.108', $camera->camera_ip);
        $this->assertTrue($camera->sensor_ok);
        $this->assertTrue($camera->isOnline());
    }

    public function test_caregiver_scope_and_response_history_are_enforced(): void
    {
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $other = $this->user('caregiver');
        $this->actingAs($other)->get('/events/'.$id)->assertNotFound();
        $this->get('/events/'.$id.'/image')->assertNotFound();
        $this->actingAs($this->caregiver)->get('/users')->assertForbidden();
        $this->get('/events/'.$id)->assertOk();
        $this->patchJson('/events/'.$id, ['status' => 'acknowledged', 'note' => 'Checking patient now.'])->assertRedirect();
        $this->patchJson('/events/'.$id, ['status' => 'resolved', 'note' => 'Patient checked in person.'])->assertRedirect();
        $this->patchJson('/events/'.$id, ['status' => 'pending', 'note' => 'Attempt to reopen.'])->assertUnprocessable();
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $this->assertSame('resolved', $event->status);
        $this->assertSame('patient_safe', $event->caregiver_outcome);
        $this->assertSame(2, $event->updates()->count());
    }

    public function test_live_feed_contains_authorized_emergency_popup_context(): void
    {
        $id = $this->submit(['event_id' => 'popup-emergency-1',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');

        $response = $this->actingAs($this->caregiver)->getJson('/monitor/feed')->assertOk();
        $event = collect($response->json('events'))->firstWhere('public_id', $id);

        $this->assertNotNull($event);
        $this->assertSame('manual_emergency', $event['popup_kind']);
        $this->assertSame('Immediate patient check required', $event['patient_status']);
        $this->assertSame($this->patient->full_name, $event['patient']['name']);
        $this->assertSame($this->caregiver->name, $event['patient']['caregiver']);
        $this->assertSame((string) $this->device->code, $event['device']['code']);
        $this->assertSame('unavailable', $event['camera']['display_status']);
        $this->assertStringContainsString($id, $event['event_url']);
        $this->assertStringContainsString($id.'/acknowledge', $event['acknowledge_url']);
        $this->actingAs($this->caregiver)->get('/dashboard')
            ->assertOk()->assertSee('emergency-alert-layer', false)
            ->assertSee('emergency-alert-acknowledge', false);

        $otherCaregiver = $this->user('caregiver');
        $otherEvents = collect($this->actingAs($otherCaregiver)->getJson('/monitor/feed')
            ->assertOk()->json('events'));
        $this->assertFalse($otherEvents->contains('public_id', $id));
    }

    public function test_emergency_popup_keeps_snapshot_without_live_preview(): void
    {
        $cameraKey = Str::random(64);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Live test camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.90',
            'camera_port' => 80, 'camera_key' => $cameraKey]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'popup-live-camera',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');

        $feedEvent = collect($this->actingAs($this->caregiver)->getJson('/monitor/feed')
            ->assertOk()->json('events'))->firstWhere('public_id', $id);
        $this->assertArrayNotHasKey('live_url', $feedEvent['camera']);
        $this->assertNull($feedEvent['camera']['image_url']);

        $this->get("/patients/{$this->patient->id}/camera/live")->assertNotFound();
        $this->get(route('patients.show', $this->patient))
            ->assertOk()->assertDontSee('data-patient-camera', false);
        $this->get('/dashboard')
            ->assertOk()->assertDontSee('emergency-camera-live-tab', false);
        $this->assertSame('pending', FallEvent::where('public_id', $id)->firstOrFail()->camera->status);
    }

    public function test_offline_camera_connection_failure_does_not_wait_for_second_snapshot_attempt(): void
    {
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Offline test camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.91',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'offline-emergency-image',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('Failed to connect to camera');
        });

        (new CaptureEventImage($event->camera->id))->handle();

        $this->assertSame(1, $attempts);
        $this->assertSame('timeout', $event->camera->fresh()->status);
        $this->assertStringContainsString('same network', $event->camera->fresh()->result);
    }

    public function test_camera_read_timeout_reports_a_snapshot_timeout_without_claiming_a_hardware_fault(): void
    {
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Frame-stalled camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.92',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'stalled-emergency-image',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('Operation timed out with 0 bytes received');
        });

        (new CaptureEventImage($event->camera->id))->handle();

        $this->assertSame(1, $attempts);
        $this->assertSame('timeout', $event->camera->fresh()->status);
        $this->assertStringContainsString('snapshot request timed out', $event->camera->fresh()->result);
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_recently_online_camera_recovers_from_one_transient_connection_failure(): void
    {
        Storage::fake('local');
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Transient connection camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.93',
            'camera_port' => 80, 'camera_key' => Str::random(64), 'last_seen_at' => now()]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'transient-emergency-image',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $attempts = 0;
        $jpeg = $this->tinyJpeg();
        Http::fake(function () use (&$attempts, $jpeg) {
            if (++$attempts === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('Failed to connect to camera');
            }
            return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg',
                'Content-Length' => (string) strlen($jpeg)]);
        });

        (new CaptureEventImage($event->camera->id))->handle();

        $this->assertSame(2, $attempts);
        $record = $event->camera->fresh();
        $this->assertSame('captured', $record->status);
        $this->assertSame($jpeg, Storage::disk('local')->get($record->image_path));
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_recently_online_camera_retries_only_once_and_popup_preserves_the_failure_reason(): void
    {
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Unavailable connection camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.94',
            'camera_port' => 80, 'camera_key' => Str::random(64), 'last_seen_at' => now()]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'unavailable-emergency-image',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            ++$attempts;
            throw new \Illuminate\Http\Client\ConnectionException('Failed to connect to camera');
        });

        (new CaptureEventImage($event->camera->id))->handle();

        $this->assertSame(2, $attempts);
        $record = $event->camera->fresh();
        $this->assertSame('timeout', $record->status);
        $this->assertSame('pending', $event->fresh()->status);
        $feed = collect($this->actingAs($this->caregiver)->getJson('/monitor/feed')
            ->assertOk()->json('events'))->firstWhere('public_id', $id);
        $this->assertSame('unavailable', $feed['camera']['display_status']);
        $this->assertSame($record->result, $feed['camera']['result']);
        $this->assertStringContainsString('same network', $feed['camera']['result']);
    }

    public function test_camera_response_size_is_checked_before_downloading_the_body(): void
    {
        Storage::fake('local');
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Oversized response camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.95',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'oversized-emergency-image',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $attempts = 0;
        $rejected = 0;
        Http::fake(function ($request, $options) use (&$attempts, &$rejected) {
            ++$attempts;
            $this->assertFalse($options['stream'] ?? false);
            try {
                ($options['on_headers'])(new \GuzzleHttp\Psr7\Response(200, [
                    'Content-Length' => (string) (config('monitor.camera_max_image_bytes') + 1),
                ]));
            } catch (\RuntimeException $exception) {
                ++$rejected;
                throw $exception;
            }
            $this->fail('An oversized response must be rejected before reading its body.');
        });

        (new CaptureEventImage($event->camera->id))->handle();

        $this->assertSame(2, $attempts);
        $this->assertSame(2, $rejected);
        $this->assertSame('timeout', $event->camera->fresh()->status);
        $this->assertSame('pending', $event->fresh()->status);
        Storage::disk('local')->assertDirectoryEmpty('captures');
    }

    public function test_popup_acknowledgement_is_authorized_recorded_and_idempotent(): void
    {
        $id = $this->submit(['event_id' => 'popup-emergency-ack',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $otherCaregiver = $this->user('caregiver');

        $this->actingAs($otherCaregiver)->postJson('/events/'.$id.'/acknowledge')->assertNotFound();
        $this->actingAs($this->caregiver)->postJson('/events/'.$id.'/acknowledge')
            ->assertOk()->assertJsonPath('status', 'acknowledged')
            ->assertJsonPath('acknowledged_by', $this->caregiver->name);

        $event->refresh();
        $this->assertSame('acknowledged', $event->status);
        $this->assertSame($this->caregiver->id, $event->acknowledged_by);
        $this->assertNotNull($event->acknowledged_at);
        $this->assertSame(1, $event->updates()->count());
        $this->assertStringContainsString('live popup', $event->updates()->first()->note);

        $this->actingAs($this->caregiver)->postJson('/events/'.$id.'/acknowledge')
            ->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->assertSame(1, $event->updates()->count());
    }

    public function test_camera_failure_preserves_the_open_fall_alert(): void
    {
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Test camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.99',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        Queue::assertPushed(CaptureEventImage::class);
        Http::fake(['http://192.168.1.99/capture' => Http::response('', 503),
            'http://192.168.1.99:80/capture' => Http::response('', 503)]);
        (new CaptureEventImage($event->camera->id))->handle();
        Http::assertSentCount(2);
        $this->assertSame('timeout', $event->camera->fresh()->status);
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame('inconclusive', $event->fresh()->verification_status);
        $this->assertNull($event->camera->fresh()->image_path);
    }

    public function test_firmware_frame_failure_is_not_reported_as_an_incomplete_jpeg(): void
    {
        Storage::fake('local');
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Frame failure camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.94',
            'camera_port' => 80, 'camera_key' => Str::random(64), 'last_seen_at' => now()]);
        $this->assign($camera);
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        Http::fake(fn () => Http::response(['error' => 'capture_failed'], 503));

        (new CaptureEventImage($event->camera->id))->handle();

        Http::assertSentCount(2);
        $record = $event->camera->fresh();
        $this->assertStringContainsString('could not capture a frame', $record->result);
        $this->assertStringNotContainsString('complete JPEG', $record->result);
        $this->assertNull($record->image_path);
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame('inconclusive', $event->fresh()->verification_status);
        Storage::disk('local')->assertDirectoryEmpty('captures');
        $this->actingAs($this->caregiver)->getJson('/monitor/feed')->assertOk()
            ->assertJsonPath('events.0.camera.display_status', 'unavailable')
            ->assertJsonPath('events.0.camera.result', $record->result);
    }

    public function test_one_firmware_frame_failure_can_recover_without_losing_the_snapshot(): void
    {
        Storage::fake('local');
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Recovering frame camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.95',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'frame-recovery-emergency',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $jpeg = $this->tinyJpeg();
        Http::fakeSequence()->push(['error' => 'capture_failed'], 503)
            ->push($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg)]);

        (new CaptureEventImage($event->camera->id))->handle();

        Http::assertSentCount(2);
        $record = $event->camera->fresh();
        $this->assertSame('captured', $record->status);
        $this->assertSame($jpeg, Storage::disk('local')->get($record->image_path));
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_rejected_camera_key_is_not_retried_or_reported_as_a_hardware_failure(): void
    {
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Access rejection camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.96',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        Http::fake(fn () => Http::response(['error' => 'camera_key_required'], 401));

        (new CaptureEventImage($event->camera->id))->handle();

        Http::assertSentCount(1);
        $this->assertStringContainsString('camera access key', $event->camera->fresh()->result);
        $this->assertStringNotContainsString('camera power', $event->camera->fresh()->result);
        $this->assertNull($event->camera->fresh()->image_path);
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_truncated_jpeg_is_retried_and_never_marked_as_captured(): void
    {
        Storage::fake('local');
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Truncated JPEG camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.77',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $completeJpeg = $this->tinyJpeg();
        $truncatedJpeg = substr($completeJpeg, 0, -2);

        Http::fake([
            'http://192.168.1.77/capture' => Http::response($truncatedJpeg, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) strlen($completeJpeg),
            ]),
            'http://192.168.1.77:80/capture' => Http::response($truncatedJpeg, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) strlen($completeJpeg),
            ]),
        ]);

        (new CaptureEventImage($event->camera->id))->handle();

        Http::assertSentCount(2);
        $record = $event->camera->fresh();
        $this->assertSame('timeout', $record->status);
        $this->assertNull($record->image_path);
        $this->assertSame('inconclusive', $event->fresh()->verification_status);
        Storage::disk('local')->assertDirectoryEmpty('captures');
    }

    public function test_jpeg_with_valid_markers_but_broken_pixels_is_rejected(): void
    {
        Storage::fake('local');
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Corrupt JPEG camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.78',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit(['event_id' => 'broken-emergency-image',
            'event_type' => 'manual_emergency', 'is_simulated' => false])
            ->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $jpeg = $this->tinyJpeg();
        $corrupt = substr($jpeg, 0, -80)."\xFF\xD9";
        $this->assertIsArray(@getimagesizefromstring($corrupt));
        $response = Http::response($corrupt, 200, ['Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($corrupt)]);
        Http::fake(['http://192.168.1.78/capture' => $response,
            'http://192.168.1.78:80/capture' => $response]);

        (new CaptureEventImage($event->camera->id))->handle();

        Http::assertSentCount(2);
        $this->assertSame('timeout', $event->camera->fresh()->status);
        $this->assertNull($event->camera->fresh()->image_path);
        Storage::disk('local')->assertDirectoryEmpty('captures');
        $this->assertSame('pending', $event->fresh()->status);
        $path = 'captures/test-corrupt/'.$event->camera->id.'.jpg';
        Storage::disk('local')->put($path, $corrupt);
        $event->camera->update(['status' => 'captured', 'image_path' => $path, 'captured_at' => now()]);
        $this->assertFalse($event->camera->fresh()->hasDisplayableImage());
        $this->actingAs($this->caregiver)->get('/monitor/feed')->assertOk()
            ->assertJsonPath('events.0.camera.display_status', 'incomplete')
            ->assertJsonPath('events.0.camera.image_url', null);
    }

    public function test_pose_verdict_triages_falls_without_replacing_caregiver_review(): void
    {
        Storage::fake('local');
        config(['monitor.camera_verifier_url' => 'http://verifier.test/verify-fall',
            'monitor.camera_verifier_key' => Str::random(64)]);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Triage camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.88',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $jpeg = $this->tinyJpeg();

        $fallId = $this->submit($this->payload())->assertCreated()
            ->assertJsonPath('verification_status', 'verifying')->json('event_id');
        $fallEvent = FallEvent::where('public_id', $fallId)->firstOrFail();
        Http::fake([
            'http://192.168.1.88/capture' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'http://192.168.1.88:80/capture' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'http://verifier.test/verify-fall' => Http::response([
                'verdict' => 'fall', 'confidence' => 0.82, 'reason' => 'horizontal_lying_pose',
            ]),
        ]);
        (new CaptureEventImage($fallEvent->camera->id))->handle();
        $fallEvent->refresh();
        $cameraResult = $fallEvent->camera->fresh();
        $this->assertSame('fall', $cameraResult->verdict, $cameraResult->result ?? 'No camera result');
        $this->assertSame('likely_fall', $fallEvent->verification_status);
        $this->assertSame('pending', $fallEvent->status);
        $this->assertTrue($fallEvent->requiresImmediateAttention());
        $this->actingAs($this->caregiver)->get('/monitor/feed')->assertOk()->assertJsonFragment([
            'public_id' => $fallEvent->public_id, 'requires_attention' => true,
        ]);
    }

    public function test_no_fall_pose_verdict_stays_open_for_caregiver_review(): void
    {
        Storage::fake('local');
        config(['monitor.camera_verifier_url' => 'http://verifier.test/verify-fall',
            'monitor.camera_verifier_key' => Str::random(64)]);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'No-fall triage camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.88',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $jpeg = $this->tinyJpeg();

        $clearId = $this->submit($this->payload())->assertCreated()
            ->assertJsonPath('verification_status', 'verifying')->json('event_id');
        $clearEvent = FallEvent::where('public_id', $clearId)->firstOrFail();
        Http::fake([
            'http://192.168.1.88/capture' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'http://192.168.1.88:80/capture' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'http://verifier.test/verify-fall' => Http::response([
                'verdict' => 'no_fall', 'confidence' => 0.91, 'reason' => 'upright_or_non_fall_pose',
            ]),
        ]);
        (new CaptureEventImage($clearEvent->camera->id))->handle();
        $clearEvent->refresh();
        $this->assertSame('unlikely_fall', $clearEvent->verification_status);
        $this->assertSame('pending', $clearEvent->status);
        $this->assertFalse($clearEvent->requiresImmediateAttention());
        $this->assertSame('no_fall', $clearEvent->camera->fresh()->verdict);
        $this->actingAs($this->caregiver)->get('/monitor/feed')->assertOk()->assertJsonFragment([
            'public_id' => $clearEvent->public_id, 'requires_attention' => false,
        ]);
    }

    public function test_private_jpeg_is_saved_before_optional_verifier_failure(): void
    {
        Storage::fake('local');
        config(['monitor.camera_verifier_url' => 'http://verifier.test/verify-fall',
            'monitor.camera_verifier_key' => Str::random(64)]);
        $camera = Device::create(['code' => Str::uuid(), 'name' => 'Test camera',
            'type' => 'esp32_cam', 'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true, 'is_simulated' => false, 'camera_ip' => '192.168.1.99',
            'camera_port' => 80, 'camera_key' => Str::random(64)]);
        $this->assign($camera);
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        // Generated one-pixel JPEG, not a patient photograph.
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD8qqKKKAP/2Q==');
        $captureAttempts = 0;
        Http::fake(function ($request) use ($event, $jpeg, $camera, &$captureAttempts) {
            if (str_contains($request->url(), '/capture')) {
                $captureAttempts++;
                $this->assertTrue($request->hasHeader('X-Camera-Key', $camera->camera_key));
                if ($captureAttempts === 1) return Http::response('', 503);
                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }
            $record = $event->camera->fresh();
            $this->assertSame('captured', $record->status);
            Storage::disk('local')->assertExists($record->image_path);
            return Http::response('', 503);
        });
        (new CaptureEventImage($event->camera->id))->handle();
        Http::assertSentCount(3);
        $this->assertSame(2, $captureAttempts);
        $record = $event->camera->fresh();
        $this->assertSame('captured', $record->status);
        $this->assertSame('uncertain', $record->verdict);
        $this->assertSame('inconclusive', $event->fresh()->verification_status);
        $this->assertSame($jpeg, Storage::disk('local')->get($record->image_path));
        $this->assertSame('pending', $event->fresh()->status);
        $feedEvent = collect($this->actingAs($this->caregiver)->getJson('/monitor/feed')
            ->assertOk()->json('events'))->firstWhere('public_id', $id);
        $this->assertSame('captured', $feedEvent['camera']['display_status']);
        $this->assertStringContainsString('/events/'.$id.'/image?v=',
            $feedEvent['camera']['image_url']);
        $this->actingAs($this->caregiver)->get('/events/'.$id.'/image')
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->user('caregiver'))->get('/events/'.$id.'/image')->assertNotFound();
    }

    public function test_no_legacy_setting_can_reenable_simulation_routes_or_ingestion(): void
    {
        config(['monitor.simulation_enabled' => true]);
        $this->assertFalse(Route::has('simulation.index'));
        $this->assertFalse(Route::has('simulation.store'));
        $this->actingAs($this->administrator)->get('/simulation')->assertNotFound();
        $this->postJson('/simulation', ['scenario' => 'possible_fall'])->assertNotFound();
        $data = $this->payload();
        $data['is_simulated'] = true;
        $this->submit($data)->assertForbidden();
    }

    public function test_hardware_clients_need_no_source_flag_and_cannot_supply_camera_verdicts(): void
    {
        $data = $this->payload();
        unset($data['is_simulated']);
        $this->submit($data)->assertCreated();
        $this->submit($this->payload())->assertOk()->assertJsonPath('duplicate', true);
        $data['event_id'] = 'spoofed-camera-1';
        $data['camera_status'] = 'simulated';
        $this->submit($data)->assertUnprocessable()->assertJsonValidationErrors('camera_status');
    }

    public function test_legacy_device_keys_cannot_send_events_or_heartbeats(): void
    {
        $this->device->update(['is_simulated' => true, 'is_active' => true, 'last_seen_at' => now()]);
        $this->submit($this->payload())->assertUnauthorized();
        $this->postJson('/api/v1/heartbeat', ['sensor_ok' => true],
            ['X-Device-Key' => $this->key])->assertUnauthorized();
        $this->assertFalse($this->device->fresh()->isOnline());
    }

    public function test_device_registration_is_hardware_only(): void
    {
        $data = ['code' => 'REAL-'.Str::random(8), 'name' => 'Actual controller',
            'type' => 'esp32', 'is_active' => true, 'camera_port' => 80];
        $this->actingAs($this->administrator)->postJson('/devices', $data + ['is_simulated' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_simulated');
        $this->postJson('/devices', $data)->assertRedirect('/devices');
        $this->assertFalse(Device::where('code', $data['code'])->firstOrFail()->is_simulated);
        $this->get('/devices')->assertOk()->assertDontSee('name="is_simulated"', false)->assertDontSee('Simulation lab');
        $this->device->update(['is_simulated' => true]);
        $this->putJson('/devices/'.$this->device->id, $data)->assertNotFound();
        $this->postJson('/devices/'.$this->device->id.'/assign', ['patient_id' => $this->patient->id])->assertNotFound();
        $this->postJson('/devices/'.$this->device->id.'/rotate-key', ['confirm' => true])->assertNotFound();
    }

    public function test_legacy_events_and_readings_are_not_exposed_or_relabelled(): void
    {
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $event->update(['is_simulated' => true]);
        $event->reading->update(['is_simulated' => true]);
        $this->actingAs($this->administrator)->get('/dashboard')->assertOk()->assertDontSee($id);
        $this->get('/events?source=simulation')->assertOk()->assertDontSee($id);
        $this->get('/monitor/feed')->assertOk()->assertJsonCount(0, 'events');
        $this->get('/events/'.$id)->assertNotFound();
        $this->get('/events/'.$id.'/image')->assertNotFound();
        $this->patchJson('/events/'.$id, ['status' => 'resolved', 'note' => 'Should not update.'])->assertNotFound();
        $this->assertSame(0, $this->patient->events()->count());
        $this->assertSame(0, $this->patient->readings()->count());
        $this->assertDatabaseHas('fall_events', ['id' => $event->id, 'is_simulated' => true, 'status' => 'pending']);
    }

    public function test_archived_patient_and_room_are_not_available_for_monitoring(): void
    {
        $room = Room::create(['number' => 'ARCHIVED-'.Str::random(8), 'name' => 'Archived room', 'archived_at' => now()]);
        $this->patient->update(['archived_at' => now(), 'room_id' => $room->id, 'is_active' => false]);
        $this->actingAs($this->administrator)->get('/patients')->assertOk()->assertDontSee($this->patient->full_name);
        $this->get('/patients/'.$this->patient->id)->assertNotFound();
        $this->get('/patients/'.$this->patient->id.'/edit')->assertNotFound();
        $this->get('/rooms')->assertOk()->assertDontSee($room->number);
        $this->putJson('/rooms/'.$room->id, ['name' => 'Changed'])->assertNotFound();
        $this->postJson('/patients', ['code' => Str::random(10), 'full_name' => 'Real patient',
            'room_id' => $room->id, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('room_id');
    }

    public function test_event_view_displays_the_stored_pose_score(): void
    {
        $id = $this->submit($this->payload())->assertCreated()->json('event_id');
        $event = FallEvent::where('public_id', $id)->firstOrFail();
        $event->camera->update(['confidence' => 0.875]);
        $this->actingAs($this->caregiver)->get('/events/'.$id)->assertOk()->assertSee('87.5%');
    }
}
