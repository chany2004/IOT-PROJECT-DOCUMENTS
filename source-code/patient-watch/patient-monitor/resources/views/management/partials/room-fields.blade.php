@php
    $failed = $errors->any() && old('_room_form') === $formKey;
    $roomValue = fn ($field) => $failed ? old($field) : $room?->{$field};
@endphp
<div class="form-grid">
    <label>Room number<input name="number" required maxlength="40" value="{{ $roomValue('number') }}" placeholder="e.g. 3 or A-101">@if($failed) @error('number')<small class="room-field-error">{{ $message }}</small>@enderror @endif</label>
    <label>Floor <small>(optional)</small><input name="floor" maxlength="40" value="{{ $roomValue('floor') }}" placeholder="e.g. 2 or Ground">@if($failed) @error('floor')<small class="room-field-error">{{ $message }}</small>@enderror @endif</label>
</div>
<label>Room name<input name="name" required maxlength="100" value="{{ $roomValue('name') }}" placeholder="e.g. East Wing Room 3">@if($failed) @error('name')<small class="room-field-error">{{ $message }}</small>@enderror @endif</label>
<label>Room notes <small>(optional)</small><textarea name="notes" rows="3" maxlength="1000" placeholder="Room location, accessibility, or equipment notes">{{ $roomValue('notes') }}</textarea><small class="field-hint">Keep notes about the room. Record medical information in the patient profile.</small>@if($failed) @error('notes')<small class="room-field-error">{{ $message }}</small>@enderror @endif</label>
