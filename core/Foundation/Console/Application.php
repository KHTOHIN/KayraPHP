<?php

namespace Kayra\Foundation\Console;

use Symfony\Component\Console\Application as SymfonyApplication;

class Application extends SymfonyApplication
{
    public function __construct(string $name = 'KayraPHP Console', string $version = '1.0.0')
    {
        parent::__construct($name, $version);
        // Auto-register commands from kayra.json
        $json = json_decode(file_get_contents(base_path('kayra.json')), true);
        foreach ($json['commands'] ?? [] as $command) {
            $this->add(new $command());
        }
    }
}