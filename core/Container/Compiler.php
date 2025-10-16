<?php

namespace Kayra\Container;

use Kayra\Logger\LogManager;
use Kayra\Cache\CacheManager;
use Kayra\Storage\StorageManager;

class Compiler
{
    public function __construct(private Container $container) {}

    public function compileBasicServices(array $configs): void
    {
        $compiled = "<?php\n";
        $compiled .= "use Kayra\\Container\\Container;\n";
        $compiled .= "\$container = new Container();\n";

        // Logger
        $compiled .= "\$container->bind('logger', function() { return new LogManager(); });\n";

        // Cache
        $compiled .= "\$container->bind('cache', function() use (\$configs) { return new CacheManager(\$configs['cache']); });\n";

        // Storage
        $compiled .= "\$container->bind('storage', function() use (\$configs) { return new StorageManager(\$configs['storage']); });\n";

        // App
        $compiled .= "\$container->singleton('app', function() { return new \\Kayra\\Foundation\\Application(base_path()); });\n";

        // DB Pool
        $compiled .= "\$container->bind('db', function() use (\$configs) { return new \\Kayra\\Database\\ConnectionPool(\$configs['database']); });\n";

        // Events
        $compiled .= "\$container->bind('events', function() { return new \\Kayra\\Events\\EventDispatcher(); });\n";

        $compiled .= "return \$container;\n";

        $path = storage_path('cache/container.php');
        file_put_contents($path, $compiled);
    }
}