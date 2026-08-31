# KayraPHP

A compile-first, runtime-agnostic PHP framework for PHP 8.5+.

KayraPHP is built around one idea that existing PHP frameworks retrofit rather than assume:
**a request's state must be structurally separate from the application's state.** That makes it
correct under long-running runtimes (Swoole, RoadRunner, FrankenPHP) by construction rather than
by convention, and it is what the container's three lifetimes exist to express.

```bash
composer install
cp .env.example .env
php kayra key:generate
php kayra serve
```

---

## Requirements

| | |
|---|---|
| PHP | 8.5 or newer |
| Required extensions | `uri`, `mbstring`, `json`, `openssl` |
| Recommended | `opcache` (production), `pdo_*`, `swoole` |

Modern PHP is used deliberately, not decoratively:

| Feature | Used for |
|---|---|
| `ext/uri` (8.5) | strict RFC 3986 URI parsing |
| `#[\NoDiscard]` (8.5) | all 25 PSR-7 `withX()` methods |
| Lazy objects (8.4) | deferred service construction |
| Property hooks (8.4) | computed `$loop` state |
| Asymmetric visibility (8.4) | immutable value objects |
| Fibers | per-request container isolation |

`#[\NoDiscard]` is worth calling out. PSR-7 messages are immutable, so
`$response->withStatus(404);` on its own line does nothing — a bug this codebase actually
shipped before. The engine now reports it:

```
Warning: The return value of method Kayra\Http\Response::withStatus() should either be used
or intentionally ignored by casting it as (void)
```

**On `ext/uri`:** parsing is strict-then-lenient. `ext/uri` implements RFC 3986 to the letter and
therefore *rejects* request targets that real clients nonetheless send — raw spaces, un-encoded
UTF-8 paths, `{}`, `|`, `^`, malformed `%zz`. Those fall through to `parse_url()` so they reach
the router and get an honest 404 instead of raising an exception. Targets that neither parser
accepts return 400, not a crash.

Run `php kayra doctor` to see which fast paths are active on your machine.

---

## Layout

```
app/            Your code — Controllers, Services, Middlewares, Providers, Views
bootstrap/      Application construction and compiled artefacts
config/         Configuration files
core/           The framework
database/       Migrations and seeders
public/         Web root; index.php is the only entry point
routes/         web.php, api.php, console.php
storage/        Logs, sessions, compiled templates
tests/          PHPUnit suite
```

---

## The container

Three lifetimes, and the choice between them matters:

```php
$app->bind(Mailer::class, SmtpMailer::class);   // new instance every time
$app->singleton(Clock::class);                  // one for the app's lifetime
$app->scoped(CurrentUser::class);               // one per request, then discarded
```

`scoped()` is the important one. On php-fpm the process ends after each request, so the
distinction is invisible. On Swoole one process serves many requests **concurrently**, and a
service that should have been scoped becomes a cross-request data leak — one user seeing
another's data.

Scoped instances are therefore partitioned by execution context (Swoole coroutine, Fiber, or the
root), not held in one shared array. Two requests in flight at the same time cannot see each
other's services, and one finishing cannot destroy the other's state. `tests/Unit/CoroutineSafetyTest.php`
demonstrates both properties rather than asserting them in prose.

Also available: `lazy: true` (PHP 8.4 lazy objects, constructed on first property access),
`when()->needs()->give()` for contextual bindings, `tag()`/`tagged()`, and `extend()` for decoration.

---

## Routing

```php
Route::get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show')
    ->whereNumber('id')
    ->middleware('auth');

Route::group(['prefix' => 'api/v1', 'name' => 'api.v1.'], function () {
    Route::apiResource('users', UserController::class);
});
```

Static routes are answered from a hash lookup before any regular expression runs; parameterised
routes go to `nikic/fast-route`. The gap widens with scale — at 500 routes a static match stays at
~1 µs while a parameterised one costs ~10.5 µs.

---

## Database

```php
$users = $db->table('users')
    ->where('active', true)
    ->where(fn ($q) => $q->where('age', '>', 18)->orWhere('verified', true))
    ->orderBy('created_at', 'DESC')
    ->forPage(2, 25)
    ->get();
```

Two rules hold throughout: every **value** is a bound parameter, and every **identifier** —
table, column, operator, sort direction — is validated against an allow-list before it reaches
the SQL string. Identifiers cannot be bound, so anything that is not a plain name is rejected
rather than escaped. `tests/Unit/DatabaseTest.php` fires the usual injection payloads at it.

`update()` and `delete()` refuse to run without a `where()`; `updateAll()` and `truncate()` exist
for when that really is the intent. Nested `transaction()` calls use savepoints, so an inner
failure does not silently commit the outer one.

```bash
php kayra make:migration create_posts_table
php kayra migrate
php kayra migrate:status
php kayra migrate:rollback
```

Drivers: MySQL/MariaDB, PostgreSQL, SQLite, SQL Server.

---

## Security

Sessions are ordinary objects in the container's scoped bucket — not PHP's `session_*` globals,
which are exactly what breaks under a long-running worker. Payloads are encrypted at rest with
AES-256-GCM, so a readable storage directory does not hand over their contents, and a file edited
on disk fails to decrypt rather than feeding modified data back in.

```php
Route::post('/profile', [ProfileController::class, 'update'])->middleware('web');
```

The `web` group starts the session and verifies the CSRF token. Other middleware:
`auth`, `throttle:60,1`, `signed`, `session`.

Defaults you get without asking:

- Security headers, including `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy`
- CSP with a per-request `{nonce}`, shared to templates as `$csp_nonce`
- Cookies default to `Secure`, `HttpOnly`, `SameSite=Lax`
- CR/LF rejected in header values and redirect targets (response splitting)
- `X-Forwarded-*` ignored until you list trusted proxies
- Host header checked against `security.trusted_hosts` (host-header poisoning)
- CORS refuses to combine a wildcard origin with credentials
- `{{ }}` escapes with `ENT_QUOTES | ENT_SUBSTITUTE`
- Session ids and rate-limiter keys can never become file paths
- Request data in `$_SERVER` never becomes configuration input
- A stale route cache is rejected, never used to dispatch the wrong handler
- `@csrf` throws rather than emitting an empty token

```bash
php kayra doctor --security   # non-zero exit if a check fails — for deploy pipelines
```

---

## Validation

```php
$data = Validator::make($request->all(), [
    'email'    => 'required|email|max:255',
    'age'      => 'nullable|integer|between:13,120',
    'password' => 'required|string|min:12|confirmed',
])->validate();
```

`validated()` returns only fields that had a rule — passing raw input onward after validating a
subset of it is how mass-assignment bugs happen.

---

## Console

```bash
php kayra serve                       # development server
php kayra route:list                  # every route (--json, --method, --path, --name)
php kayra make:controller Post        # also: model service repository middleware provider command migration view
php kayra migrate                     # also: migrate:rollback migrate:fresh migrate:status
php kayra optimize                    # compile config, packages, routes, service graph, templates, preload
php kayra optimize --show-skipped     # list classes left dynamic
php kayra optimize:clear              # remove compiled artefacts
php kayra key:generate                # generate APP_KEY
php kayra doctor [--security]         # environment check
php kayra about                       # application summary
php kayra test                        # run PHPUnit
```

---

## Production

```bash
composer install --no-dev --optimize-autoloader
php kayra optimize
```

Set `APP_ENV=production`. Debug output is then force-disabled regardless of `APP_DEBUG`, because an
`APP_DEBUG=true` that reaches production is a data leak, not a preference.

`kayra optimize` builds six artefacts into `bootstrap/cache/`:

| Artefact | Removes from every request |
|---|---|
| `config.php` | globbing `config/` and one `require` per file |
| `packages.php` | scanning `installed.json` for package providers |
| `routes.php` | compiling route patterns into regular expressions |
| `services.php` | **reflecting over every constructor in the service graph** |
| compiled views | stat-ing each template to check staleness |
| `preload.php` | compiling framework classes per worker |

The service graph is what distinguishes this from a cache. `ContainerCompiler` walks every class
the application can resolve — controllers, middleware, provider bindings, and their transitive
dependencies — and writes a plain-array construction plan. At run time the container reads the
plan instead of calling `ReflectionClass`; `tests/Unit/ContainerCompilerTest.php` asserts that
zero reflection calls occur. Classes whose construction genuinely depends on run-time state
(contextual bindings, variadics, closure factories) are left dynamic and listed by
`kayra optimize --show-skipped`.

Point OPcache at the generated preload script:

```ini
opcache.preload=/path/to/bootstrap/cache/preload.php
opcache.preload_user=www-data
```

"Compiling" means precomputation, not native compilation — PHP still interprets the result.

Measured on this codebase (PHP 8.5.0, in-process, warm, median of three runs):

| | boot | JSON route | controller + DI | HTML + template |
|---|---|---|---|---|
| uncompiled | 0.83 ms | 40.6 µs | 41.8 µs | 375.9 µs |
| compiled | 0.53 ms | 39.7 µs | 40.2 µs | 366.9 µs |
| compiled + OPcache/JIT | 0.55 ms | **27.8 µs** | **30.7 µs** | **92.9 µs** |

Boot cost falls ~36% with the compiled artefacts, which is what a php-fpm request pays on every
hit. Enable OPcache: it is the single largest win and nothing else comes close.

---

## Runtimes

The same application runs unchanged under php-fpm, the built-in server, Swoole, or FrankenPHP
worker mode. The runtime decides how requests arrive; the kernel only ever sees one request in and
one response out. Set `APP_RUNTIME` to `auto`, `fpm`, `swoole` or `frankenphp`.

---

## Packages

A package advertises itself in its own `composer.json` and is discovered automatically:

```json
{
  "extra": {
    "kayra": {
      "providers": ["Vendor\\Package\\PackageServiceProvider"]
    }
  }
}
```

Opt out per package with `app.dont_discover`. The manifest is cached by `kayra optimize`.

---

## Quality

```bash
php kayra test                                   # 220 unit + feature tests
vendor/bin/phpunit -c phpunit-psr7.xml           # 145 PSR-7 conformance tests
vendor/bin/phpstan analyse                       # level 9
vendor/bin/infection                             # mutation testing (needs pcov/xdebug)
```

- **220 tests, 376 assertions** across the container, HTTP layer, routing, templates, security,
  database and coroutine isolation.
- **145 PSR-7 conformance tests** from `php-http/psr7-integration-tests` — written by the PSR
  maintainers, so they check the spec rather than this implementation's idea of it.
- **PHPStan level 9**, with a baseline that records pre-existing annotation debt and may only
  shrink. CI fails on any new error.
- **CI matrix** covers PHP 8.5 with and without OPcache, with and without Swoole, and re-runs the
  whole suite against the compiled build.

`Kayra\Tests\TestCase` boots a fresh application per test so no container state leaks between them.

---

## Versioning

See [VERSIONING.md](VERSIONING.md) for the compatibility contract: what counts as public API, what
counts as a break, and the deprecation policy. Until `1.0.0` the framework is explicitly unstable —
pin an exact version.

---

## What is not built yet

Stated plainly, because a framework that implies capabilities it lacks wastes your time:

**No ORM** (the query builder returns arrays), **no authentication**, no authorization, no cache
abstraction, no queues, no events, no mail, no file-storage abstraction.

The pieces those would build on — container, HTTP, routing, database, sessions, encryption,
validation — are in place and tested. The ORM is deliberately last: an object-mapping layer
constrains every schema decision above it, and building one on a foundation still in motion is
what forces rewrites.

---

## Licence

MIT
