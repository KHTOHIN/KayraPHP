<?php

namespace Kayra\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class MakeControllerCommand extends Command
{
    protected static $defaultName = 'make:controller';
    protected static $defaultDescription = 'Create a new controller class';

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Controller name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $file = __DIR__ . '/../../../../app/Controllers/' . $name . 'Controller.php';
        if (file_exists($file)) {
            $output->writeln("<error>Controller already exists.</error>");
            return Command::FAILURE;
        }
        $content = "<?php\n\nnamespace App\\Controllers;\n\nuse Kayra\\Http\\Controller;\nuse Kayra\\Http\\Request;\nuse Kayra\\Http\\Response;\n\nclass {$name}Controller extends Controller\n{\n    public function index(Request \$request): Response\n    {\n        return \$this->response->json(['message' => 'Hello from {$name}']);\n    }\n}\n";
        file_put_contents($file, $content);
        $output->writeln("<info>Controller created: {$file}</info>");
        return Command::SUCCESS;
    }
}