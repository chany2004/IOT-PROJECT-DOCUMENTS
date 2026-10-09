<?php

namespace Tests\Feature;

use App\Models\{Device, DeviceAssignment, Patient, Room, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoomManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $caregiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('patient_monitor_test', config('database.connections.pgsql.database'));
        $this->admin = $this->user('administrator');
        $this->caregiver = $this->user('caregiver');
    }

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role).' room test', 'email' => Str::uuid().'@example.test',
            'password' => Str::random(32), 'role' => $role, 'is_active' => true]);
    }

    private function room(array $attributes = []): Room
    {
        return Room::create(array_merge(['number' => 'ROOM-'.Str::upper(Str::random(8)),
            'name' => 'East wing room', 'floor' => '2'], $attributes));
    }

    private function patient(array $attributes = []): Patient
    {
        return Patient::create(array_merge(['code' => 'PAT-'.Str::upper(Str::random(8)),
            'full_name' => 'Room assignment patient', 'caregiver_id' => $this->caregiver->id,
            'is_active' => true], $attributes));
    }

    public function test_room_directory_shows_occupancy_care_context_and_natural_order(): void
    {
        $prefix = 'SORT-'.Str::upper(Str::random(8)).'-';
        $available = $this->room(['number' => $prefix.'2']);
        $occupied = $this->room(['number' => $prefix.'10']);
        $patient = $this->patient(['room_id' => $occupied->id]);
        $waiting = $this->patient(['full_name' => 'Waiting for room']);
        $device = Device::create(['code' => 'DEV-'.Str::random(8), 'name' => 'Online sensor',
            'type' => 'esp32', 'is_active' => true, 'is_simulated' => false,
            'key_hash' => hash('sha256', Str::random(64)), 'last_seen_at' => now()]);
        DeviceAssignment::create(['patient_id' => $patient->id, 'device_id' => $device->id,
            'device_type' => 'esp32', 'assigned_at' => now(), 'assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('rooms.index'))->assertOk()
            ->assertSeeText('Rooms & occupancy')->assertSeeText('Awaiting a room')
            ->assertSeeText($this->caregiver->name)->assertSeeText('1 / 2 assigned')->assertSeeText('1 online')
            ->assertSeeInOrder(['Room '.$available->number, 'Room '.$occupied->number])
            ->assertViewHas('unassignedPatients', fn ($patients) => $patients->contains('id', $waiting->id)
                && !$patients->contains('id', $patient->id));
    }

    public function test_room_creation_and_edit_preserve_assignment_and_validate_unique_number(): void
    {
        $number = 'NEW-'.Str::random(8);
        $this->actingAs($this->admin)->post(route('rooms.store'), [
            'number' => $number, 'name' => 'New room', 'floor' => 'Ground', 'notes' => 'Accessible entry',
        ])->assertRedirect(route('rooms.index'))->assertSessionHasNoErrors();
        $room = Room::where('number', $number)->firstOrFail();
        $patient = $this->patient(['room_id' => $room->id]);
        $this->put(route('rooms.update', $room), [
            'number' => $number, 'name' => 'Updated room', 'floor' => 'Ground', 'notes' => 'Equipment shelf',
            'patient_id' => null,
        ])->assertRedirect(route('rooms.index'))->assertSessionHasNoErrors();
        $this->assertSame($room->id, $patient->fresh()->room_id);
        $this->assertSame('Updated room', $room->fresh()->name);
        $this->post(route('rooms.store'), ['number' => $number, 'name' => 'Duplicate'])
            ->assertSessionHasErrors('number');
    }

    public function test_failed_room_edit_reopens_only_its_form_and_restores_values(): void
    {
        $room = $this->room();
        $other = $this->room();
        $this->actingAs($this->admin)->from(route('rooms.index'))->put(route('rooms.update', $room), [
            '_room_form' => 'edit-'.$room->id, 'number' => $other->number,
            'name' => 'Keep this entered value', 'floor' => 'Third', 'notes' => '<script>room note</script>',
        ])->assertRedirect(route('rooms.index'))->assertSessionHasErrors('number');
        $this->get(route('rooms.index'))->assertOk()->assertSee('class="room-edit"  open', false)
            ->assertSee('value="Keep this entered value"', false)
            ->assertDontSee('id="create-room" class="panel room-create"  open', false)
            ->assertSee('&lt;script&gt;room note&lt;/script&gt;', false)
            ->assertDontSee('<script>room note</script>', false);
        $this->assertSame('East wing room', $room->fresh()->name);
    }

    public function test_failed_room_creation_restores_form_and_field_errors(): void
    {
        $room = $this->room();
        $this->actingAs($this->admin)->from(route('rooms.index'))->post(route('rooms.store'), [
            '_room_form' => 'create', 'number' => $room->number, 'name' => 'Unsubmitted room',
        ])->assertRedirect(route('rooms.index'))->assertSessionHasErrors('number');
        $this->get(route('rooms.index'))->assertOk()->assertSee('id="create-room" class="panel room-create"  open', false)
            ->assertSee('value="Unsubmitted room"', false)->assertSee('room-field-error', false);
    }

    public function test_admin_can_assign_unplaced_patient_and_repeat_without_changes(): void
    {
        $room = $this->room();
        $patient = $this->patient();
        $payload = ['patient_id' => $patient->id, '_room_form' => 'assign-'.$room->id];
        $this->actingAs($this->admin)->post(route('rooms.assign', $room), $payload)
            ->assertRedirect(route('rooms.index'))->assertSessionHasNoErrors();
        $this->assertSame($room->id, $patient->fresh()->room_id);
        $this->post(route('rooms.assign', $room), $payload)->assertRedirect(route('rooms.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, Patient::where('room_id', $room->id)->count());
    }

    public function test_room_assignment_cannot_replace_occupant_or_move_assigned_patient(): void
    {
        $room = $this->room();
        $otherRoom = $this->room();
        $occupant = $this->patient(['room_id' => $room->id]);
        $waiting = $this->patient();
        $this->actingAs($this->admin)->from(route('rooms.index'))
            ->post(route('rooms.assign', $room), ['patient_id' => $waiting->id])
            ->assertRedirect(route('rooms.index'))->assertSessionHasErrors('patient_id');
        $this->assertNull($waiting->fresh()->room_id);
        $this->post(route('rooms.assign', $otherRoom), ['patient_id' => $occupant->id])
            ->assertSessionHasErrors('patient_id');
        $this->assertSame($room->id, $occupant->fresh()->room_id);
        $this->assertNull($otherRoom->fresh()->patient);
    }

    public function test_inactive_archived_and_reserved_records_cannot_receive_room_assignment(): void
    {
        $room = $this->room();
        $inactive = $this->patient(['is_active' => false]);
        $archived = $this->patient(['archived_at' => now(), 'room_id' => $room->id, 'full_name' => 'Archived private name']);
        $waiting = $this->patient();
        $this->actingAs($this->admin);
        foreach ([$inactive, $archived] as $patient) {
            $this->post(route('rooms.assign', $room), ['patient_id' => $patient->id])->assertSessionHasErrors('patient_id');
        }
        $this->post(route('rooms.assign', $room), ['patient_id' => $waiting->id])->assertSessionHasErrors('patient_id');
        $this->assertNull($waiting->fresh()->room_id);
        $this->get(route('rooms.index'))->assertOk()->assertSeeText('Reserved room')
            ->assertDontSeeText('Archived private name')->assertDontSee(route('rooms.assign', $room), false);
        $hiddenRoom = $this->room(['archived_at' => now()]);
        $this->post(route('rooms.assign', $hiddenRoom), ['patient_id' => $waiting->id])->assertNotFound();
    }

    public function test_room_management_is_administrator_only(): void
    {
        $room = $this->room();
        $patient = $this->patient();
        $this->actingAs($this->caregiver)->get(route('rooms.index'))->assertForbidden();
        $this->post(route('rooms.store'), ['number' => 'unauthorized', 'name' => 'Room'])->assertForbidden();
        $this->put(route('rooms.update', $room), ['number' => $room->number, 'name' => 'Changed'])->assertForbidden();
        $this->post(route('rooms.assign', $room), ['patient_id' => $patient->id])->assertForbidden();
        $this->assertNull($patient->fresh()->room_id);
        $this->assertSame('East wing room', $room->fresh()->name);
    }
}
