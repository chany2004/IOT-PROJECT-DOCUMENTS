@extends('layouts.app')
@section('title', 'User accounts')
@section('content')
@php($formKey = old('_user_form'))
<div class="account-page">
    <div class="page-heading account-page-heading">
        <div>
            <div class="eyebrow">ACCESS MANAGEMENT</div>
            <h1>User accounts</h1>
            <p>Manage administrator access and the caregivers responsible for patient alerts.</p>
        </div>
        <a id="add-user-trigger" class="button account-add-button" data-account-modal-trigger href="{{ route('users.index', ['create' => 1]) }}#create-account" aria-haspopup="dialog" aria-controls="create-account"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12"></path></svg>Add user</a>
    </div>

    <div class="account-metrics" aria-label="Account and care assignment summary">
        <div class="account-metric"><span>Active caregivers</span><strong>{{ $activeCaregivers }}</strong><small>Available for patient assignment</small></div>
        <div class="account-metric"><span>Active administrators</span><strong>{{ $activeAdministrators }}</strong><small>Manage access and hardware</small></div>
        <div class="account-metric {{ $unassignedPatients ? 'needs-assignment' : '' }}"><span>Patients without caregiver</span><strong>{{ $unassignedPatients }}</strong><small>{{ $unassignedPatients ? 'Assign a caregiver in Patients' : 'All active patients have a caregiver' }}</small></div>
    </div>

    <dialog id="create-account" class="account-modal" aria-labelledby="create-account-title" aria-describedby="create-account-description" @if($formKey === 'create' || (!$formKey && request()->boolean('create'))) open @endif>
        <header class="account-modal-header">
            <span class="account-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 20a5.5 5.5 0 0 1 11 0M18 8v6M15 11h6"></path></svg></span>
            <div><h2 id="create-account-title">Add a user account</h2><p id="create-account-description">Create a caregiver or administrator with the right level of access.</p></div>
            <form method="dialog" class="account-modal-close-form"><button class="account-modal-close" type="submit" aria-label="Close Add user"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 5l10 10M15 5 5 15"></path></svg></button></form>
        </header>
        <form method="post" action="{{ route('users.store') }}" class="account-form account-modal-form">
            @csrf
            <input type="hidden" name="_user_form" value="create">
            <div class="account-modal-body">
            @if($formKey === 'create' && $errors->any())
                <div class="account-modal-errors" role="alert" tabindex="-1"><strong>Please check the account details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <section class="account-create-section" aria-labelledby="account-profile-title">
                <div class="account-section-heading"><span class="account-step">01</span><div><h3 id="account-profile-title">Profile</h3><p>Use the name and email this person will sign in with.</p></div></div>
                <div class="account-form-grid">
                    <label for="new-user-name">Full name<input id="new-user-name" name="name" required maxlength="150" autocomplete="name" value="{{ $formKey === 'create' ? old('name') : '' }}" placeholder="e.g. Maria Santos"></label>
                    <label for="new-user-email">Email address<input id="new-user-email" type="email" name="email" required maxlength="255" autocomplete="email" value="{{ $formKey === 'create' ? old('email') : '' }}" placeholder="name@example.com"></label>
                </div>
            </section>
            <section class="account-create-section" aria-labelledby="account-access-title">
                <div class="account-section-heading"><span class="account-step">02</span><div><h3 id="account-access-title">Access</h3><p>Set what this person can manage and when the account becomes available.</p></div></div>
                <div class="account-form-grid">
                    <label for="new-user-role">Role<select id="new-user-role" name="role"><option value="caregiver" @selected($formKey === 'create' && old('role') === 'caregiver')>Caregiver</option><option value="administrator" @selected($formKey === 'create' && old('role') === 'administrator')>Administrator</option></select><small>Caregivers see only their assigned patients.</small></label>
                    <label for="new-user-status">Account status<select id="new-user-status" name="is_active"><option value="1" @selected($formKey !== 'create' || old('is_active') == '1')>Active</option><option value="0" @selected($formKey === 'create' && old('is_active') == '0')>Inactive</option></select><small>Inactive accounts cannot sign in.</small></label>
                </div>
            </section>
            <section class="account-create-section" aria-labelledby="account-security-title">
                <div class="account-section-heading"><span class="account-step">03</span><div><h3 id="account-security-title">Security</h3><p>Set an initial password for the new account.</p></div></div>
                <div class="account-form-grid">
                    <label for="new-user-password">Password<input id="new-user-password" type="password" name="password" required minlength="12" autocomplete="new-password"><small>Use at least 12 characters.</small></label>
                    <label for="new-user-password-confirmation">Confirm password<input id="new-user-password-confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
                </div>
            </section>
            </div>
            <div class="account-form-footer account-modal-footer"><span>Assign patients after creating an active caregiver account.</span><div class="account-modal-actions"><button class="button subtle" type="submit" formmethod="dialog" formnovalidate>Cancel</button><button class="button" type="submit">Create account</button></div></div>
        </form>
    </dialog>

    <section class="panel account-directory" aria-labelledby="account-directory-title">
        <div class="account-directory-heading">
            <div><h2 id="account-directory-title">Account directory</h2><p>Review access and patient workload before changing an account.</p></div>
            <span class="account-result-count">{{ $users->total() }} {{ $users->total() === 1 ? 'account' : 'accounts' }}</span>
        </div>
        <form class="account-filters" method="get" action="{{ route('users.index') }}" role="search" aria-label="Filter user accounts">
            <label>Search name or email<input type="search" name="q" maxlength="100" value="{{ request('q') }}" placeholder="Find a person"></label>
            <label>Role<select name="role"><option value="">All roles</option><option value="caregiver" @selected(request('role') === 'caregiver')>Caregivers</option><option value="administrator" @selected(request('role') === 'administrator')>Administrators</option></select></label>
            <label>Status<select name="status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select></label>
            <div class="account-filter-actions"><button class="button" type="submit">Apply filters</button>@if(request()->hasAny(['q', 'role', 'status']))<a class="button subtle" href="{{ route('users.index') }}">Clear</a>@endif</div>
        </form>

        <div class="account-list">
            @forelse($users as $user)
                @php($editing = $formKey === 'edit-'.$user->id)
                @php($isSelf = $user->id === auth()->id())
                <article class="account-row" id="account-{{ $user->id }}">
                    <div class="account-row-main">
                        <div class="account-identity">
                            <span class="account-avatar" aria-hidden="true">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                            <div class="account-person"><h3>{{ $user->name }} @if($isSelf)<span class="account-you">You</span>@endif</h3><span>{{ $user->email }}</span></div>
                        </div>
                        <div class="account-role"><span>Role</span><strong>{{ $user->isAdmin() ? 'Administrator' : 'Caregiver' }}</strong></div>
                        <div class="account-load"><span>Care assignment</span>@if($user->role === 'caregiver')<strong>{{ $user->active_patients_count }} active {{ $user->active_patients_count === 1 ? 'patient' : 'patients' }}</strong><small>{{ $user->patients_count }} total assigned</small>@else<strong>System access</strong><small>All patients and devices</small>@endif</div>
                        <div class="account-state"><span>Status</span><x-badge :value="$user->is_active ? 'active' : 'inactive'"/></div>
                    </div>
                    <div class="account-row-actions">
                        <a class="account-edit-trigger" data-account-modal-trigger href="{{ route('users.index', array_merge(request()->only(['q', 'role', 'status', 'page']), ['edit' => $user->id])) }}#edit-account-{{ $user->id }}" aria-haspopup="dialog" aria-controls="edit-account-{{ $user->id }}">Edit account <span aria-hidden="true">&rarr;</span></a>
                        <dialog id="edit-account-{{ $user->id }}" class="account-modal account-edit-modal" aria-labelledby="edit-account-title-{{ $user->id }}" aria-describedby="edit-account-description-{{ $user->id }}" @if($editing || (!$formKey && request('edit') == $user->id)) open @endif>
                            <header class="account-modal-header">
                                <span class="account-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20a7 7 0 0 1 14 0M17 5l2 2"></path></svg></span>
                                <div><span class="account-modal-kicker">ACCOUNT SETTINGS</span><h2 id="edit-account-title-{{ $user->id }}">Edit {{ $user->name }}</h2><p id="edit-account-description-{{ $user->id }}">Profile, access, and account security</p></div>
                                <form method="dialog" class="account-modal-close-form"><button class="account-modal-close" type="submit" aria-label="Close Edit account for {{ $user->name }}"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 5l10 10M15 5 5 15"></path></svg></button></form>
                            </header>
                            <form method="post" action="{{ route('users.update', array_merge(['user' => $user], request()->only(['q', 'role', 'status', 'page']))) }}" class="account-form account-modal-form">
                                @csrf @method('PUT')
                                <input type="hidden" name="_user_form" value="edit-{{ $user->id }}">
                                <div class="account-modal-body">
                                @if($editing && $errors->any())
                                    <div class="account-modal-errors" role="alert" tabindex="-1"><strong>Please check the account details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                                @endif
                                <section class="account-edit-section" aria-labelledby="edit-profile-{{ $user->id }}">
                                    <div class="account-edit-section-heading"><span aria-hidden="true">01</span><div><h3 id="edit-profile-{{ $user->id }}">Profile</h3><p>The name and email used to sign in.</p></div></div>
                                    <div class="account-form-grid">
                                        <label>Full name<input name="name" required maxlength="150" autocomplete="name" value="{{ $editing ? old('name', $user->name) : $user->name }}"></label>
                                        <label>Email address<input type="email" name="email" required maxlength="255" autocomplete="email" value="{{ $editing ? old('email', $user->email) : $user->email }}"></label>
                                    </div>
                                </section>
                                <section class="account-edit-section" aria-labelledby="edit-access-{{ $user->id }}">
                                    <div class="account-edit-section-heading"><span aria-hidden="true">02</span><div><h3 id="edit-access-{{ $user->id }}">Access</h3><p>Control this account's role and availability.</p></div></div>
                                    <div class="account-form-grid">
                                        @if($isSelf)
                                            <div class="account-readonly"><span>Role</span><strong>Administrator</strong><small>Another administrator must change your own access.</small></div>
                                            <div class="account-readonly"><span>Account status</span><strong>{{ $user->is_active ? 'Active' : 'Inactive' }}</strong></div>
                                            <input type="hidden" name="role" value="{{ $user->role }}">
                                            <input type="hidden" name="is_active" value="{{ (int) $user->is_active }}">
                                        @else
                                            <label>Role<select name="role"><option value="caregiver" @selected(($editing ? old('role', $user->role) : $user->role) === 'caregiver')>Caregiver</option><option value="administrator" @selected(($editing ? old('role', $user->role) : $user->role) === 'administrator') @disabled($user->role === 'caregiver' && $user->patients_count > 0)>Administrator</option></select></label>
                                            <label>Account status<select name="is_active"><option value="1" @selected(($editing ? old('is_active', (int) $user->is_active) : (int) $user->is_active) == '1')>Active</option><option value="0" @selected(($editing ? old('is_active', (int) $user->is_active) : (int) $user->is_active) == '0') @disabled($user->role === 'caregiver' && $user->patients_count > 0)>Inactive</option></select></label>
                                        @endif
                                    </div>
                                </section>
                                <section class="account-edit-section" aria-labelledby="edit-security-{{ $user->id }}">
                                    <div class="account-edit-section-heading"><span aria-hidden="true">03</span><div><h3 id="edit-security-{{ $user->id }}">Password</h3><p>Leave blank to keep the current password.</p></div></div>
                                    <div class="account-form-grid">
                                        <label>New password<input type="password" name="password" minlength="12" autocomplete="new-password"></label>
                                        <label>Confirm password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>
                                    </div>
                                </section>
                                </div>
                                <div class="account-form-footer account-modal-footer"><span>Changing role, email, or password signs out existing sessions.</span><div class="account-modal-actions"><button class="button subtle" type="submit" formmethod="dialog" formnovalidate>Cancel</button><button class="button" type="submit">Save changes</button></div></div>
                            </form>
                        </dialog>
                    </div>
                </article>
            @empty
                <div class="account-empty"><strong>No accounts match these filters.</strong><p>Try another name, role, or status.</p><a class="button subtle small" href="{{ route('users.index') }}">Clear filters</a></div>
            @endforelse
        </div>
        @if($users->hasPages())
            <nav class="account-pagination" aria-label="Account pages"><span>Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span><div>@if($users->previousPageUrl())<a href="{{ $users->previousPageUrl() }}">Previous</a>@endif @if($users->nextPageUrl())<a href="{{ $users->nextPageUrl() }}">Next</a>@endif</div></nav>
        @endif
    </section>
</div>
@endsection
