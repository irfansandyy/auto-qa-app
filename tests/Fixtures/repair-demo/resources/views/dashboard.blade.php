@extends('layout')
@section('content')
<h1>Account dashboard</h1>
<p>You are signed in.</p>
<p>{{ auth()->user()->email }}</p>
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">Sign out</button>
</form>
@endsection
