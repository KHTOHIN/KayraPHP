<?php

declare(strict_types=1);

namespace Kayra\Tests\Feature;

use Kayra\Http\Middleware\ShareViewState;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Pipeline\CallableHandler;
use Kayra\Pipeline\Pipeline;
use Kayra\Session\Session;
use Kayra\Session\SessionHandler;
use Kayra\Tests\TestCase;
use Kayra\View\Factory as ViewFactory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The variables every page template expects to find.
 */
final class ShareViewStateTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        parent::setUp();

        $this->views = sys_get_temp_dir() . '/kayra_svs_' . bin2hex(random_bytes(4));
        mkdir($this->views, 0o777, true);

        $this->app->get(ViewFactory::class)->addPath($this->views);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->views);

        parent::tearDown();
    }

    private function template(string $name, string $contents): void
    {
        file_put_contents($this->views . '/' . $name . '.kayra.php', $contents);
    }

    /**
     * Run the middleware with a session already holding $flashed, then render.
     *
     * @param array<string, mixed> $flashed
     */
    private function render(string $view, array $flashed = []): string
    {
        $session = new Session($this->app->get(SessionHandler::class));
        $session->start();

        foreach ($flashed as $key => $value) {
            $session->put($key, $value);
        }

        $this->app->scopedInstance(Session::class, $session);

        $rendered = '';

        (new Pipeline(
            [ShareViewState::class],
            new CallableHandler(function (ServerRequestInterface $request) use ($view, &$rendered): Response {
                $rendered = $this->app->get(ViewFactory::class)->render($view);

                return Response::noContent();
            }),
            $this->app,
        ))->handle(new Request('GET', 'http://localhost/'));

        return $rendered;
    }

    #[Test]
    public function errors_is_always_defined_even_on_a_clean_request(): void
    {
        // So a template can write `@if($errors)` without an isset() dance --
        // and so a typo'd key fails loudly instead of rendering as blank.
        $this->template('t', <<<'VIEW'
            @if($errors)
            has
            @else
            none
            @endif
            VIEW);

        $this->assertSame('none', trim($this->render('t')));
    }

    #[Test]
    public function flashed_errors_reach_the_view(): void
    {
        $this->template('t', '{{ $errors["email"][0] }}');

        $this->assertSame(
            'That address is taken.',
            $this->render('t', ['errors' => ['email' => ['That address is taken.']]]),
        );
    }

    #[Test]
    public function old_input_reaches_the_view_so_a_form_can_be_redrawn(): void
    {
        $this->template('t', '{{ $old["title"] ?? "" }}');

        $this->assertSame('half typed', $this->render('t', ['old' => ['title' => 'half typed']]));
    }

    #[Test]
    public function a_status_message_reaches_the_view(): void
    {
        $this->template('t', <<<'VIEW'
            @isset($status)
            {{ $status }}
            @endisset
            VIEW);

        $this->assertSame('Saved.', trim($this->render('t', ['status' => 'Saved.'])));
    }

    #[Test]
    public function current_user_is_undefined_for_a_guest(): void
    {
        // Undefined rather than null, so `@isset($currentUser)` is the natural
        // way to ask "is anyone signed in".
        $this->template('t', <<<'VIEW'
            @isset($currentUser)
            someone
            @else
            nobody
            @endisset
            VIEW);

        $this->assertSame('nobody', trim($this->render('t')));
    }

    #[Test]
    public function a_hostile_value_in_the_session_is_escaped_not_executed(): void
    {
        // Flash data is written by the application, but old input is whatever
        // the user typed -- so it goes through the same escaping as everything
        // else `{{ }}` renders.
        $this->template('t', '{{ $old["title"] ?? "" }}');

        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $this->render('t', ['old' => ['title' => '<script>alert(1)</script>']]),
        );
    }

    #[Test]
    public function a_non_array_errors_value_is_ignored_rather_than_trusted(): void
    {
        // Session storage is not schema-checked. A corrupted or hand-crafted
        // value must not reach a template that will foreach over it.
        $this->template('t', <<<'VIEW'
            @if($errors)
            has
            @else
            none
            @endif
            VIEW);

        $this->assertSame('none', trim($this->render('t', ['errors' => 'not an array'])));
    }

    #[Test]
    public function view_state_does_not_survive_into_the_next_request(): void
    {
        $this->template('t', '{{ $old["title"] ?? "clean" }}');

        $this->assertSame('first', $this->render('t', ['old' => ['title' => 'first']]));

        // What a long-running runtime does between requests.
        $this->app->terminate();

        $this->assertSame('clean', $this->render('t'));
    }
}
