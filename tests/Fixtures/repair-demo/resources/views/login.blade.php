@extends('layout')
@section('content')
<h1>Sign in</h1>
<form method="POST" action="{{ route('login') }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
</form>
@endsection
