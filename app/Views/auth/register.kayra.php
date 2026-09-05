@extends('layouts.app')

@section('title', 'Create an account')

@section('content')
    <h1 class="brand">Create an account</h1>

    <form method="post" action="{{ url('/register') }}">
        @csrf

        <label for="name">Name</label>
        <input id="name" name="name" value="{{ $old['name'] ?? '' }}" autocomplete="name" required>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ $old['email'] ?? '' }}"
               autocomplete="email" required>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password"
               autocomplete="new-password" required>

        <button type="submit">Register</button>
    </form>

    <p class="tag">Already registered? <a href="{{ url('/login') }}">Sign in</a>.</p>
@endsection
