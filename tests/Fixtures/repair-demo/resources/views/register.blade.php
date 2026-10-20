@extends('layout')
@section('content')
<h1>Create an account</h1>
<p>Phone is optional.</p>
<form method="POST" action="{{ route('register') }}">
    @csrf
    <label for="name">Name</label>
    <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
    <label for="phone">Phone</label>
    <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
    <label for="password_confirmation">Confirm password</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12">
    <button type="submit">Register</button>
</form>
<p><a href="{{ route('login') }}">Sign in</a></p>
@endsection
