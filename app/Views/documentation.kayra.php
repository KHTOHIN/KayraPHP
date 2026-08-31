@extends('layouts.app')

@section('title', 'KayraPHP Documentation')

@section('content')
    <header style="border:0">
        <h1 class="brand">Kayra<span>PHP</span> docs</h1>
        <p class="tag">Everything currently implemented, and nothing that is not.</p>
    </header>

    <h2>Routing</h2>
    <pre style="overflow-x:auto"><code>use Kayra\Routing\Route;

Route::get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show')
    ->whereNumber('id')
    ->middleware('auth');

Route::group(['prefix' => 'api/v1', 'name' => 'api.v1.'], function () {
    Route::apiResource('users', UserController::class);
});

Route::view('/about', 'pages.about');
Route::redirect('/old', '/new', 301);</code></pre>

    <table>
        <tr><th><code>get post put patch delete options any match</code></th><td>HTTP verbs</td></tr>
        <tr><th><code>-&gt;name()</code></th><td>Name the route for <code>route('users.show', ['id' =&gt; 1])</code></td></tr>
        <tr><th><code>-&gt;middleware()</code></th><td>Alias or class name; <code>throttle:60,1</code> passes parameters</td></tr>
        <tr><th><code>-&gt;where() -&gt;whereNumber() -&gt;whereSlug() -&gt;whereUuid()</code></th><td>Parameter constraints</td></tr>
        <tr><th><code>-&gt;domain()</code></th><td>Restrict to a host</td></tr>
        <tr><th><code>resource() apiResource()</code></th><td>Conventional CRUD routes</td></tr>
    </table>

    <h2>Controllers</h2>
    <pre style="overflow-x:auto"><code>final class UserController extends Controller
{
    // Constructor dependencies are autowired.
    public function __construct(private readonly UserService $users) {}

    // {user} arrives by name; Request arrives by type.
    public function show(string $user, Request $request): ResponseInterface
    {
        return $this->json(['data' =&gt; $this->users-&gt;find((int) $user)]);
    }
}</code></pre>

    <p>Extending <code>Controller</code> is optional. Any callable works as an action &mdash; a closure,
       an invokable class, or <code>[Class::class, 'method']</code>. Return a response, an array
       (encoded as JSON), a string (sent as HTML), or <code>null</code> (204).</p>

    <h2>The container</h2>
    <table>
        <tr><th><code>bind()</code></th><td>A new instance on every resolution</td></tr>
        <tr><th><code>singleton()</code></th><td>One instance for the application's lifetime</td></tr>
        <tr><th><code>scoped()</code></th><td>One instance per request, discarded afterwards</td></tr>
        <tr><th><code>singleton(..., lazy: true)</code></th><td>PHP 8.4 lazy object &mdash; constructed on first use</td></tr>
        <tr><th><code>when()-&gt;needs()-&gt;give()</code></th><td>Contextual bindings</td></tr>
        <tr><th><code>tag() / tagged()</code></th><td>Resolve a group of services together</td></tr>
        <tr><th><code>extend()</code></th><td>Decorate a service after it is built</td></tr>
    </table>

    <p><strong>Reach for <code>scoped()</code> whenever a service holds request state.</strong>
       It is what keeps the application correct under Swoole and RoadRunner: the scoped bucket
       is emptied after every request, so nothing can leak into the next one.</p>

    <h2>Templates</h2>
    <pre style="overflow-x:auto"><code>@verbatim
@extends('layouts.app')

@section('content')
    <h1>{{ $title }}</h1>          {{-- escaped --}}
    {!! $trustedHtml !!}           {{-- raw --}}

    @forelse($users as $user)
        <li>{{ $loop->iteration }}. {{ $user['name'] }}</li>
    @empty
        <li>No users.</li>
    @endforelse

    <div @class(['card' => true, 'card--active' => $isActive])></div>
@endsection
@endverbatim</code></pre>

    {{-- Directive names are written with @@ so the compiler emits them as text
         rather than trying to compile them. --}}
    <table>
        <tr><th>Output</th><td>@verbatim <code>{{ }}</code> escaped, <code>{!! !!}</code> raw, <code>{{-- --}}</code> comment @endverbatim</td></tr>
        <tr><th>Control</th><td><code>@@if @@elseif @@else @@endif @@unless @@isset @@empty @@switch</code></td></tr>
        <tr><th>Loops</th><td><code>@@foreach @@forelse @@for @@while @@break @@continue</code> with <code>$loop</code></td></tr>
        <tr><th>Layout</th><td><code>@@extends @@section @@endsection @@show @@yield @@parent</code></td></tr>
        <tr><th>Include</th><td><code>@@include @@includeIf @@includeWhen</code></td></tr>
        <tr><th>Helpers</th><td><code>@@csrf @@method @@json @@class @@style @@checked @@selected @@disabled</code></td></tr>
        <tr><th>Escape hatch</th><td><code>@@php @@endphp</code>, <code>@@verbatim @@endverbatim</code>, <code>@@@@</code> for a literal <code>@@</code></td></tr>
    </table>

    <p>Templates compile to plain PHP in <code>storage/framework/views</code>, so OPcache treats
       them exactly like hand-written code. In production the staleness check is skipped entirely.</p>

    <h2>Console</h2>
    <table>
        <tr><th><code>kayra serve</code></th><td>Development server (<code>--host</code>, <code>--port</code>)</td></tr>
        <tr><th><code>kayra route:list</code></th><td>Every route (<code>--method</code>, <code>--path</code>, <code>--name</code>, <code>--json</code>)</td></tr>
        <tr><th><code>kayra make:&lt;type&gt; Name</code></th><td>controller, model, service, repository, middleware, provider, command, view</td></tr>
        <tr><th><code>kayra optimize</code></th><td>Compile config, routes and templates</td></tr>
        <tr><th><code>kayra optimize:clear</code></th><td>Remove every compiled artefact</td></tr>
        <tr><th><code>kayra key:generate</code></th><td>Generate <code>APP_KEY</code></td></tr>
        <tr><th><code>kayra doctor</code></th><td>Environment check and active fast paths</td></tr>
        <tr><th><code>kayra about</code></th><td>Application summary</td></tr>
        <tr><th><code>kayra test</code></th><td>Run PHPUnit</td></tr>
    </table>

    <h2>Middleware</h2>
    <p>Middleware is PSR-15. The parameter types must be the PSR interfaces
       (<code>ServerRequestInterface</code>, <code>RequestHandlerInterface</code>) and not the
       KayraPHP subclasses &mdash; PHP requires a parameter type to be the same as or wider than the
       interface's, so narrowing it is a fatal error at class-declaration time.</p>

    <pre style="overflow-x:auto"><code>final class EnsureAdmin implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $request-&gt;getAttribute('user')?-&gt;isAdmin()) {
            return Response::json(['message' =&gt; 'Forbidden'], 403);
        }

        return $handler-&gt;handle($request);
    }
}</code></pre>

    <p>Built in: <code>TrustProxies</code>, <code>HandleCors</code>, <code>SecurityHeaders</code>,
       <code>MethodOverride</code>. Register global middleware in <code>config/app.php</code>
       and per-route aliases under <code>middleware_aliases</code>.</p>

    <h2>Runtimes</h2>
    <p>The same application runs unchanged under php-fpm, the built-in server, or Swoole.
       The runtime decides how requests arrive; the kernel only ever sees one request in and one
       response out. Set <code>APP_RUNTIME</code> to <code>auto</code>, <code>fpm</code> or
       <code>swoole</code>.</p>

    <h2>Not implemented yet</h2>
    <p>Stated plainly so nothing here is a surprise. There is currently no database layer, ORM,
       migrations, authentication, sessions, cache abstraction, queues, events or mail. Those come
       after the core is stable &mdash; building them on a moving foundation is what forces rewrites.</p>
@endsection
