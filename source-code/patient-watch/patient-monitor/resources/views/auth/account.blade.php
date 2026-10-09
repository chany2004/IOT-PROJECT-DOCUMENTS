@extends('layouts.app')
@section('title', 'My account')
@section('content')
<div class="page-heading"><div><div class="eyebrow">ACCOUNT SECURITY</div><h1>Your account</h1><p>{{ auth()->user()->email }}</p></div></div>
<section class="panel form-panel"><h2>Change password</h2><form method="post" action="{{ route('account.password') }}">@csrf @method('PUT')
<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" required><small>At least 12 characters.</small></label>
<label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
<button class="button">Update password</button></form></section>
@endsection
