<?php

declare(strict_types=1);

namespace Kayra\Exceptions;

use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\View\Factory as ViewFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Converts any thrown value into an HTTP response, and decides what may be
 * shown to the client.
 *
 * The debug page is only ever reachable when the application is both non-production
 * and explicitly in debug mode — see {@see Application::isDebug()}.
 */
class Handler
{
    /**
     * Exception types that are never logged: they are expected outcomes, not faults.
     *
     * @var list<class-string>
     */
    protected array $dontReport = [
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
        ValidationException::class,
    ];

    public function __construct(
        private readonly Application $app,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Log an exception, unless it is one of the expected ones.
     */
    public function report(Throwable $e): void
    {
        if ($this->logger === null || $this->shouldNotReport($e)) {
            return;
        }

        $level = $e instanceof HttpException && $e->getStatusCode() < 500
            ? LogLevel::WARNING
            : LogLevel::ERROR;

        $this->logger->log($level, $e->getMessage(), [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);
    }

    protected function shouldNotReport(Throwable $e): bool
    {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render an exception as a response.
     */
    public function render(Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        $this->report($e);

        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $headers = $e instanceof HttpException ? $e->getHeaders() : [];

        $response = $this->wantsJson($request)
            ? $this->renderJson($e, $status)
            : $this->renderHtml($e, $status, $request);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return $request instanceof Request && $request->wantsJson();
    }

    private function renderJson(Throwable $e, int $status): ResponseInterface
    {
        $payload = ['message' => $this->safeMessage($e, $status)];

        if ($e instanceof ValidationException) {
            $payload['errors'] = $e->errors;
        }

        if ($this->app->isDebug()) {
            $payload['exception'] = $e::class;
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['trace'] = $this->frames($e, 20);
        }

        return Response::json($payload, $status);
    }

    private function renderHtml(Throwable $e, int $status, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->app->isDebug()) {
            return Response::html($this->debugPage($e, $request), $status);
        }

        // A project-supplied errors/{status} view wins over the built-in page.
        $views = $this->app->get(ViewFactory::class);

        foreach (["errors.{$status}", 'errors.' . intdiv($status, 100) . 'xx', 'errors.error'] as $candidate) {
            if ($views->exists($candidate)) {
                return Response::html(
                    $views->render($candidate, ['status' => $status, 'message' => $this->safeMessage($e, $status)]),
                    $status,
                );
            }
        }

        return Response::html($this->minimalPage($status, $this->safeMessage($e, $status)), $status);
    }

    /**
     * The message a client is allowed to see.
     *
     * A 5xx message can contain connection strings, paths and query fragments,
     * so outside debug mode it is replaced with the generic status phrase.
     */
    private function safeMessage(Throwable $e, int $status): string
    {
        if ($this->app->isDebug()) {
            return $e->getMessage();
        }

        if ($e instanceof HttpException && $status < 500 && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        return Response::PHRASES[$status] ?? 'Error';
    }

    /**
     * @return list<array{file: string, line: int, function: string}>
     */
    private function frames(Throwable $e, int $limit): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, $limit) as $frame) {
            $frames[] = [
                'file'     => $frame['file'] ?? '[internal]',
                'line'     => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }

        return $frames;
    }

    private function minimalPage(int $status, string $message): string
    {
        $phrase = Response::PHRASES[$status] ?? 'Error';
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>{$status} {$e($phrase)}</title>
            <style>
              :root{color-scheme:light dark}
              body{margin:0;min-height:100vh;display:grid;place-items:center;
                   font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;
                   background:#fff;color:#111}
              @media (prefers-color-scheme:dark){body{background:#0b0d10;color:#e6e6e6}}
              main{text-align:center;padding:2rem}
              h1{font-size:4rem;margin:0;font-weight:650;letter-spacing:-.02em}
              p{margin:.5rem 0 0;opacity:.7}
            </style></head>
            <body><main><h1>{$status}</h1><p>{$e($message)}</p></main></body></html>
            HTML;
    }

    /**
     * The developer error page: exception, source excerpt, trace and request state.
     */
    private function debugPage(Throwable $e, ServerRequestInterface $request): string
    {
        $esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $chain = [];
        $current = $e;

        while ($current !== null) {
            $chain[] = $current;
            $current = $current->getPrevious();
        }

        $primary = $chain[0];
        $excerpt = $this->sourceExcerpt($primary->getFile(), $primary->getLine());

        $traceRows = '';

        foreach ($this->frames($primary, 30) as $i => $frame) {
            $traceRows .= sprintf(
                '<tr><td class="n">%d</td><td><span class="fn">%s</span><br><span class="fl">%s:%d</span></td></tr>',
                $i,
                $esc($frame['function']),
                $esc($this->relative($frame['file'])),
                $frame['line'],
            );
        }

        $previousBlocks = '';

        foreach (array_slice($chain, 1) as $prev) {
            $previousBlocks .= sprintf(
                '<div class="prev"><b>Caused by</b> %s: %s <span class="fl">%s:%d</span></div>',
                $esc($prev::class),
                $esc($prev->getMessage()),
                $esc($this->relative($prev->getFile())),
                $prev->getLine(),
            );
        }

        $requestRows = '';
        $details = [
            'Method'  => $request->getMethod(),
            'URI'     => (string) $request->getUri(),
            'Route'   => $this->routeLabel($request),
            'PHP'     => PHP_VERSION,
            'Kayra'   => Application::VERSION,
            'Env'     => $this->app->environment(),
        ];

        foreach ($details as $label => $value) {
            $requestRows .= sprintf('<tr><th>%s</th><td>%s</td></tr>', $esc($label), $esc($value));
        }

        foreach ($request->getHeaders() as $name => $values) {
            // Never print credentials, even on the debug page.
            $shown = in_array(strtolower($name), ['authorization', 'cookie', 'proxy-authorization'], true)
                ? '[hidden]'
                : implode(', ', $values);

            $requestRows .= sprintf('<tr><th>%s</th><td>%s</td></tr>', $esc($name), $esc($shown));
        }

        $class = $esc($primary::class);
        $message = $esc($primary->getMessage());
        $file = $esc($this->relative($primary->getFile()));
        $line = $primary->getLine();

        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>{$class}</title>
            <style>
              :root{color-scheme:dark;--bg:#0b0d10;--panel:#14181d;--line:#242a31;
                    --text:#e6e8ea;--dim:#8a939c;--accent:#ff6b6b;--hl:#3a2b2b}
              *{box-sizing:border-box}
              body{margin:0;background:var(--bg);color:var(--text);
                   font:14px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
              header{padding:1.5rem 2rem;border-bottom:1px solid var(--line);background:var(--panel)}
              .type{color:var(--accent);font-size:.8rem;text-transform:uppercase;letter-spacing:.08em}
              h1{margin:.35rem 0 .5rem;font-size:1.35rem;font-weight:600;line-height:1.35}
              .fl{color:var(--dim);font-size:.82rem}
              .prev{margin-top:.6rem;padding:.5rem .75rem;background:#1a1416;
                    border-left:2px solid var(--accent);font-size:.85rem}
              main{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,26rem);
                   gap:1px;background:var(--line)}
              @media(max-width:900px){main{grid-template-columns:1fr}}
              section{background:var(--bg);padding:1.25rem 2rem;min-width:0}
              h2{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;
                 color:var(--dim);margin:0 0 .75rem}
              pre{margin:0;overflow-x:auto;background:var(--panel);
                  border:1px solid var(--line);border-radius:6px;padding:.75rem 0}
              .ln{display:block;padding:0 1rem;white-space:pre}
              .ln.on{background:var(--hl);border-left:2px solid var(--accent);padding-left:calc(1rem - 2px)}
              .ln .g{color:var(--dim);user-select:none;display:inline-block;
                     width:3.5em;text-align:right;margin-right:1em}
              table{width:100%;border-collapse:collapse;font-size:.85rem}
              td,th{padding:.4rem .5rem;border-bottom:1px solid var(--line);
                    text-align:left;vertical-align:top;word-break:break-word}
              th{color:var(--dim);font-weight:500;width:34%}
              td.n{color:var(--dim);width:2.5em}
              .fn{color:#9ecbff}
            </style></head>
            <body>
              <header>
                <div class="type">{$class}</div>
                <h1>{$message}</h1>
                <div class="fl">{$file}:{$line}</div>
                {$previousBlocks}
              </header>
              <main>
                <section>
                  <h2>Source</h2>
                  <pre>{$excerpt}</pre>
                  <h2 style="margin-top:1.5rem">Stack trace</h2>
                  <table>{$traceRows}</table>
                </section>
                <section>
                  <h2>Request</h2>
                  <table>{$requestRows}</table>
                </section>
              </main>
            </body></html>
            HTML;
    }

    /**
     * Render the lines around a source location, highlighting the failing one.
     */
    private function sourceExcerpt(string $file, int $line, int $padding = 8): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '<span class="ln">source unavailable</span>';
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return '<span class="ln">source unavailable</span>';
        }

        $start = max(0, $line - $padding - 1);
        $end = min(count($lines) - 1, $line + $padding - 1);
        $output = '';

        for ($i = $start; $i <= $end; $i++) {
            $output .= sprintf(
                '<span class="ln%s"><span class="g">%d</span>%s</span>',
                $i === $line - 1 ? ' on' : '',
                $i + 1,
                htmlspecialchars($lines[$i], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return $output;
    }

    /**
     * The matched route's URI, when the failure happened after routing.
     */
    private function routeLabel(ServerRequestInterface $request): string
    {
        $route = $request->getAttribute('route');

        return $route instanceof \Kayra\Routing\Route ? $route->uri : '—';
    }

    /**
     * Shorten an absolute path to something readable, relative to the project.
     */
    private function relative(string $path): string
    {
        $base = $this->app->basePath();
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : $path;
    }
}
