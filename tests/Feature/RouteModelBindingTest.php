<?php

declare(strict_types=1);

namespace Kayra\Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Kayra\Database\Connection;
use Kayra\Database\ModelNotFoundException;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Pipeline\CallableHandler;
use Kayra\Pipeline\Pipeline;
use Kayra\Routing\MatchedRoute;
use Kayra\Routing\Route;
use Kayra\Routing\SubstituteBindings;
use Kayra\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `{post}` in a URL becoming a Post in the action.
 *
 * The point of doing this in middleware is that authorization runs before the
 * controller: a policy asked "may this user edit post 7" needs post 7, not the
 * string "7".
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class RouteModelBindingTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->config()->set('database.default', 'sqlite');
        $this->app->config()->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db = $this->app->get(Connection::class);
        $this->db->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT,'
            . ' email_verified_at TEXT, remember_token TEXT, created_at TEXT, updated_at TEXT)',
        );
        $this->db->statement(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, body TEXT,'
            . ' created_at TEXT, updated_at TEXT)',
        );

        SubstituteBindings::flushSignatures();
    }

    private function makePost(string $title = 'Bound'): Post
    {
        $user = new User(['name' => 'Kawsar', 'email' => 'k@test']);
        $user->forceFill(['password' => 'x']);
        $user->save();

        $post = new Post(['title' => $title, 'body' => 'body']);
        $post->forceFill(['user_id' => $user->getKey()]);
        $post->save();

        return $post;
    }

    /**
     * Run one request through the binding middleware for a route pointing at
     * the given controller method.
     *
     * @param array{class-string, string} $handler
     * @param array<string, string>       $parameters
     */
    private function bind(array $handler, array $parameters): ServerRequestInterface
    {
        $route = new Route(['GET'], '/posts/{post}', $handler);
        $matched = new MatchedRoute($route, $parameters);

        $request = new Request('GET', 'http://localhost/posts/' . implode('/', $parameters))
            ->withAttribute('route', $matched->route)
            ->withAttribute('route.parameters', $matched->parameters);

        // The kernel publishes each raw parameter under its own name before the
        // route middleware runs; mirror that so this exercises the real shape.
        foreach ($parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $seen = null;

        (new Pipeline(
            [SubstituteBindings::class],
            new CallableHandler(function (ServerRequestInterface $r) use (&$seen): Response {
                $seen = $r;

                return Response::noContent();
            }),
            $this->app,
        ))->handle($request);

        self::assertInstanceOf(ServerRequestInterface::class, $seen);

        return $seen;
    }

    #[Test]
    public function a_typed_parameter_is_replaced_by_the_record(): void
    {
        $post = $this->makePost('Hello');

        $request = $this->bind(
            [BindingController::class, 'typed'],
            ['post' => $post->getRouteKey()],
        );

        $bound = $request->getAttribute('post');

        $this->assertInstanceOf(Post::class, $bound);
        $this->assertSame('Hello', $bound->title);
    }

    #[Test]
    public function the_record_is_also_published_where_authorization_looks_for_it(): void
    {
        // `can:update,post` reads this attribute. Without it the Gate would be
        // handed an id string, find no policy for it and deny -- which looks
        // like a broken policy rather than a missing binding.
        $post = $this->makePost();

        $request = $this->bind(
            [BindingController::class, 'typed'],
            ['post' => $post->getRouteKey()],
        );

        $this->assertInstanceOf(Post::class, $request->getAttribute('route.model.post'));
    }

    #[Test]
    public function an_untyped_parameter_is_left_as_the_raw_string(): void
    {
        $this->makePost();

        $request = $this->bind(
            [BindingController::class, 'untyped'],
            ['post' => '1'],
        );

        $this->assertSame('1', $request->getAttribute('post'));
        $this->assertNull($request->getAttribute('route.model.post'));
    }

    #[Test]
    public function an_id_that_matches_nothing_is_a_404_not_a_null_model(): void
    {
        // The action is promised a real record. Handing it null instead would
        // push the check into every controller that ever binds a model.
        $this->expectException(ModelNotFoundException::class);

        $this->bind(
            [BindingController::class, 'typed'],
            ['post' => '404'],
        );
    }

    #[Test]
    public function binding_honours_a_custom_route_key(): void
    {
        $post = $this->makePost('Findable by title');

        $request = $this->bind(
            [BindingController::class, 'byTitle'],
            ['post' => 'Findable by title'],
        );

        $bound = $request->getAttribute('post');

        $this->assertInstanceOf(PostByTitle::class, $bound);
        $this->assertSame($post->getKey(), $bound->getKey());
    }

    #[Test]
    public function the_action_receives_the_model_the_middleware_resolved(): void
    {
        // End to end through the kernel's own dispatcher: the route declares a
        // string id, the action declares a Post, and the action wins.
        $post = $this->makePost('Through the dispatcher');

        $this->app->config()->set('app.route_files', []);

        $response = $this->requestThroughRoute(
            '/posts/' . $post->getRouteKey(),
            [BindingController::class, 'show'],
        );

        $this->assertSame('Through the dispatcher', (string) $response->getBody());
    }

    /**
     * Dispatch one ad-hoc route through the real ActionDispatcher.
     *
     * @param array{class-string, string} $handler
     */
    private function requestThroughRoute(string $uri, array $handler): Response
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $id = basename(is_string($path) ? $path : $uri);

        $route = new Route(['GET'], '/posts/{post}', $handler);
        $matched = new MatchedRoute($route, ['post' => $id]);

        $request = new Request('GET', 'http://localhost' . $uri)
            ->withAttribute('route', $matched->route)
            ->withAttribute('route.parameters', $matched->parameters)
            ->withAttribute('post', $id);

        $response = (new Pipeline(
            [SubstituteBindings::class],
            new \Kayra\Http\ActionDispatcher($this->app, $matched),
            $this->app,
        ))->handle($request);

        self::assertInstanceOf(Response::class, $response);

        return $response;
    }
}

/**
 * A Post looked up by title rather than id, to prove getRouteKeyName() is what
 * decides the column.
 *
 * @property string $title
 */
final class PostByTitle extends \Kayra\Database\Model
{
    protected string $table = 'posts';

    /** @var list<string> */
    protected array $fillable = ['title', 'body'];

    public function getRouteKeyName(): string
    {
        return 'title';
    }
}

/**
 * Stands in for a real controller. The binding middleware reads these
 * signatures; only show() is ever actually invoked.
 */
final class BindingController
{
    public function typed(Post $post): string
    {
        return $post->title;
    }

    public function untyped(string $post): string
    {
        return $post;
    }

    public function byTitle(PostByTitle $post): string
    {
        return $post->title;
    }

    public function show(Post $post): Response
    {
        return Response::text($post->title);
    }
}
