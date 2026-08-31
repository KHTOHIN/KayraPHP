@extends('layouts.app')

@section('title', 'KayraPHP')

@section('content')
    <header style="border:0">
        <h1 class="brand">Kayra<span>PHP</span></h1>
        <p class="tag">A compile-first, runtime-agnostic PHP framework.</p>
    </header>

    <div class="grid">
        <div class="card">
            <dl><dt>Environment</dt><dd>{{ $env }}</dd></dl>
        </div>
        <div class="card">
            <dl><dt>Routes</dt><dd>{{ $routes }}</dd></dl>
        </div>
        <div class="card">
            <dl><dt>PHP</dt><dd>{{ $php }}</dd></dl>
        </div>
        <div class="card">
            <dl><dt>Users (demo)</dt><dd>{{ $userCount }}</dd></dl>
        </div>
    </div>

    <h2>Optimisation state</h2>
    <table>
        <tr>
            <th>Configuration cached</th>
            <td>@if($optimised['config'])<span class="yes">yes</span>@else<span class="no">no &mdash; run <code>php kayra optimize</code></span>@endif</td>
        </tr>
        <tr>
            <th>Routes cached</th>
            <td>@if($optimised['routes'])<span class="yes">yes</span>@else<span class="no">no &mdash; run <code>php kayra optimize</code></span>@endif</td>
        </tr>
        <tr>
            <th>OPcache available</th>
            <td>@if($optimised['opcache'])<span class="yes">yes</span>@else<span class="no">no</span>@endif</td>
        </tr>
        <tr>
            <th>Native URI parser (PHP 8.5 <code>ext/uri</code>)</th>
            <td>@if($optimised['nativeUri'])<span class="yes">in use</span>@else<span class="no">falling back to parse_url()</span>@endif</td>
        </tr>
        <tr>
            <th>Debug mode</th>
            <td>@if($debug)<span class="yes">on</span>@else<span class="no">off</span>@endif</td>
        </tr>
    </table>

    <h2>Try it</h2>
    <table>
        <tr><th><code>GET /health</code></th><td>Liveness probe</td></tr>
        <tr><th><code>GET /api/v1/ping</code></th><td>Closure route returning an array</td></tr>
        <tr><th><code>GET /api/v1/users</code></th><td>Resource route with an injected service</td></tr>
        <tr><th><code>GET /api/v1/users/1</code></th><td>Route parameter injected by name</td></tr>
        <tr><th><code>GET /api/v1/me</code></th><td>Behind <code>auth</code> middleware &mdash; returns 401 without a bearer token</td></tr>
    </table>

    <h2>Next steps</h2>
    <table>
        <tr><th><code>php kayra route:list</code></th><td>Show every registered route</td></tr>
        <tr><th><code>php kayra make:controller Post</code></th><td>Generate a controller</td></tr>
        <tr><th><code>php kayra optimize</code></th><td>Compile config, routes and templates for production</td></tr>
        <tr><th><code>php kayra doctor</code></th><td>Check the environment and active fast paths</td></tr>
    </table>
@endsection
