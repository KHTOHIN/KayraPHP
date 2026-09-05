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

    <p>Global, in order: <code>TrustProxies</code>, <code>ValidateHost</code>,
       <code>HandleCors</code>, <code>SecurityHeaders</code>, <code>MethodOverride</code>.
       Available as route aliases: <code>auth</code>, <code>can</code>, <code>throttle</code>,
       <code>signed</code>, <code>bindings</code>, and the <code>session</code> and
       <code>web</code> groups. Register your own in <code>config/app.php</code> under
       <code>middleware_aliases</code>; an alias whose value is a list becomes a group.</p>

    <h2>Runtimes</h2>
    <p>The same application runs unchanged under php-fpm, the built-in server, or Swoole.
       The runtime decides how requests arrive; the kernel only ever sees one request in and one
       response out. Set <code>APP_RUNTIME</code> to <code>auto</code>, <code>fpm</code> or
       <code>swoole</code>.</p>

    <h2>Database and the ORM</h2>
    <p>A model is a class with a table. Mass assignment is closed by default: a key that is not in
       <code>$fillable</code> throws rather than being quietly dropped, so a request body can never
       reach a column nobody listed.</p>

    <pre style="overflow-x:auto"><code>final class Post extends Model
{
    protected string $table = 'posts';

    // user_id is absent on purpose: ownership comes from the session.
    protected array $fillable = ['title', 'body'];
    protected array $casts    = ['created_at' =&gt; 'datetime'];

    public function author(): BelongsTo
    {
        return $this-&gt;belongsTo(User::class, 'user_id');
    }
}

Post::with('author')-&gt;orderBy('created_at', 'DESC')-&gt;get();  // one query for the authors, not N</code></pre>

    <p>Migrations live in <code>database/migrations</code> and run with <code>kayra migrate</code>
       (<code>migrate:rollback</code>, <code>migrate:fresh</code>, <code>migrate:status</code>).</p>

    <h2>Route model binding</h2>
    <p>A parameter type hint is the whole declaration. <code>/posts/{post}</code> with an action of
       <code>edit(Post $post)</code> receives the record; an id that matches nothing is a 404 before
       the controller is constructed. Override <code>getRouteKeyName()</code> to match on a slug
       instead of an id.</p>
    <p>This happens in middleware rather than in the action for one reason: authorization runs
       first, and a policy asked "may this user edit post 7" needs post 7, not the string "7".</p>

    <h2>Authentication</h2>
    <p>Guards answer "who is this". <code>SessionGuard</code> for browsers, <code>TokenGuard</code>
       for APIs; both read from a <code>UserProvider</code>, configured in <code>config/auth.php</code>.
       Logging in regenerates the session id, so a fixed session cannot survive the privilege change.
       Passwords are bcrypt or argon2id, never reversible.</p>
    <p>Put <code>auth</code> on a route to require an identity, and <code>throttle:10,1</code> on
       anything that takes a password &mdash; a login form with no rate limit is a credential-stuffing
       endpoint.</p>

    <h2>Authorization</h2>
    <p>Gates and policies answer "may they". Denial is the default: an ability with no rule is
       refused, so forgetting to write one locks the door rather than opening it.</p>

    <pre style="overflow-x:auto"><code>// config/auth.php
'policies' =&gt; [Post::class =&gt; PostPolicy::class],

// routes
Route::get('/posts/{post}/edit', [PostController::class, 'edit'])
    -&gt;middleware('can:update,post');

// the controller, again -- the route is the fence, this is the lock
$this-&gt;authorize('update', $post);

// and the view, so the page never offers a link that answers 403
@@can('update', $post) &lt;a href="..."&gt;Edit&lt;/a&gt; @@endcan</code></pre>

    <h2>Validation</h2>
    <p><code>validate()</code> returns only the fields that had rules, so an unvalidated key cannot
       reach a mass-assignment call. <code>safe()</code> returns the same data readable as the types
       the rules just established, which is what keeps controllers free of casts.</p>

    <pre style="overflow-x:auto"><code>$input = Validator::make($request-&gt;all(), [
    'title' =&gt; 'required|string|min:3|max:200',
    'age'   =&gt; 'nullable|integer|between:13,120',
])-&gt;safe();

$title = $input-&gt;string('title');   // a string, not a mixed you have to cast</code></pre>

    <h2>Sessions, CSRF and view state</h2>
    <p>The <code>web</code> group starts the session, verifies the CSRF token on unsafe methods,
       substitutes route bindings, and publishes <code>$errors</code>, <code>$old</code>,
       <code>$status</code> and <code>$currentUser</code> to every view &mdash; so a form can be
       redrawn with what the user typed without every controller passing it along.</p>
    <p><code>@@csrf</code> throws when no token is available rather than emitting an empty field. A
       form carrying <code>&lt;input name="_token" value=""&gt;</code> looks protected in review and
       is not, which is worse than no directive at all.</p>

    <h2>Concurrency</h2>
    <p>Request-scoped state is partitioned by execution context &mdash; Swoole coroutine, fiber, or
       the single php-fpm request. That covers the container's scoped bindings, the guard, the gate,
       and the view factory's per-request state. Under php-fpm it costs one boolean check; under a
       worker runtime it is the difference between "scoped" being a claim and being true.</p>

    <h2>Not implemented yet</h2>
    <p>Stated plainly so nothing here is a surprise. There is no cache abstraction, queue, event
       dispatcher, mail layer, broadcasting or file-storage abstraction. Those come after the core
       is stable &mdash; building them on a moving foundation is what forces rewrites.</p>
@endsection
