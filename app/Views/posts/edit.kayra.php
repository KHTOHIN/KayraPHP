@extends('layouts.app')

@section('title', 'Edit post')

@section('content')
    <h1 class="brand">Edit post</h1>

    <form method="post" action="{{ url('/posts/' . $post->id) }}">
        @csrf
        @method('PUT')

        <label for="title">Title</label>
        <input id="title" name="title" value="{{ $old['title'] ?? $post->title }}" required>

        <label for="body">Body</label>
        <textarea id="body" name="body" required>{{ $old['body'] ?? $post->body }}</textarea>

        <button type="submit">Save changes</button>
    </form>
@endsection
