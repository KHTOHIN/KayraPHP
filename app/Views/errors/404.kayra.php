@extends('layouts.app')

@section('title', '404 Not Found')

@section('content')
    <header style="border:0">
        <h1 class="brand">404</h1>
        <p class="tag">{{ $message }}</p>
    </header>

    <p><a href="{{ url('/') }}">Back to the home page</a></p>
@endsection
