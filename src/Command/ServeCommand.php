<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Starts a local PHP web server serving public/ and optionally opens the browser.
 *
 * Usage:
 *   bin/console app:serve
 *   bin/console app:serve --port=8181 --no-open
 */
#[AsCommand('app:serve', 'Serve public/ on a local port and open the gallery in the browser')]
final class ServeCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen on', '8099')
            ->addOption('no-open', null, InputOption::VALUE_NONE, 'Do not open a browser tab');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $port = (int) $input->getOption('port');
        $url  = "http://localhost:{$port}";

        $io->title('AI Pipeline Demo — Local Server');
        $io->writeln("Serving  : {$this->publicDir}");
        $io->writeln("URL      : {$url}");
        $io->writeln('Press Ctrl+C to stop.');
        $io->newLine();

        if (!$input->getOption('no-open')) {
            $this->openBrowser($url);
        }

        // php -S is simplest — no dependency on the Symfony CLI
        $process = new Process(
            ['php', '-S', "localhost:{$port}", '-t', $this->publicDir],
            null,
            null,
            null,
            null, // no timeout
        );

        $process->run(function (string $type, string $buffer) use ($output): void {
            // php -S logs to stderr; show only non-empty lines
            foreach (explode("\n", rtrim($buffer)) as $line) {
                if (trim($line) !== '') {
                    $output->writeln("  <comment>{$line}</comment>");
                }
            }
        });

        return Command::SUCCESS;
    }

    private function openBrowser(string $url): void
    {
        $openers = match (PHP_OS_FAMILY) {
            'Darwin'  => ['open'],
            'Windows' => ['cmd', '/c', 'start'],
            default   => ['xdg-open'],     // Linux / WSL
        };

        try {
            (new Process([...$openers, $url]))->start();
        } catch (\Throwable) {
            // Non-fatal — server still starts, user can open manually
        }
    }
}
