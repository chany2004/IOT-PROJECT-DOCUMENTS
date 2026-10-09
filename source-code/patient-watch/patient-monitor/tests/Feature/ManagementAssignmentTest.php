<?php

namespace Tests\Feature;

use App\Models\{Device, DeviceAssignment, Patient, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagementAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private User $administrator;
    private User $caregiver;
    private User $otherCaregiver;

    protected function setUp(): void
    {
        parent::setUp();
        // Never let this management-flow test touch the live monitoring database.
        $this->assertSame('patient_monitor_test', config('database.connections.pgsql.database'));
        $this->administrator = $this->user('administrator', 'Admin test user');
        $this->caregiver = $this->user('caregiver', 'Assigned caregiver');
        $this->otherCaregiver = $this->user('caregiver', 'Other caregiver');
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::uuid().'@example.test',
            'password' => Str::random(32),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function patient(User $caregiver, string $name = 'Patient under care'): Patient
    {
        return Patient::create([
            'code' => 'PAT-'.Str::upper(Str::random(8)),
            'full_name' => $name,
            'caregiver_id' => $caregiver->id,
            'is_active' => true,
        ]);
    }

    private function device(string $name = 'Physical controller'): Device
    {
        return Device::create([
            'code' => 'ESP-'.Str::upper(Str::random(8)),
            'name' => $name,
            'type' => 'esp32',
            'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true,
            'is_simulated' => false,
        ]);
    }

    private function assign(Device $device, Patient $patient): DeviceAssignment
    {
        return DeviceAssignment::create([
            'device_id' => $device->id,
            'patient_id' => $patient->id,
            'device_type' => $device->type,
            'assigned_at' => now()->subMinute(),
            'assigned_by' => $this->administrator->id,
        ]);
    }

    public function test_sidebar_has_the_only_sign_out_action_for_both_roles(): void
    {
        foreach ([$this->administrator, $this->caregiver] as $user) {
            $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $html = $response->getContent();

            $this->assertSame(1, substr_count($html, 'class="sidebar-logout"'));
            $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'));
            $this->assertStringNotContainsString('PATIENT-END MONITORING', $html);
            $this->assertStringNotContainsString('Connected patient monitoring', $html);
        }
    }

    public function test_admin_can_unassign_then_assign_a_controller_to_another_patient(): void
    {
        $firstPatient = $this->patient($this->caregiver, 'First patient');
        $secondPatient = $this->patient($this->otherCaregiver, 'Second patient');
        $device = $this->device();

        $this->actingAs($this->administrator)
            ->put(route('patients.update', $firstPatient), [
                'code' => $firstPatient->code,
                'full_name' => $firstPatient->full_name,
                'caregiver_id' => $firstPatient->caregiver_id,
                'device_id' => $device->id,
                'is_active' => '1',
            ])
            ->assertRedirect(route('patients.show', $firstPatient));

        $current = DeviceAssignment::where('device_id', $device->id)->whereNull('unassigned_at')->firstOrFail();
        $this->assertSame($firstPatient->id, $current->patient_id);

        $this->actingAs($this->administrator)
            ->post(route('devices.assign', $device), ['patient_id' => ''])
            ->assertRedirect();

        $this->assertNull($device->fresh()->currentAssignment()->first());
        $this->assertNotNull(DeviceAssignment::where('device_id', $device->id)->firstOrFail()->unassigned_at);

        $this->actingAs($this->administrator)
            ->put(route('patients.update', $secondPatient), [
                'code' => $secondPatient->code,
                'full_name' => $secondPatient->full_name,
                'caregiver_id' => $secondPatient->caregiver_id,
                'device_id' => $device->id,
                'is_active' => '1',
            ])
            ->assertRedirect(route('patients.show', $secondPatient));

        $this->assertSame($secondPatient->id, $device->fresh()->currentAssignment()->firstOrFail()->patient_id);
        $this->assertSame(2, DeviceAssignment::where('device_id', $device->id)->count());
    }

    public function test_selecting_a_different_same_type_device_replaces_the_old_one(): void
    {
        $patient = $this->patient($this->caregiver);
        $oldDevice = $this->device('Old controller');
        $newDevice = $this->device('New controller');
        $this->assign($oldDevice, $patient);

        $this->actingAs($this->administrator)
            ->put(route('patients.update', $patient), [
                'code' => $patient->code,
                'full_name' => $patient->full_name,
                'caregiver_id' => $patient->caregiver_id,
                'device_id' => $newDevice->id,
                'is_active' => '1',
            ])
            ->assertRedirect(route('patients.show', $patient));

        $this->assertNull($oldDevice->fresh()->currentAssignment()->first());
        $this->assertSame($patient->id, $newDevice->fresh()->currentAssignment()->firstOrFail()->patient_id);
        $this->assertNotNull($oldDevice->assignments()->firstOrFail()->unassigned_at);
    }

    public function test_controller_registration_does_not_require_or_save_camera_settings(): void
    {
        $code = 'CTRL-'.Str::upper(Str::random(8));
        $this->actingAs($this->administrator)->post(route('devices.store'), [
            'code' => $code, 'name' => 'New controller', 'type' => 'esp32', 'is_active' => '1',
            'camera_ip' => '8.8.8.8', 'camera_key' => Str::random(64),
        ])->assertRedirect(route('devices.index'))->assertSessionHasNoErrors()->assertSessionHas('device_key');

        $device = Device::where('code', $code)->firstOrFail();
        $this->assertNull($device->camera_ip);
        $this->assertNull($device->camera_key);
        $this->assertSame(80, $device->camera_port);
    }

    public function test_device_registration_uses_an_admin_modal_only_when_requested(): void
    {
        $response = $this->actingAs($this->administrator)
            ->get(route('devices.index'))->assertOk()
            ->assertSee('data-open-device-registration', false)
            ->assertSee('id="register-device" class="device-registration-dialog"', false)
            ->assertSee('id="device-registration-loading"', false)
            ->assertSee('device-registration-modal.js', false)
            ->assertDontSee('class="panel device-create-panel"', false);
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog id="register-device"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );

        $response = $this->get(route('devices.index', ['register' => 1]))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<dialog id="register-device"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );

        $this->actingAs($this->caregiver)->get(route('devices.index'))->assertOk()
            ->assertDontSee('id="register-device"', false)
            ->assertDontSee('data-open-device-registration', false);
    }

    public function test_camera_validation_does_not_flash_capture_key_to_the_session(): void
    {
        $code = 'CAM-'.Str::upper(Str::random(8));
        $this->actingAs($this->administrator)->from(route('devices.index'))->post(route('devices.store'), [
            '_device_form' => 'register', 'code' => $code,
            'name' => 'Camera', 'type' => 'esp32_cam', 'is_active' => '1',
            'camera_ip' => '8.8.8.8', 'camera_port' => 80, 'camera_key' => Str::random(64),
        ])->assertRedirect(route('devices.index'))->assertSessionHasErrors('camera_ip')
            ->assertSessionHas('_old_input._device_form', 'register')
            ->assertSessionMissing('_old_input.camera_key');

        $response = $this->get(route('devices.index'))->assertOk()
            ->assertSee('value="'.$code.'"', false);
        $this->assertMatchesRegularExpression(
            '/<dialog id="register-device"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
    }

    public function test_failed_device_edit_reopens_its_form_and_keeps_entered_values(): void
    {
        $device = $this->device();
        $this->actingAs($this->administrator)->from(route('devices.index'))->put(route('devices.update', $device), [
            '_device_form' => 'edit-'.$device->id, 'code' => 'not valid!',
            'name' => 'Retained device name', 'type' => 'esp32', 'is_active' => '1',
        ])->assertRedirect(route('devices.index'))->assertSessionHasErrors('code');

        $response = $this->get(route('devices.index'))->assertOk()
            ->assertSee('value="Retained device name"', false);
        $this->assertMatchesRegularExpression(
            '/<dialog id="device-'.$device->id.'-edit"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog id="register-device"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
        $this->assertNotSame('Retained device name', $device->fresh()->name);
    }

    public function test_disabled_device_assignment_returns_validation_error_but_can_be_unassigned(): void
    {
        $patient = $this->patient($this->caregiver);
        $device = $this->device();
        $device->update(['is_active' => false]);

        $this->actingAs($this->administrator)->from(route('devices.index'))->post(route('devices.assign', $device), [
            'patient_id' => $patient->id,
        ])->assertRedirect(route('devices.index'))->assertSessionHasErrors('patient_id');
        $this->assertNull($device->fresh()->currentAssignment);

        $assignment = $this->assign($device, $patient);
        $this->post(route('devices.assign', $device), ['patient_id' => ''])->assertRedirect();
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertNull($device->fresh()->currentAssignment);
    }

    public function test_assignment_modal_opens_on_link_or_validation_error(): void
    {
        $device = $this->device();

        $response = $this->actingAs($this->administrator)
            ->get(route('devices.index'))->assertOk()
            ->assertSeeText('Manage patient assignment')
            ->assertSee('aria-controls="device-'.$device->id.'-assignment"', false);
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog id="device-'.$device->id.'-assignment"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );

        $response = $this->get(route('devices.index', ['device_action' => 'assign-'.$device->id]))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<dialog id="device-'.$device->id.'-assignment"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );

        foreach (['edit', 'security'] as $action) {
            $response = $this->get(route('devices.index', ['device_action' => $action.'-'.$device->id]))->assertOk();
            $this->assertMatchesRegularExpression(
                '/<dialog id="device-'.$device->id.'-'.$action.'"[^>]*\sopen(?:\s|>)/',
                $response->getContent()
            );
        }

        $this->from(route('devices.index'))
            ->post(route('devices.assign', $device), [
                '_device_form' => 'assign-'.$device->id,
                'patient_id' => 'not-a-patient',
            ])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHasErrors('patient_id');

        $response = $this->get(route('devices.index'))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<dialog id="device-'.$device->id.'-assignment"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
    }

    public function test_key_rotation_validation_reopens_only_its_modal(): void
    {
        $device = $this->device();

        $this->actingAs($this->administrator)->from(route('devices.index'))
            ->post(route('devices.rotate', $device), [
                '_device_form' => 'security-'.$device->id,
            ])
            ->assertSessionHasErrors('confirm');

        $response = $this->get(route('devices.index'))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<dialog id="device-'.$device->id.'-security"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog id="device-'.$device->id.'-edit"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
        $response->assertSeeText('Please confirm the key rotation.');
    }

    public function test_successful_modal_actions_return_to_closed_registry(): void
    {
        $device = $this->device();
        $patient = $this->patient($this->caregiver);

        $this->actingAs($this->administrator)
            ->from(route('devices.index', ['device_action' => 'assign-'.$device->id]))
            ->post(route('devices.assign', $device), [
                '_device_form' => 'assign-'.$device->id,
                'patient_id' => $patient->id,
            ])
            ->assertRedirect(route('devices.index'));

        $this->from(route('devices.index', ['device_action' => 'security-'.$device->id]))
            ->post(route('devices.rotate', $device), [
                '_device_form' => 'security-'.$device->id,
                'confirm' => '1',
            ])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('device_key');

        $response = $this->get(route('devices.index'))->assertOk()
            ->assertSeeText('New key for '.$device->code);
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog id="device-'.$device->id.'-security"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
    }

    public function test_device_assignment_replaces_same_type_and_is_idempotent(): void
    {
        $patient = $this->patient($this->caregiver);
        $old = $this->device('Previous sensor');
        $replacement = $this->device('Replacement sensor');
        $assignment = $this->assign($old, $patient);

        $this->actingAs($this->administrator)->post(route('devices.assign', $replacement), [
            'patient_id' => $patient->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertSame($patient->id, $replacement->fresh()->currentAssignment->patient_id);

        $this->post(route('devices.assign', $replacement), ['patient_id' => $patient->id])->assertRedirect();
        $this->assertSame(1, $replacement->assignments()->count());
        $this->assertSame(1, $patient->currentAssignments()->count());
    }

    public function test_camera_edit_retains_encrypted_key_and_rotation_requires_confirmation(): void
    {
        $device = $this->device('Camera under test');
        $captureKey = Str::random(64);
        $device->update(['type' => 'esp32_cam', 'camera_ip' => '192.168.1.12', 'camera_key' => $captureKey]);

        $this->actingAs($this->administrator)->put(route('devices.update', $device), [
            'code' => $device->code, 'name' => 'Updated camera', 'type' => 'esp32_cam',
            'is_active' => '1', 'camera_ip' => '192.168.1.13', 'camera_port' => 80, 'camera_key' => '',
        ])->assertRedirect(route('devices.index'))->assertSessionHasNoErrors();
        $this->assertSame($captureKey, $device->fresh()->camera_key);
        $this->assertNotSame($captureKey, $device->fresh()->getRawOriginal('camera_key'));
        $previousHash = $device->fresh()->key_hash;

        $this->post(route('devices.rotate', $device), [])->assertSessionHasErrors('confirm');
        $this->assertSame($previousHash, $device->fresh()->key_hash);
        $this->post(route('devices.rotate', $device), ['confirm' => '1'])
            ->assertRedirect()->assertSessionHas('device_key');
        $this->assertNotSame($previousHash, $device->fresh()->key_hash);
        $this->get(route('devices.index'))->assertOk()->assertDontSee($captureKey);

        $this->actingAs($this->caregiver)->post(route('devices.rotate', $device), ['confirm' => '1'])->assertForbidden();
        $this->put(route('devices.update', $device), [])->assertForbidden();
        $this->post(route('devices.store'), [])->assertForbidden();
    }

    public function test_caregiver_empty_registry_explains_assignment_not_registration(): void
    {
        $this->actingAs($this->caregiver)->get(route('devices.index'))->assertOk()
            ->assertSeeText('No hardware assigned yet')->assertDontSeeText('Register first device');
    }

    public function test_caregiver_sees_only_assigned_patients_and_devices(): void
    {
        $visiblePatient = $this->patient($this->caregiver, 'Visible patient');
        $hiddenPatient = $this->patient($this->otherCaregiver, 'Hidden patient');
        $device = $this->device();
        $this->assign($device, $visiblePatient);

        $this->actingAs($this->caregiver)
            ->get(route('patients.index'))
            ->assertOk()
            ->assertSeeText($visiblePatient->full_name)
            ->assertDontSeeText($hiddenPatient->full_name);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText($visiblePatient->full_name)
            ->assertDontSeeText($hiddenPatient->full_name);

        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSeeText($device->code)
            ->assertDontSeeText('Update assignment')
            ->assertDontSee('data-open-device-action', false);

        $this->get(route('patients.show', $visiblePatient))->assertOk();
        $this->get(route('patients.show', $hiddenPatient))->assertNotFound();
        $this->get(route('patients.edit', $visiblePatient))->assertForbidden();
        $this->post(route('devices.assign', $device), ['patient_id' => $hiddenPatient->id])->assertForbidden();
    }

    public function test_add_patient_button_opens_admin_modal_and_keeps_direct_form_available(): void
    {
        $this->actingAs($this->administrator)
            ->get(route('patients.index'))
            ->assertOk()
            ->assertSee('id="add-patient-trigger"', false)
            ->assertSee('id="create-patient"', false)
            ->assertSee('id="patient-modal-loading"', false)
            ->assertSee('id="patient-modal-submit"', false)
            ->assertSee('name="_patient_form" value="create"', false);

        $response = $this->get(route('patients.index', ['create' => 1]))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<dialog id="create-patient"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
        $this->get(route('patients.create'))->assertOk()->assertSeeText('Patient details');

        $this->actingAs($this->caregiver)
            ->get(route('patients.index'))
            ->assertOk()
            ->assertDontSee('id="create-patient"', false)
            ->assertDontSee('id="add-patient-trigger"', false);
    }

    public function test_patient_modal_validation_reopens_with_old_values_and_success_keeps_existing_redirect(): void
    {
        $code = 'PAT-'.Str::upper(Str::random(8));
        $this->actingAs($this->administrator)
            ->from(route('patients.index'))
            ->post(route('patients.store'), [
                '_patient_form' => 'create',
                'code' => $code,
                'full_name' => '',
                'is_active' => '1',
            ])
            ->assertRedirect(route('patients.index'))
            ->assertSessionHasErrors('full_name');

        $response = $this->get(route('patients.index'))->assertOk()
            ->assertSee('value="'.$code.'"', false);
        $this->assertMatchesRegularExpression(
            '/<dialog id="create-patient"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );

        $this->post(route('patients.store'), [
            '_patient_form' => 'create',
            'code' => $code,
            'full_name' => 'New modal patient',
            'caregiver_id' => $this->caregiver->id,
            'is_active' => '1',
        ])->assertRedirect(route('patients.show', Patient::where('code', $code)->firstOrFail()));
    }

    public function test_account_directory_has_search_filters_and_assignment_summary(): void
    {
        $this->patient($this->caregiver, 'Directory assignment patient');
        $inactive = $this->user('caregiver', 'Inactive directory user');
        $inactive->update(['is_active' => false]);

        $this->actingAs($this->administrator)
            ->get(route('users.index', ['q' => 'Assigned', 'role' => 'caregiver', 'status' => 'active']))
            ->assertOk()
            ->assertSeeText($this->caregiver->name)
            ->assertSeeText('Active caregivers')
            ->assertSeeText('1 active patient')
            ->assertSee('class="account-identity"', false)
            ->assertSee('class="account-row-actions"', false)
            ->assertDontSeeText('View patients')
            ->assertDontSeeText('Inactive directory user');
    }

    public function test_add_user_button_opens_the_creation_modal_without_javascript(): void
    {
        $this->actingAs($this->administrator)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('id="add-user-trigger"', false)
            ->assertSee(route('users.index', ['create' => 1]).'#create-account', false);

        $response = $this->get(route('users.index', ['create' => 1]))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<dialog id="create-account"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
    }

    public function test_create_account_returns_to_closed_directory_and_validation_reopens_modal(): void
    {
        $email = Str::uuid().'@example.test';
        $this->actingAs($this->administrator)
            ->from(route('users.index', ['create' => 1]))
            ->post(route('users.store'), [
                '_user_form' => 'create',
                'name' => 'New caregiver',
                'email' => $email,
                'role' => 'caregiver',
                'is_active' => '1',
                'password' => 'A-secure-password-123',
                'password_confirmation' => 'A-secure-password-123',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'Account created.');

        $this->assertSame('caregiver', User::where('email', $email)->firstOrFail()->role);

        $this->from(route('users.index'))
            ->post(route('users.store'), [
                '_user_form' => 'create',
                'name' => 'Missing email',
                'email' => '',
                'role' => 'caregiver',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('email');

        $response = $this->get(route('users.index'))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<dialog id="create-account"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );
        $response->assertSee('class="account-modal-errors"', false);
    }

    public function test_edit_account_opens_a_modal_reopens_on_error_and_closes_after_save(): void
    {
        $editUrl = route('users.index', ['edit' => $this->caregiver->id]);
        $this->actingAs($this->administrator);
        $response = $this->get($editUrl)->assertOk()
            ->assertSee('aria-controls="edit-account-'.$this->caregiver->id.'"', false)
            ->assertDontSee('class="account-edit"', false);
        $this->assertMatchesRegularExpression(
            '/<dialog id="edit-account-'.$this->caregiver->id.'"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );

        $this->from($editUrl)->put(route('users.update', $this->caregiver), [
            '_user_form' => 'edit-'.$this->caregiver->id,
            'name' => 'Updated caregiver',
            'email' => '',
            'role' => 'caregiver',
            'is_active' => '1',
        ])->assertSessionHasErrors('email');

        $response = $this->get($editUrl)->assertOk()
            ->assertSee('class="account-modal-errors"', false)
            ->assertSee('value="Updated caregiver"', false);
        $this->assertMatchesRegularExpression(
            '/<dialog id="edit-account-'.$this->caregiver->id.'"[^>]*\sopen(?:\s|>)/',
            $response->getContent()
        );

        $filters = ['role' => 'caregiver', 'status' => 'active'];
        $this->from($editUrl)->put(route('users.update', array_merge(['user' => $this->caregiver], $filters)), [
            '_user_form' => 'edit-'.$this->caregiver->id,
            'name' => 'Updated caregiver',
            'email' => $this->caregiver->email,
            'role' => 'caregiver',
            'is_active' => '1',
        ])->assertRedirect(route('users.index', $filters))
            ->assertSessionHas('success', 'Account updated.');

        $closedResponse = $this->get(route('users.index', $filters))->assertOk();
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog id="edit-account-'.$this->caregiver->id.'"[^>]*\sopen(?:\s|>)/',
            $closedResponse->getContent()
        );
        $this->assertSame('Updated caregiver', $this->caregiver->fresh()->name);
    }

    public function test_administrator_cannot_change_own_role_or_status(): void
    {
        $this->actingAs($this->administrator)
            ->put(route('users.update', $this->administrator), [
                '_user_form' => 'edit-'.$this->administrator->id,
                'name' => $this->administrator->name,
                'email' => $this->administrator->email,
                'role' => 'caregiver',
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $this->assertSame('administrator', $this->administrator->fresh()->role);
    }

    public function test_caregiver_with_patients_cannot_be_deactivated_or_promoted(): void
    {
        $this->patient($this->caregiver, 'Protected assignment patient');

        $this->actingAs($this->administrator)
            ->get(route('users.index', ['edit' => $this->caregiver->id]))
            ->assertOk()
            ->assertDontSeeText('Reassign this caregiver');

        $this->actingAs($this->administrator)
            ->put(route('users.update', $this->caregiver), [
                '_user_form' => 'edit-'.$this->caregiver->id,
                'name' => $this->caregiver->name,
                'email' => $this->caregiver->email,
                'role' => 'administrator',
                'is_active' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $this->assertSame('caregiver', $this->caregiver->fresh()->role);
        $this->assertTrue($this->caregiver->fresh()->is_active);
    }
}
