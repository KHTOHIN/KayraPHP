<?php

declare(strict_types=1);

namespace Kayra\View;

use Closure;
use Kayra\Auth\Access\Gate;
use Kayra\Container\ExecutionContext;
use ParseError;
use RuntimeException;
use Throwable;

/**
 * Resolves, compiles, caches and renders templates.
 *
 * A template is compiled to PHP once and written to the cache directory. On
 * later requests the cache file is `require`d directly, so rendering costs the
 * same as including a hand-written PHP file.
 *
 * In production the staleness check is skipped entirely (see $alwaysRecompile),
 * removing two filesystem stat calls per view.
 */
final class Factory
{
    /** Marker left behind by @parent, replaced when the section is finalised. */
    private const PARENT_PLACEHOLDER = '@__kayra_parent__@';

    /**
     * Per-request rendering state, keyed by execution context.
     *
     * The factory is a singleton, so this cannot be a set of plain properties:
     * see {@see RenderState} for what goes wrong when it is.
     *
     * @var array<string, RenderState>
     */
    private array $states = [];

    /**
     * Values shared with every view in every request.
     *
     * Set at boot by service providers. Per-request values -- a CSRF token, a
     * CSP nonce -- belong in {@see shareForRequest()} instead.
     *
     * @var array<string, mixed>
     */
    private array $globals = [];

    /** @var array<string, string> Memoised view-name => path lookups. */
    private array $resolved = [];

    /**
     * Memoised source-path => compiled-path lookups.
     *
     * Only populated in production. Rendering a page touches every template in
     * its layout chain, and each one otherwise costs a stat() to decide whether
     * the compiled copy is current — which measurement showed to be the
     * dominant cost of rendering, far outweighing output buffering.
     *
     * @var array<string, string>
     */
    private array $compiledPaths = [];

    /**
     * @var (Closure(): Gate)|null Deferred so most renders never build one.
     *
     * The resolver itself is set once at boot and is safe to share; the Gate it
     * produces is per-request and lives on the {@see RenderState}.
     */
    private ?Closure $gateResolver = null;

    /**
     * @param list<string> $paths Directories searched for templates, in order.
     */
    public function __construct(
        private array $paths,
        private readonly string $cachePath,
        private readonly Compiler $compiler = new Compiler(),
        private readonly bool $alwaysRecompile = true,
    ) {
    }

    public function addPath(string $path): void
    {
        if (!in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
            $this->resolved = [];
        }
    }

    /**
     * Share a variable with every view, in every request.
     *
     * For values that belong to one request -- a CSRF token, a CSP nonce, the
     * signed-in user -- use {@see shareForRequest()}. Putting those here would
     * hand one request's token to the next one on a long-running worker.
     */
    public function share(string $key, mixed $value): void
    {
        $this->globals[$key] = $value;
    }

    /**
     * Share a variable with every view rendered for the current request.
     *
     * Overrides a global of the same name, and is discarded by
     * {@see forgetRequestState()} when the request ends.
     */
    public function shareForRequest(string $key, mixed $value): void
    {
        $this->state()->shared[$key] = $value;
    }

    /**
     * The rendering state for the calling request, created on demand.
     */
    private function state(): RenderState
    {
        return $this->states[ExecutionContext::id()] ??= new RenderState();
    }

    /**
     * Discard the calling request's rendering state.
     *
     * Long-running runtimes call this after every request, through
     * {@see \Kayra\Foundation\Application::terminate()}. Only this context's
     * state is dropped: other requests may still be mid-render.
     */
    public function forgetRequestState(): void
    {
        unset($this->states[ExecutionContext::id()]);
    }

    public function exists(string $view): bool
    {
        try {
            $this->resolve($view);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Render a template to a string.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = []): string
    {
        $state = $this->state();
        $content = $this->renderOne($view, $data);

        // Walk the @extends chain outwards. Each parent renders with the
        // sections the child already captured.
        while ($state->depth === 0 && $state->parent !== null) {
            $parent = $state->parent;
            $state->parent = null;
            $content = $this->renderOne($parent, $data);
        }

        if ($state->depth === 0) {
            $state->sections = [];
            $state->sectionStack = [];
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderOne(string $view, array $data): string
    {
        $source = $this->resolve($view);
        $path = $this->compiled($source);
        $state = $this->state();

        $state->depth++;
        $level = ob_get_level();

        try {
            return $this->evaluate($path, [...$this->globals, ...$state->shared, ...$data]);
        } catch (ParseError $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            // The raw error points at a hashed file in the cache directory,
            // which tells the author nothing. Name the template they wrote.
            throw new RuntimeException(
                sprintf(
                    'Template [%s] compiled to invalid PHP: %s. This is usually an unclosed '
                    . 'directive -- note that a directive is only recognised when it does not '
                    . 'directly follow a word character, so "text@endif" is literal text and '
                    . '"text @endif" is the directive. Compiled copy: %s.',
                    $source,
                    $e->getMessage(),
                    $path,
                ),
                previous: $e,
            );
        } catch (Throwable $e) {
            // Drop any buffers the template opened before it failed, otherwise
            // the error page renders inside a half-finished template.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        } finally {
            $state->depth--;
        }
    }

    /**
     * @param array<string, mixed> $__data
     */
    private function evaluate(string $__path, array $__data): string
    {
        $__env = $this;

        extract($__data, EXTR_SKIP);

        ob_start();

        try {
            include $__path;
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /* --------------------------------------------------------------------
     | Resolution and compilation
     * -------------------------------------------------------------------- */

    /**
     * Turn a dotted view name into an absolute template path.
     */
    public function resolve(string $view): string
    {
        if (isset($this->resolved[$view])) {
            return $this->resolved[$view];
        }

        // Reject traversal before touching the filesystem.
        if (str_contains($view, '..') || str_contains($view, "\0")) {
            throw new RuntimeException("Invalid view name [{$view}].");
        }

        $relative = str_replace('.', DIRECTORY_SEPARATOR, $view);

        foreach ($this->paths as $base) {
            foreach (['.kayra.php', '.php'] as $extension) {
                $candidate = $base . DIRECTORY_SEPARATOR . $relative . $extension;

                if (is_file($candidate)) {
                    $real = realpath($candidate);
                    $baseReal = realpath($base);

                    // Confirm the resolved file is genuinely inside the view path.
                    if ($real !== false && $baseReal !== false && str_starts_with($real, $baseReal)) {
                        return $this->resolved[$view] = $real;
                    }
                }
            }
        }

        throw new RuntimeException(
            "View [{$view}] not found. Searched: " . implode(', ', $this->paths),
        );
    }

    /**
     * Return the path to the compiled version of a template, compiling if needed.
     */
    private function compiled(string $path): string
    {
        // Production: once a compiled file has been confirmed to exist, it
        // cannot become stale within this process — `kayra optimize` is what
        // refreshes it — so never stat the same template twice.
        if (isset($this->compiledPaths[$path])) {
            return $this->compiledPaths[$path];
        }

        $target = $this->cachePath . DIRECTORY_SEPARATOR . hash('xxh128', $path) . '.php';

        if (!$this->alwaysRecompile && is_file($target)) {
            return $this->compiledPaths[$path] = $target;
        }

        if (is_file($target) && filemtime($target) >= filemtime($path)) {
            // Deliberately not memoised in development: the whole point there is
            // that editing a template takes effect on the next render.
            return $target;
        }

        if (!is_dir($this->cachePath) && !@mkdir($this->cachePath, 0o775, true) && !is_dir($this->cachePath)) {
            throw new RuntimeException("Unable to create view cache directory [{$this->cachePath}].");
        }

        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("Unable to read view [{$path}].");
        }

        // Write to a temporary file and rename, so a concurrent request never
        // sees a half-written template.
        $temporary = $target . '.' . getmypid() . '.tmp';

        file_put_contents($temporary, $this->compiler->compile($source), LOCK_EX);
        rename($temporary, $target);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($target, true);
        }

        return $target;
    }

    /**
     * Pre-compile every template found in the configured paths.
     *
     * @return int Number of templates compiled.
     */
    public function compileAll(): int
    {
        $count = 0;

        foreach ($this->paths as $base) {
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $this->compiled($file->getPathname());
                    $count++;
                }
            }
        }

        return $count;
    }

    /* --------------------------------------------------------------------
     | Layout API, called from compiled templates
     * -------------------------------------------------------------------- */

    public function extend(string $view): void
    {
        $this->state()->parent = $view;
    }

    public function startSection(string $name): void
    {
        $this->state()->sectionStack[] = $name;
        ob_start();
    }

    public function setSection(string $name, string $content): void
    {
        $this->state()->sections[$name] = $content;
    }

    /**
     * @param bool $echo Return the section content (used by @show).
     */
    public function endSection(bool $echo = false): string
    {
        $state = $this->state();

        if ($state->sectionStack === []) {
            throw new RuntimeException('@endsection without a matching @section.');
        }

        $name = array_pop($state->sectionStack);
        $content = (string) ob_get_clean();

        // A child template renders first. When the parent later defines the same
        // section, the child's version wins and @parent pulls in the parent's.
        $state->sections[$name] = isset($state->sections[$name])
            ? str_replace(self::PARENT_PLACEHOLDER, $content, $state->sections[$name])
            : $content;

        return $echo ? $state->sections[$name] : '';
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        $content = $this->state()->sections[$name] ?? $default;

        // Any @parent left unresolved has no parent content to pull in.
        return str_replace(self::PARENT_PLACEHOLDER, '', $content);
    }

    public function parentPlaceholder(): string
    {
        return self::PARENT_PLACEHOLDER;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->state()->sections[$name]);
    }

    /* --------------------------------------------------------------------
     | Includes
     * -------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extra
     */
    public function include(string $view, array $data = [], array $extra = []): string
    {
        // get_defined_vars() from the caller carries framework internals; drop them.
        unset($data['__env'], $data['__path'], $data['__data'], $data['loop']);

        return $this->renderOne($view, [...$data, ...$extra]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extra
     */
    public function includeIf(string $view, array $data = [], array $extra = []): string
    {
        return $this->exists($view) ? $this->include($view, $data, $extra) : '';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extra
     */
    public function includeWhen(bool $condition, string $view = '', array $data = [], array $extra = []): string
    {
        return $condition ? $this->include($view, $data, $extra) : '';
    }

    /* --------------------------------------------------------------------
     | Loops
     * -------------------------------------------------------------------- */

    public function pushLoop(mixed $subject): void
    {
        $count = is_countable($subject) ? count($subject) : null;

        $state = $this->state();

        $state->loopStack[] = new LoopState(
            $count ?? 0,
            end($state->loopStack) ?: null,
        );
    }

    public function incrementLoop(): LoopState
    {
        $loop = end($this->state()->loopStack);

        if ($loop === false) {
            throw new RuntimeException('Loop state requested outside a loop.');
        }

        $loop->advance();

        return $loop;
    }

    public function popLoop(): LoopState
    {
        $loop = array_pop($this->state()->loopStack);

        if ($loop === null) {
            throw new RuntimeException('Loop stack underflow.');
        }

        return $loop;
    }

    /* --------------------------------------------------------------------
     | Form helpers
     * -------------------------------------------------------------------- */

    /**
     * Render the CSRF hidden field.
     *
     * Throws rather than emitting an empty token. A form carrying
     * `<input name="_token" value="">` looks protected in a code review and is
     * not, which is worse than no directive at all — so the failure is loud.
     */
    public function csrfField(): string
    {
        $token = $this->state()->shared['csrf_token'] ?? $this->globals['csrf_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new RuntimeException(
                '@csrf was used but no CSRF token is available for this request. The token is '
                . 'published by the StartSession middleware, so the route needs the `web` or '
                . '`session` middleware group. Outside an HTTP request, share one yourself with '
                . '$views->shareForRequest(\'csrf_token\', $token).',
            );
        }

        return sprintf(
            '<input type="hidden" name="_token" value="%s">',
            htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * Back the @can / @cannot directives.
     *
     * With no gate available — a view rendered outside an application, in a
     * test or a mail preview — this denies rather than failing, which is the
     * safe direction.
     */
    public function can(string $ability, mixed ...$arguments): bool
    {
        return $this->gate()?->allows($ability, ...$arguments) ?? false;
    }

    /**
     * Resolve the gate on first use.
     *
     * Deferred on purpose: building a gate pulls in the whole auth stack, and
     * most renders — every console-compiled template, every page with no @can —
     * never need one.
     */
    private function gate(): ?Gate
    {
        $state = $this->state();

        // Resolve at most once per request, whatever the outcome. Caching the
        // Gate on the factory instead would answer every later request with the
        // permissions of whoever happened to be signed in for the first one.
        if (!$state->gateResolved && $this->gateResolver !== null) {
            $state->gateResolved = true;

            try {
                $state->gate = ($this->gateResolver)();
            } catch (Throwable) {
                // No auth stack in this context; @can denies.
                $state->gate = null;
            }
        }

        return $state->gate;
    }

    /**
     * @internal Called by the auth service provider.
     *
     * @param (Closure(): Gate)|null $resolver
     */
    public function setGateResolver(?Closure $resolver): void
    {
        $this->gateResolver = $resolver;

        foreach ($this->states as $state) {
            $state->gate = null;
            $state->gateResolved = false;
        }
    }

    public function methodField(string $method): string
    {
        return sprintf(
            '<input type="hidden" name="_method" value="%s">',
            htmlspecialchars(strtoupper($method), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
