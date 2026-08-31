<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use InvalidArgumentException;
use Kayra\Exceptions\MethodNotAllowedHttpException;
use Kayra\Exceptions\NotFoundHttpException;
use Kayra\Http\Request;
use Kayra\Routing\Route;
use Kayra\Routing\RouteCollection;
use Kayra\Routing\Router;
use Kayra\Routing\UrlGenerator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteCollection::class)]
#[CoversClass(UrlGenerator::class)]
final class RoutingTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', 'home')->name('home');
        $this->router->get('/users/{id}', 'show')->name('users.show')->whereNumber('id');
        $this->router->post('/users', 'store')->name('users.store');
        $this->router->get('/files/{path:.+}', 'files')->name('files');
    }

    private function get(string $path, string $method = 'GET'): \Kayra\Routing\MatchedRoute
    {
        return $this->router->resolve(new Request($method, 'https://app.test' . $path));
    }

    #[Test]
    public function it_matches_a_static_route(): void
    {
        $this->assertSame('home', $this->get('/')->route->handler);
    }

    #[Test]
    public function it_captures_route_parameters(): void
    {
        $this->assertSame('42', $this->get('/users/42')->parameter('id'));
    }

    #[Test]
    public function where_constraints_are_enforced(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->get('/users/not-a-number');
    }

    #[Test]
    public function a_greedy_parameter_captures_slashes(): void
    {
        $this->assertSame('a/b/c.txt', $this->get('/files/a/b/c.txt')->parameter('path'));
    }

    #[Test]
    public function get_routes_also_answer_head(): void
    {
        $this->assertSame('home', $this->get('/', 'HEAD')->route->handler);
    }

    #[Test]
    public function a_wrong_method_yields_405_with_an_allow_header(): void
    {
        try {
            $this->get('/users', 'DELETE');
            $this->fail('Expected MethodNotAllowedHttpException.');
        } catch (MethodNotAllowedHttpException $e) {
            $this->assertSame(405, $e->getStatusCode());
            $this->assertSame('POST', $e->getHeaders()['Allow']);
        }
    }

    #[Test]
    public function paths_are_normalised_before_matching(): void
    {
        $this->assertSame('42', $this->get('/users/42/')->parameter('id'));
        $this->assertSame('42', $this->get('//users//42')->parameter('id'));
    }

    #[Test]
    public function parameters_are_percent_decoded(): void
    {
        $router = new Router();
        $router->get('/tags/{tag}', 'h');

        $matched = $router->resolve(new Request('GET', 'https://app.test/tags/a%20b'));

        $this->assertSame('a b', $matched->parameter('tag'));
    }

    #[Test]
    public function groups_compose_prefix_middleware_and_name(): void
    {
        $router = new Router();
        $router->group(['prefix' => 'api', 'middleware' => 'throttle', 'name' => 'api.'], function (RouteCollection $c): void {
            $c->group(['prefix' => 'admin', 'middleware' => 'auth', 'name' => 'admin.'], function (RouteCollection $c2): void {
                $c2->add(['GET'], '/stats', 'stats')->name('stats');
            });
        });

        $matched = $router->resolve(new Request('GET', 'https://app.test/api/admin/stats'));

        $this->assertSame('stats', $matched->route->handler);
        $this->assertSame(['throttle', 'auth'], $matched->route->getMiddleware());
        $this->assertSame('api.admin.stats', $matched->route->getName());
    }

    #[Test]
    public function resource_routes_generate_the_conventional_seven(): void
    {
        $router = new Router();
        $router->routes()->resource('posts', 'PostController');
        $router->compile();

        $this->assertCount(7, $router->routes()->all());
        $this->assertSame(
            ['posts.index', 'posts.create', 'posts.store', 'posts.show', 'posts.edit', 'posts.update', 'posts.destroy'],
            array_keys($router->routes()->named()),
        );
    }

    #[Test]
    public function resource_parameters_are_singularised(): void
    {
        $router = new Router();
        $router->routes()->resource('categories', 'C');
        $router->compile();

        $matched = $router->resolve(new Request('GET', 'https://app.test/categories/7'));

        $this->assertSame('7', $matched->parameter('category'));
    }

    #[Test]
    public function duplicate_route_names_are_rejected(): void
    {
        $router = new Router();
        $router->get('/a', 'a')->name('dup');
        $router->get('/b', 'b')->name('dup');

        $this->expectException(LogicException::class);

        $router->compile();
    }

    #[Test]
    public function the_static_dsl_is_unbound_once_route_files_finish_loading(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kayra') . '.php';
        file_put_contents($file, '<?php use Kayra\Routing\Route; Route::get("/dsl", "dsl");');

        $router = new Router();
        $router->load([$file]);
        $router->compile();
        unlink($file);

        $this->assertSame('dsl', $router->resolve(new Request('GET', 'https://app.test/dsl'))->route->handler);

        $this->expectException(LogicException::class);
        Route::get('/leaked', 'x');
    }

    #[Test]
    public function compiled_dispatch_data_survives_var_export(): void
    {
        $this->router->compile();
        $exported = eval('return ' . var_export($this->router->compile(), true) . ';');

        $rebuilt = new Router($this->router->routes());
        $rebuilt->useCompiled($exported);

        $matched = $rebuilt->resolve(new Request('GET', 'https://app.test/users/9'));

        $this->assertSame('9', $matched->parameter('id'));
        // Regression: the name index must be rebuilt when routes come from cache.
        $this->assertNotNull($rebuilt->routes()->getByName('users.show'));
    }

    /* ------------------------------------------------------- url generator */

    #[Test]
    public function it_builds_urls_from_named_routes(): void
    {
        $this->router->compile();
        $url = new UrlGenerator($this->router->routes(), 'https://app.test');

        $this->assertSame('/users/42', $url->route('users.show', ['id' => 42]));
        $this->assertSame('https://app.test/', $url->route('home', [], true));
        $this->assertSame('/users/1?q=x', $url->route('users.show', ['id' => 1, 'q' => 'x']));
    }

    #[Test]
    public function a_missing_route_parameter_is_an_error(): void
    {
        $this->router->compile();
        $url = new UrlGenerator($this->router->routes());

        $this->expectException(InvalidArgumentException::class);

        $url->route('users.show');
    }

    #[Test]
    public function unknown_route_names_are_an_error(): void
    {
        $this->router->compile();

        $this->expectException(InvalidArgumentException::class);

        (new UrlGenerator($this->router->routes()))->route('no.such.route');
    }
}
