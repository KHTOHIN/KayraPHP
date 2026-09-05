@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <h1 class="brand">Sign in</h1>

    <form method="post" action="{{ url('/login') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ $old['email'] ?? '' }}"
               autocomplete="email" required>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button type="submit">Sign in</button>
    </form>

    <p class="tag">No account yet? <a href="{{ url('/register') }}">Register</a>.</p>
@endsection
