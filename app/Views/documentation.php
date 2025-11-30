<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'KayraPHP Documentation', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .documentation {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .toc {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .toc ul {
            list-style-type: none;
            padding-left: 20px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h2 {
            border-bottom: 1px solid #eaecef;
            padding-bottom: 10px;
        }
        code {
            background-color: #f6f8fa;
            padding: 2px 5px;
            border-radius: 3px;
            font-family: monospace;
        }
        pre {
            background-color: #f6f8fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .back-to-home {
            margin-top: 30px;
        }
    </style>
</head>
<body>
<div class="documentation">
    <h1>KayraPHP Documentation</h1>
    
    <div class="toc">
        <h2>Table of Contents</h2>
        <ul>
            <li><a href="#introduction">Introduction</a></li>
            <li><a href="#installation">Installation</a></li>
            <li><a href="#architecture">Architecture Patterns</a></li>
            <li><a href="#routing">Routing</a></li>
            <li><a href="#controllers">Controllers</a></li>
            <li><a href="#views">Views</a></li>
            <li><a href="#models">Models</a></li>
            <li><a href="#database">Database</a></li>
            <li><a href="#middleware">Middleware</a></li>
            <li><a href="#validation">Validation</a></li>
            <li><a href="#authentication">Authentication</a></li>
            <li><a href="#authorization">Authorization</a></li>
            <li><a href="#caching">Caching</a></li>
            <li><a href="#logging">Logging</a></li>
            <li><a href="#events">Events</a></li>
            <li><a href="#storage">Storage</a></li>
            <li><a href="#cli">Command Line Interface</a></li>
            <li><a href="#testing">Testing</a></li>
            <li><a href="#deployment">Deployment</a></li>
            <li><a href="#performance">Performance Optimization</a></li>
            <li><a href="#security">Security</a></li>
            <li><a href="#troubleshooting">Troubleshooting</a></li>
        </ul>
    </div>

    <div id="introduction" class="section">
        <h2>Introduction</h2>
        <p>KayraPHP is a minimal, PSR-compliant PHP framework (PHP 8.4+) focused on performance. It features precompiled dependency injection, async IO stubs, opcache preload, and avoids runtime reflection for maximum efficiency.</p>
        <p>Key features include:</p>
        <ul>
            <li>Performance-First Design: Optimized for speed and efficiency</li>
            <li>Multiple Architecture Patterns: MVC, Factory-Service, Domain-Driven Design, or Custom Hybrid</li>
            <li>PSR Compliance: Follows PHP Standard Recommendations</li>
            <li>Async I/O Support: With Swoole extension</li>
            <li>Modern PHP: Leverages PHP 8.4+ features</li>
            <li>Docker Support: Ready-to-use Docker configuration</li>
        </ul>
    </div>

    <div id="installation" class="section">
        <h2>Installation</h2>
        <h3>Requirements</h3>
        <ul>
            <li>PHP 8.4 or higher</li>
            <li>Composer</li>
            <li>MySQL 8.0 (for database functionality)</li>
            <li>Redis (for caching, optional)</li>
            <li>Swoole extension (for ultra performance mode, optional)</li>
        </ul>

        <h3>Via Composer</h3>
        <pre><code>composer create-project kayraphp/framework my-project
cd my-project
php kayra key:generate</code></pre>

        <h3>Via Docker</h3>
        <pre><code># Clone the repository
git clone https://github.com/kayraphp/framework.git my-project
cd my-project

# Start Docker containers
docker-compose up -d

# Install dependencies and generate key
docker-compose exec php composer install
docker-compose exec php php kayra key:generate</code></pre>
    </div>

    <div id="architecture" class="section">
        <h2>Architecture Patterns</h2>
        <p>KayraPHP supports multiple architecture patterns that can be selected in the configuration:</p>

        <h3>MVC (Model-View-Controller)</h3>
        <p>The default pattern separates the application into models, views, and controllers:</p>
        <ul>
            <li><strong>Models</strong>: Represent the data and business logic</li>
            <li><strong>Views</strong>: Represent the presentation layer</li>
            <li><strong>Controllers</strong>: Handle user input and coordinate between models and views</li>
        </ul>

        <h3>Factory-Service</h3>
        <p>This pattern separates the application into factories and services:</p>
        <ul>
            <li><strong>Factories</strong>: Create and configure services</li>
            <li><strong>Services</strong>: Contain the business logic</li>
        </ul>

        <h3>Domain-Driven Design (DDD)</h3>
        <p>This pattern organizes the application around business domains:</p>
        <ul>
            <li>Each domain has its own models, services, and repositories</li>
            <li>Domains are isolated and communicate through well-defined interfaces</li>
        </ul>

        <h3>Custom Hybrid</h3>
        <p>This pattern allows you to mix and match elements from different architecture patterns:</p>
        <ul>
            <li>Combines aspects of MVC, Factory-Service, and Domain-Driven patterns</li>
            <li>Provides flexibility to organize code in a way that best fits your project's needs</li>
        </ul>

        <h3>Configuring Architecture Pattern</h3>
        <p>You can configure the architecture pattern in the <code>config/app.php</code> file:</p>
        <pre><code>'architecture' => env('APP_ARCHITECTURE', 'mvc'), // Options: mvc, factory-service, domain-driven, custom</code></pre>
        <p>You can also set the architecture pattern using the <code>APP_ARCHITECTURE</code> environment variable.</p>
    </div>

    <div id="routing" class="section">
        <h2>Routing</h2>
        <p>KayraPHP uses a simple and efficient routing system. Routes are defined in the <code>routes/web.php</code> and <code>routes/api.php</code> files.</p>

        <h3>Basic Routing</h3>
        <pre><code>// routes/web.php
return [
    'GET' => [
        '/' => 'App\\Controllers\\HomeController@index',
        '/about' => fn($req) => \Kayra\Http\Response::create(200, [], 'About Page'),
    ],
    'POST' => [
        '/contact' => 'App\\Controllers\\ContactController@submit',
    ],
];</code></pre>

        <h3>Route Parameters</h3>
        <pre><code>'GET' => [
    '/users/{id}' => 'App\\Controllers\\UserController@show',
    '/posts/{slug}' => 'App\\Controllers\\PostController@show',
],</code></pre>

        <h3>Route Groups</h3>
        <pre><code>'GET' => [
    '/admin/users' => 'App\\Controllers\\Admin\\UserController@index',
    '/admin/posts' => 'App\\Controllers\\Admin\\PostController@index',
],</code></pre>

        <h3>Route Middleware</h3>
        <pre><code>'GET' => [
    '/dashboard' => [
        'controller' => 'App\\Controllers\\DashboardController@index',
        'middleware' => ['auth'],
    ],
],</code></pre>
    </div>

    <div id="controllers" class="section">
        <h2>Controllers</h2>
        <p>Controllers handle incoming HTTP requests and return responses. They are stored in the <code>app/Controllers</code> directory.</p>

        <h3>Basic Controller</h3>
        <pre><code>namespace App\Controllers;

use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\Http\Response;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('home', ['title' => 'Welcome to KayraPHP Framework']);
    }
}</code></pre>

        <h3>Controller Methods</h3>
        <ul>
            <li><code>view($name, $data = [])</code>: Render a view</li>
            <li><code>json($data, $status = 200)</code>: Return JSON response</li>
            <li><code>redirect($url, $status = 302)</code>: Redirect to another URL</li>
            <li><code>file($path, $name = null, $headers = [])</code>: Return a file download</li>
        </ul>
    </div>

    <div id="views" class="section">
        <h2>Views</h2>
        <p>Views are responsible for rendering HTML. They are stored in the <code>app/Views</code> or <code>resources/views</code> directory.</p>

        <h3>Basic View</h3>
        <pre><code>&lt;!DOCTYPE html&gt;
&lt;html lang="en"&gt;
&lt;head&gt;
    &lt;meta charset="UTF-8"&gt;
    &lt;title&gt;&lt;?= htmlspecialchars($title ?? 'KayraPHP', ENT_QUOTES, 'UTF-8') ?&gt;&lt;/title&gt;
&lt;/head&gt;
&lt;body&gt;
    &lt;h1&gt;&lt;?= htmlspecialchars($title ?? 'Welcome', ENT_QUOTES, 'UTF-8') ?&gt;&lt;/h1&gt;
    &lt;p&gt;This is a basic view.&lt;/p&gt;
&lt;/body&gt;
&lt;/html&gt;</code></pre>

        <h3>Layouts</h3>
        <p>You can use layouts to avoid duplicating code across views:</p>
        <pre><code>// resources/views/layout.php
&lt;!DOCTYPE html&gt;
&lt;html lang="en"&gt;
&lt;head&gt;
    &lt;meta charset="UTF-8"&gt;
    &lt;title&gt;&lt;?= $title ?? 'KayraPHP' ?&gt;&lt;/title&gt;
&lt;/head&gt;
&lt;body&gt;
&lt;header&gt;&lt;h1&gt;KayraPHP Framework&lt;/h1&gt;&lt;/header&gt;
&lt;main&gt;&lt;?= $slot ?? '' ?&gt;&lt;/main&gt;
&lt;footer&gt;Performance-first PHP&lt;/footer&gt;
&lt;/body&gt;
&lt;/html&gt;</code></pre>
    </div>

    <div id="models" class="section">
        <h2>Models</h2>
        <p>Models represent the data and business logic of your application. They are stored in the <code>app/Models</code> directory.</p>

        <h3>Basic Model</h3>
        <pre><code>namespace App\Models;

use Kayra\Database\Model;

class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password'];
    protected array $hidden = ['password'];
}</code></pre>

        <h3>Model Methods</h3>
        <ul>
            <li><code>find($id)</code>: Find a record by ID</li>
            <li><code>findBy($column, $value)</code>: Find a record by column value</li>
            <li><code>all()</code>: Get all records</li>
            <li><code>create($data)</code>: Create a new record</li>
            <li><code>update($id, $data)</code>: Update a record</li>
            <li><code>delete($id)</code>: Delete a record</li>
        </ul>
    </div>

    <div id="database" class="section">
        <h2>Database</h2>
        <p>KayraPHP provides a simple and efficient database abstraction layer. Database configuration is stored in the <code>config/database.php</code> file.</p>

        <h3>Configuration</h3>
        <pre><code>// config/database.php
return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'kayra'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
    ],
];</code></pre>

        <h3>Query Builder</h3>
        <pre><code>use Kayra\Database\DB;

// Select
$users = DB::table('users')->where('active', 1)->get();

// Insert
DB::table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);

// Update
DB::table('users')->where('id', 1)->update(['name' => 'Jane']);

// Delete
DB::table('users')->where('id', 1)->delete();</code></pre>

        <h3>Migrations</h3>
        <p>Migrations are stored in the <code>database/migrations</code> directory.</p>
        <pre><code>// database/migrations/2023_01_01_create_users_table.php
namespace Database\Migrations;

use Kayra\Database\Migration;
use Kayra\Database\Schema;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}</code></pre>
    </div>

    <div id="middleware" class="section">
        <h2>Middleware</h2>
        <p>Middleware provides a mechanism for filtering HTTP requests entering your application. They are stored in the <code>app/Middlewares</code> directory.</p>

        <h3>Basic Middleware</h3>
        <pre><code>namespace App\Middlewares;

use Kayra\Http\Middleware;
use Kayra\Http\Request;
use Kayra\Http\Response;

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!$request->session()->has('user_id')) {
            return redirect('/login');
        }

        return $next($request);
    }
}</code></pre>

        <h3>Registering Middleware</h3>
        <p>Register middleware in the <code>config/app.php</code> file:</p>
        <pre><code>'middleware' => [
    'global' => [
        \App\Middlewares\CsrfMiddleware::class,
    ],
    'aliases' => [
        'auth' => \App\Middlewares\AuthMiddleware::class,
        'guest' => \App\Middlewares\GuestMiddleware::class,
    ],
],</code></pre>
    </div>

    <div id="validation" class="section">
        <h2>Validation</h2>
        <p>KayraPHP provides a simple validation system for validating input data.</p>

        <h3>Basic Validation</h3>
        <pre><code>use Kayra\Http\Request;
use Kayra\Validation\Validator;

public function store(Request $request): Response
{
    $validator = new Validator($request->all(), [
        'name' => 'required|min:3|max:255',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|min:8',
    ]);

    if ($validator->fails()) {
        return redirect()->back()->withErrors($validator->errors());
    }

    // Process valid data
}</code></pre>

        <h3>Available Validation Rules</h3>
        <ul>
            <li><code>required</code>: The field is required</li>
            <li><code>email</code>: The field must be a valid email address</li>
            <li><code>min:value</code>: The field must be at least value characters</li>
            <li><code>max:value</code>: The field must be at most value characters</li>
            <li><code>unique:table,column</code>: The field must be unique in the specified table column</li>
            <li><code>confirmed</code>: The field must have a matching field_confirmation field</li>
            <li><code>numeric</code>: The field must be a number</li>
            <li><code>integer</code>: The field must be an integer</li>
            <li><code>date</code>: The field must be a valid date</li>
            <li><code>in:foo,bar,...</code>: The field must be included in the given list of values</li>
        </ul>
    </div>

    <div id="authentication" class="section">
        <h2>Authentication</h2>
        <p>KayraPHP provides a simple authentication system.</p>

        <h3>Basic Authentication</h3>
        <pre><code>use Kayra\Auth\Auth;

// Login
if (Auth::attempt(['email' => $email, 'password' => $password])) {
    return redirect('/dashboard');
}

// Check if user is authenticated
if (Auth::check()) {
    // User is authenticated
}

// Get authenticated user
$user = Auth::user();

// Logout
Auth::logout();</code></pre>

        <h3>Authentication Middleware</h3>
        <pre><code>namespace App\Middlewares;

use Kayra\Auth\Auth;
use Kayra\Http\Middleware;
use Kayra\Http\Request;
use Kayra\Http\Response;

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            return redirect('/login');
        }

        return $next($request);
    }
}</code></pre>
    </div>

    <div id="authorization" class="section">
        <h2>Authorization</h2>
        <p>KayraPHP provides a simple authorization system for controlling access to resources.</p>

        <h3>Basic Authorization</h3>
        <pre><code>use Kayra\Auth\Gate;

// Define abilities
Gate::define('update-post', function ($user, $post) {
    return $user->id === $post->user_id;
});

// Check abilities
if (Gate::allows('update-post', $post)) {
    // User can update the post
}

if (Gate::denies('update-post', $post)) {
    // User cannot update the post
}</code></pre>

        <h3>Policy Classes</h3>
        <pre><code>namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id || $user->isAdmin();
    }
}</code></pre>
    </div>

    <div id="caching" class="section">
        <h2>Caching</h2>
        <p>KayraPHP provides a simple caching system. Cache configuration is stored in the <code>config/cache.php</code> file.</p>

        <h3>Configuration</h3>
        <pre><code>// config/cache.php
return [
    'default' => env('CACHE_DRIVER', 'file'),
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('cache'),
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
    ],
];</code></pre>

        <h3>Basic Usage</h3>
        <pre><code>use Kayra\Cache\Cache;

// Store a value in the cache
Cache::put('key', 'value', 60); // Store for 60 seconds

// Retrieve a value from the cache
$value = Cache::get('key');

// Retrieve a value with a default
$value = Cache::get('key', 'default');

// Check if a key exists in the cache
if (Cache::has('key')) {
    // Key exists
}

// Remove a value from the cache
Cache::forget('key');

// Clear the entire cache
Cache::flush();</code></pre>
    </div>

    <div id="logging" class="section">
        <h2>Logging</h2>
        <p>KayraPHP provides a simple logging system based on Monolog. Logs are stored in the <code>storage/logs</code> directory.</p>

        <h3>Basic Usage</h3>
        <pre><code>use Kayra\Logger\Log;

// Log an informational message
Log::info('User logged in', ['user_id' => 1]);

// Log a warning
Log::warning('Payment failed', ['user_id' => 1, 'amount' => 100]);

// Log an error
Log::error('Something went wrong', ['exception' => $exception]);

// Log levels: debug, info, notice, warning, error, critical, alert, emergency
Log::debug('Debugging information');
Log::critical('System is down');</code></pre>
    </div>

    <div id="events" class="section">
        <h2>Events</h2>
        <p>KayraPHP provides a simple event system for dispatching and listening to events.</p>

        <h3>Defining Events</h3>
        <pre><code>namespace App\Events;

class UserRegistered
{
    public function __construct(public $user)
    {
    }
}</code></pre>

        <h3>Defining Listeners</h3>
        <pre><code>namespace App\Listeners;

use App\Events\UserRegistered;
use Kayra\Logger\Log;

class SendWelcomeEmail
{
    public function handle(UserRegistered $event): void
    {
        Log::info('Sending welcome email to user', ['user_id' => $event->user->id]);
        // Send email logic
    }
}</code></pre>

        <h3>Registering Event Listeners</h3>
        <pre><code>// app/Providers/EventServiceProvider.php
namespace App\Providers;

use App\Events\UserRegistered;
use App\Listeners\SendWelcomeEmail;
use Kayra\Events\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected array $listen = [
        UserRegistered::class => [
            SendWelcomeEmail::class,
        ],
    ];
}</code></pre>

        <h3>Dispatching Events</h3>
        <pre><code>use App\Events\UserRegistered;
use Kayra\Events\Event;

$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => bcrypt('password'),
]);

Event::dispatch(new UserRegistered($user));</code></pre>
    </div>

    <div id="storage" class="section">
        <h2>Storage</h2>
        <p>KayraPHP provides a simple file storage system. Storage configuration is stored in the <code>config/storage.php</code> file.</p>

        <h3>Configuration</h3>
        <pre><code>// config/storage.php
return [
    'default' => env('STORAGE_DRIVER', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
        ],
    ],
];</code></pre>

        <h3>Basic Usage</h3>
        <pre><code>use Kayra\Storage\Storage;

// Store a file
Storage::put('file.txt', 'Contents');

// Check if a file exists
if (Storage::exists('file.txt')) {
    // File exists
}

// Get the contents of a file
$contents = Storage::get('file.txt');

// Get the size of a file
$size = Storage::size('file.txt');

// Delete a file
Storage::delete('file.txt');

// Store a file from an upload
$path = Storage::putFile('uploads', $request->file('photo'));</code></pre>
    </div>

    <div id="cli" class="section">
        <h2>Command Line Interface</h2>
        <p>KayraPHP provides a command-line interface tool for managing your application.</p>

        <h3>Available Commands</h3>
        <pre><code>php kayra list                  # List all available commands
php kayra key:generate          # Generate application encryption key
php kayra cache:clear           # Clear application cache
php kayra route:list            # List all registered routes
php kayra serve                 # Start development server
php kayra make:controller       # Create a new controller
php kayra make:model            # Create a new model
php kayra migrate               # Run database migrations
php kayra container:compile     # Compile dependency injection container</code></pre>

        <h3>Creating Custom Commands</h3>
        <pre><code>namespace App\Console\Commands;

use Kayra\Console\Command;

class HelloCommand extends Command
{
    protected string $signature = 'hello {name : The name of the person}';
    protected string $description = 'Say hello to someone';

    public function handle(): int
    {
        $name = $this->argument('name');
        $this->info("Hello, {$name}!");
        return 0;
    }
}</code></pre>

        <h3>Registering Custom Commands</h3>
        <pre><code>// app/Providers/ConsoleServiceProvider.php
namespace App\Providers;

use App\Console\Commands\HelloCommand;
use Kayra\Console\ConsoleServiceProvider as ServiceProvider;

class ConsoleServiceProvider extends ServiceProvider
{
    protected array $commands = [
        HelloCommand::class,
    ];
}</code></pre>
    </div>

    <div id="testing" class="section">
        <h2>Testing</h2>
        <p>KayraPHP provides a simple testing framework based on PHPUnit. Tests are stored in the <code>tests</code> directory.</p>

        <h3>Unit Tests</h3>
        <pre><code>namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\Calculator;

class CalculatorTest extends TestCase
{
    public function testAddition(): void
    {
        $calculator = new Calculator();
        $this->assertEquals(4, $calculator->add(2, 2));
    }
}</code></pre>

        <h3>Feature Tests</h3>
        <pre><code>namespace Tests\Feature;

use Kayra\Testing\TestCase;

class HomeTest extends TestCase
{
    public function testHomePage(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Welcome to KayraPHP Framework');
    }
}</code></pre>

        <h3>Running Tests</h3>
        <pre><code>./vendor/bin/phpunit            # Run all tests
./vendor/bin/phpunit --filter=testHomePage  # Run a specific test</code></pre>
    </div>

    <div id="deployment" class="section">
        <h2>Deployment</h2>
        <p>KayraPHP applications can be deployed in various ways.</p>

        <h3>Traditional Hosting</h3>
        <ol>
            <li>Upload your application files to the server</li>
            <li>Set the document root to the <code>public</code> directory</li>
            <li>Configure the web server (Apache, Nginx) to rewrite all requests to <code>public/index.php</code></li>
            <li>Set appropriate permissions for storage and cache directories</li>
            <li>Run <code>composer install --no-dev</code> to install dependencies</li>
            <li>Run <code>php kayra key:generate</code> to generate an application key</li>
            <li>Run <code>php kayra migrate</code> to run database migrations</li>
        </ol>

        <h3>Docker Deployment</h3>
        <ol>
            <li>Build your Docker image: <code>docker build -t myapp .</code></li>
            <li>Push your Docker image to a registry: <code>docker push myapp</code></li>
            <li>Deploy your Docker image to your server or cloud provider</li>
        </ol>

        <h3>Production Optimizations</h3>
        <pre><code># Optimize autoloader
composer install --optimize-autoloader --no-dev

# Compile container
php kayra container:compile

# Clear cache
php kayra cache:clear

# Set environment to production
APP_ENV=production</code></pre>
    </div>

    <div id="performance" class="section">
        <h2>Performance Optimization</h2>
        <p>KayraPHP is designed for performance, but there are additional optimizations you can make.</p>

        <h3>Opcache</h3>
        <p>Enable and configure Opcache in your php.ini:</p>
        <pre><code>opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
opcache.enable_cli=1</code></pre>

        <h3>Preloading</h3>
        <p>Use PHP 7.4+ preloading to load frequently used classes into memory:</p>
        <pre><code>// preload.php
opcache_compile_file(__DIR__ . '/vendor/autoload.php');
// Add more files to preload</code></pre>

        <h3>Ultra Performance Mode</h3>
        <p>Use Swoole extension for ultra performance:</p>
        <pre><code>php kayra serve --mode=ultra</code></pre>
    </div>

    <div id="security" class="section">
        <h2>Security</h2>
        <p>KayraPHP includes several security features to protect your application.</p>

        <h3>CSRF Protection</h3>
        <p>KayraPHP automatically protects your application from CSRF attacks. Include the CSRF token in your forms:</p>
        <pre><code>&lt;form method="POST" action="/contact"&gt;
    &lt;input type="hidden" name="_token" value="&lt;?= csrf_token() ?&gt;"&gt;
    &lt;!-- Form fields --&gt;
&lt;/form&gt;</code></pre>

        <h3>XSS Protection</h3>
        <p>Always escape output to prevent XSS attacks:</p>
        <pre><code>&lt;?= htmlspecialchars($variable, ENT_QUOTES, 'UTF-8') ?&gt;</code></pre>

        <h3>SQL Injection Protection</h3>
        <p>Use parameterized queries to prevent SQL injection:</p>
        <pre><code>$users = DB::table('users')
    ->where('email', $email)
    ->get();</code></pre>

        <h3>Authentication</h3>
        <p>Use the built-in authentication system to secure your application:</p>
        <pre><code>if (!Auth::check()) {
    return redirect('/login');
}</code></pre>
    </div>

    <div id="troubleshooting" class="section">
        <h2>Troubleshooting</h2>
        <p>Common issues and their solutions.</p>

        <h3>500 Internal Server Error</h3>
        <ol>
            <li>Check the error logs in <code>storage/logs</code></li>
            <li>Ensure proper permissions for storage and cache directories</li>
            <li>Verify that all required PHP extensions are installed</li>
            <li>Check that your .env file exists and is properly configured</li>
        </ol>

        <h3>404 Not Found</h3>
        <ol>
            <li>Check that your routes are properly defined</li>
            <li>Verify that your web server is configured to rewrite requests to index.php</li>
            <li>Ensure that the controller and method specified in the route exist</li>
        </ol>

        <h3>Database Connection Issues</h3>
        <ol>
            <li>Verify database credentials in your .env file</li>
            <li>Ensure that the database server is running</li>
            <li>Check that the database and tables exist</li>
        </ol>
    </div>

    <div class="back-to-home">
        <a href="/" class="btn">← Back to Home</a>
    </div>
</div>
</body>
</html>