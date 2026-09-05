@extends('layouts.app')

@section('title', 'New post')

@section('content')
    <h1 class="brand">New post</h1>

    <form method="post" action="{{ url('/posts') }}">
        @csrf

        <label for="title">Title</label>
        <input id="title" name="title" value="{{ $old['title'] ?? '' }}" required>

        <label for="body">Body</label>
        <textarea id="body" name="body" required>{{ $old['body'] ?? '' }}</textarea>

        <button type="submit">Publish</button>
    </form>
@endsection
