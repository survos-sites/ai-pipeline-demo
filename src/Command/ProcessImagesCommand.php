<?php
declare(strict_types=1);

namespace App\Command;

use Survos\AiPipelineBundle\Storage\JsonFileResultStore;
use Survos\AiPipelineBundle\Task\AiPipelineRunner;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads public/data/images.json, runs the configured pipeline for each entry,
 * and writes results to public/data/{sha1}.json.
 *
 * After processing, regenerates public/data/images.json with a `result_file`
 * field pointing at the result JSON so the static viewer can find it.
 *
 * Usage:
 *   bin/console app:process                    # process all entries
 *   bin/console app:process --limit=1          # process first entry only
 *   bin/console app:process --force            # re-run even if results exist
 *   bin/console app:process --manifest=other.json
 */
#[AsCommand('app:process', 'Process images.json through configured AI pipelines')]
final class ProcessImagesCommand extends Command
{
    public function __construct(
        private readonly AiPipelineRunner $runner,
        private readonly AiTaskRegistry   $registry,
        #[Autowire('%kernel.project_dir%/public/data')]
        private readonly string $dataDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('manifest', 'm', InputOption::VALUE_REQUIRED,
                'Path to images.json (absolute, or relative to public/data/)',
                'images.json')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'Stop after processing this many entries')
            ->addOption('force', 'f', InputOption::VALUE_NONE,
                'Re-run tasks even if a result file already exists')
            ->addOption('tasks', 't', InputOption::VALUE_REQUIRED,
                'Override the pipeline for all entries (comma-separated task names)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $force    = (bool) $input->getOption('force');
        $limit    = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $taskOver = $input->getOption('tasks');

        // ── Load manifest ─────────────────────────────────────────────────────
        $manifestPath = $this->resolveManifest((string) $input->getOption('manifest'));
        if (!is_file($manifestPath)) {
            $io->error("Manifest not found: {$manifestPath}");
            return Command::FAILURE;
        }

        $entries = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($entries)) {
            $io->error("Invalid JSON in {$manifestPath}");
            return Command::FAILURE;
        }

        $io->title('AI Pipeline — Process Images');
        $io->writeln(sprintf('Manifest : %s (%d entries)', $manifestPath, count($entries)));
        $io->writeln(sprintf('Data dir : %s', $this->dataDir));
        $io->newLine();

        $registered = array_keys($this->registry->getTaskMap());
        if ($registered === []) {
            $io->error('No tasks registered. Check your services.yaml / bundle configuration.');
            return Command::FAILURE;
        }
        $io->writeln('Registered tasks: ' . implode(', ', $registered));
        $io->newLine();

        // ── Process each entry ────────────────────────────────────────────────
        $processed = 0;

        foreach ($entries as $i => &$entry) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $url      = $entry['url']      ?? null;
            $title    = $entry['title']    ?? $url;
            $pipeline = $taskOver
                ? array_filter(array_map('trim', explode(',', $taskOver)))
                : ($entry['pipeline'] ?? $registered);

            if ($url === null) {
                $io->warning("Entry {$i} has no 'url' — skipping.");
                continue;
            }

            $io->section(sprintf('[%d/%d] %s', $i + 1, count($entries), $title));

            $store     = new JsonFileResultStore($url, $this->dataDir);
            $priorKeys = array_keys($store->getAllPrior());

            if (!$force && $priorKeys !== []) {
                $pending = array_diff($pipeline, $priorKeys);
                if ($pending === []) {
                    $io->comment('  All tasks already complete — skipping (use --force to rerun).');
                    $entry['result_file'] = basename($store->getFilePath());
                    $entry['status']      = 'complete';
                    continue;
                }
                $io->comment(sprintf('  Resuming — %d task(s) remaining: %s', count($pending), implode(', ', $pending)));
            }

            // Wire progress callbacks
            $this->runner->onBeforeTask(function (string $task) use ($io): void {
                $io->write(sprintf('  %-28s ', $task));
            });
            $this->runner->onAfterTask(function (string $task, array $result, string $status) use ($io): void {
                $label = match ($status) {
                    'done'    => '<info>done</info>',
                    'skipped' => '<comment>skipped</comment>',
                    'failed'  => '<error>FAILED: ' . ($result['error'] ?? '') . '</error>',
                    default   => $status,
                };
                $io->writeln($label);
            });

            $queue = array_values($pipeline);
            while ($queue !== []) {
                if ($this->runner->runNext($store, $queue) === null) {
                    break;
                }
            }

            $entry['result_file'] = basename($store->getFilePath());
            $entry['status']      = 'complete';
            $processed++;
        }
        unset($entry);

        // ── Write updated manifest ────────────────────────────────────────────
        file_put_contents(
            $manifestPath,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $io->newLine();
        $io->success(sprintf('Processed %d/%d entries. Manifest updated.', $processed, count($entries)));

        return Command::SUCCESS;
    }

    private function resolveManifest(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->dataDir . '/' . ltrim($path, '/');
    }
}
