@extends('layouts.app')

@section('title', 'Posts')

@section('content')
    <h1 class="brand">Posts</h1>

    {{-- Offered only to someone the policy would actually let through, so the
         page never advertises a link that answers 403. --}}
    @can('create', 'App\Models\Post')
        <p><a href="{{ url('/posts/create') }}">Write a post</a></p>
    @endcan

    @forelse($posts as $post)
        <article class="post">
            <h3>{{ $post->title }}</h3>
            <div class="meta">
                by {{ $post->author?->name ?? 'unknown' }}
                @isset($post->created_at)
                    &middot; {{ $post->created_at->format('j M Y, H:i') }}
                @endisset
            </div>
            <p>{{ $post->body }}</p>

            {{-- The same policy that guards the routes decides what is offered
                 here, so the page can never show an action the request would
                 then refuse. --}}
            @can('update', $post)
                <div class="actions">
                    <a href="{{ url('/posts/' . $post->id . '/edit') }}">Edit</a>
                    <form class="inline" method="post" action="{{ url('/posts/' . $post->id) }}">
                        @csrf
                        @method('DELETE')
                        <button class="link" type="submit">Delete</button>
                    </form>
                </div>
            @endcan
        </article>
    @empty
        <p class="empty">No posts yet.</p>
    @endforelse
@endsection
