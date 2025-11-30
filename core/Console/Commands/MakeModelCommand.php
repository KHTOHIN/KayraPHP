<?php

namespace Kayra\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class MakeModelCommand extends Command
{
    protected static $defaultName = 'make:model';
    protected static $defaultDescription = 'Create a new model class';

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Model name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $file = __DIR__ . '/../../../../app/Models/' . $name . '.php';
        
        if (file_exists($file)) {
            $output->writeln("<error>Model already exists.</error>");
            return Command::FAILURE;
        }
        
        $content = "<?php\n\nnamespace App\\Models;\n\nuse Kayra\\Database\\Model;\n\nclass {$name} extends Model\n{\n    protected string \$table = '" . strtolower($name) . "s';\n    protected array \$fillable = [\n        // Define fillable fields here\n    ];\n    \n    // Define relationships and custom methods here\n}\n";
        
        file_put_contents($file, $content);
        $output->writeln("<info>Model created: {$file}</info>");
        
        return Command::SUCCESS;
    }
}