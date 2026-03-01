<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Add a document to the pipeline manifests.
 *
 * Accepts:
 *   - A direct image/PDF URL  → adds straight to images.json or pdfs.json
 *   - An Omeka-S item page    → fetches metadata via /api/items/{id}, resolves media URL
 *   - A NARA catalog URL      → fetches metadata via proxy API, resolves page images / PDF
 *
 * Usage:
 *   bin/console app:add https://iaamcfh.omeka.net/s/IAAM_CFH/item/3940
 *   bin/console app:add https://catalog.archives.gov/id/5939992
 *   bin/console app:add https://example.com/document.pdf
 */
#[AsCommand('app:add', 'Add a document URL to the pipeline manifest')]
final class AddDocumentCommand extends Command
{
    /** Pipeline presets — user picks one of these */
    private const PIPELINES = [
        'handwritten_document' => [
            'ocr_mistral',
            'annotate_handwriting',
            'transcribe_handwriting',
            'people_and_places',
            'extract_metadata',
            'generate_title',
        ],
        'printed_document' => [
            'ocr_mistral',
            'classify',
            'extract_metadata',
            'summarize',
            'keywords',
        ],
        'photograph_or_card' => [
            'ocr_mistral',
            'classify',
            'basic_description',
            'keywords',
        ],
        'full_analysis' => [
            'ocr_mistral',
            'classify',
            'summarize',
            'keywords',
            'transcribe_handwriting',
            'annotate_handwriting',
            'people_and_places',
            'extract_metadata',
            'generate_title',
        ],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiTaskRegistry $registry,
        #[Autowire('%kernel.project_dir%/public/data')]
        private readonly string $dataDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'URL of the document, Omeka item page, or NARA catalog page')
            ->addOption('pipeline', 'p', InputOption::VALUE_REQUIRED, 'Pipeline preset name (skip interactive choice)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Override the auto-detected title')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be added without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $url    = trim($input->getArgument('url'));
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Add Document to Pipeline');

        // ── Detect source type and extract metadata ─────────────────────
        $resolved = $this->resolveUrl($url, $io);
        if ($resolved === null) {
            return Command::FAILURE;
        }

        $io->section('Detected metadata');
        $io->definitionList(
            ['Title'      => $resolved['title'] ?? '(none)'],
            ['Media URL'  => $resolved['url'] ?? '(none)'],
            ['Type'       => $resolved['type'] ?? '(unknown)'],
            ['Collection' => $resolved['collection'] ?? '(none)'],
            ['Provenance' => $resolved['provenance'] ?? '(none)'],
        );

        if ($resolved['type'] === 'video') {
            $io->warning('Video items are not yet supported by the pipeline.');
            return Command::FAILURE;
        }

        if ($resolved['type'] === 'unsupported') {
            $io->warning('Could not determine a processable media file (image or PDF) from this URL.');
            return Command::FAILURE;
        }

        // Allow title override
        $titleOpt = $input->getOption('title');
        if ($titleOpt) {
            $resolved['title'] = $titleOpt;
        }

        // ── Choose pipeline ─────────────────────────────────────────────
        $pipelineOpt = $input->getOption('pipeline');
        if ($pipelineOpt && isset(self::PIPELINES[$pipelineOpt])) {
            $pipelineName = $pipelineOpt;
        } else {
            $choices = array_keys(self::PIPELINES);
            $descriptions = [];
            foreach (self::PIPELINES as $name => $tasks) {
                $descriptions[] = sprintf('%s  (%s)', $name, implode(' → ', $tasks));
            }
            $pipelineName = $io->choice('Select a pipeline', $descriptions, 0);
            // Extract the name from the formatted choice
            $pipelineName = explode(' ', $pipelineName)[0];
            $pipelineName = trim($pipelineName);
        }

        $pipeline = self::PIPELINES[$pipelineName] ?? self::PIPELINES['printed_document'];
        $io->writeln(sprintf('Pipeline: <info>%s</info>', implode(' → ', $pipeline)));

        // ── Check for duplicates ────────────────────────────────────────
        $mediaUrl     = $resolved['url'];
        $manifestFile = $resolved['type'] === 'pdf' ? 'pdfs.json' : 'images.json';
        $manifestPath = $this->dataDir . '/' . $manifestFile;
        $entries      = [];
        if (is_file($manifestPath)) {
            $entries = json_decode(file_get_contents($manifestPath), true) ?? [];
        }

        foreach ($entries as $existing) {
            if (($existing['url'] ?? '') === $mediaUrl) {
                $io->warning("This URL is already in {$manifestFile} — skipping.");
                return Command::SUCCESS;
            }
        }

        // ── Build manifest entry ────────────────────────────────────────
        $entry = [
            'url'        => $mediaUrl,
            'provenance' => $resolved['provenance'] ?? null,
            'title'      => $resolved['title'] ?? basename($mediaUrl),
            'collection' => $resolved['collection'] ?? null,
            'pipeline'   => $pipeline,
        ];

        // Add metadata if we have it
        if (!empty($resolved['metadata'])) {
            $entry['metadata'] = $resolved['metadata'];
        }

        // Remove null values for cleanliness
        $entry = array_filter($entry, fn($v) => $v !== null);

        $io->section('Entry to add to ' . $manifestFile);
        $io->writeln(json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($dryRun) {
            $io->note('Dry run — nothing written.');
            return Command::SUCCESS;
        }

        // ── Write ───────────────────────────────────────────────────────
        $entries[] = $entry;
        file_put_contents(
            $manifestPath,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $io->success(sprintf('Added to %s. Run "bin/console app:process -m %s" to process it.', $manifestFile, $manifestFile));

        return Command::SUCCESS;
    }

    // ── URL resolution ──────────────────────────────────────────────────────

    /**
     * Detect the URL type and resolve to a media URL + metadata.
     *
     * Returns: ['url' => ..., 'title' => ..., 'type' => 'image'|'pdf'|'video'|'unsupported',
     *           'collection' => ..., 'provenance' => ..., 'metadata' => [...]]
     */
    private function resolveUrl(string $url, SymfonyStyle $io): ?array
    {
        // Omeka-S item page: https://{host}/s/{site}/item/{id}
        if (preg_match('#^(https?://[^/]+\.omeka\.net)/s/([^/]+)/item/(\d+)#', $url, $m)) {
            $io->writeln('Detected: <info>Omeka-S item page</info>');
            return $this->resolveOmekaItem($m[1], (int) $m[3], $url, $io);
        }

        // NARA catalog: https://catalog.archives.gov/id/{naId}
        if (preg_match('#^https?://catalog\.archives\.gov/id/(\d+)#', $url, $m)) {
            $io->writeln('Detected: <info>National Archives catalog record</info>');
            return $this->resolveNaraItem((int) $m[1], $url, $io);
        }

        // Direct PDF
        if (preg_match('/\.pdf(\?.*)?$/i', $url)) {
            $io->writeln('Detected: <info>Direct PDF URL</info>');
            return [
                'url'        => $url,
                'title'      => urldecode(basename(parse_url($url, PHP_URL_PATH) ?? $url)),
                'type'       => 'pdf',
                'provenance' => null,
                'collection' => null,
            ];
        }

        // Direct image
        if (preg_match('/\.(jpe?g|png|gif|webp|avif|tiff?)(\?.*)?$/i', $url)) {
            $io->writeln('Detected: <info>Direct image URL</info>');
            return [
                'url'        => $url,
                'title'      => urldecode(basename(parse_url($url, PHP_URL_PATH) ?? $url)),
                'type'       => 'image',
                'provenance' => null,
                'collection' => null,
            ];
        }

        $io->error("Cannot determine document type from URL: {$url}");
        $io->writeln('Supported: Omeka-S item pages, NARA catalog pages, direct .pdf/.jpg URLs');
        return null;
    }

    // ── Omeka-S ─────────────────────────────────────────────────────────────

    private function resolveOmekaItem(string $baseUrl, int $itemId, string $pageUrl, SymfonyStyle $io): ?array
    {
        $apiUrl = "{$baseUrl}/api/items/{$itemId}";
        $io->writeln("  Fetching: {$apiUrl}");

        try {
            $resp = $this->httpClient->request('GET', $apiUrl, ['timeout' => 15]);
            $item = $resp->toArray();
        } catch (\Throwable $e) {
            $io->error("Failed to fetch Omeka API: {$e->getMessage()}");
            return null;
        }

        $title = $item['o:title'] ?? $item['dcterms:title'][0]['@value'] ?? 'Untitled';

        // Get collection name from item sets
        $collection = null;
        if (!empty($item['o:item_set'])) {
            try {
                $setUrl = $item['o:item_set'][0]['@id'];
                $setResp = $this->httpClient->request('GET', $setUrl, ['timeout' => 10]);
                $setData = $setResp->toArray();
                $collection = $setData['o:title'] ?? null;
            } catch (\Throwable) {}
        }

        // Get the primary media to find the actual file URL
        $mediaUrl  = null;
        $mediaType = null;
        if (!empty($item['o:media'])) {
            $mediaApiUrl = $item['o:media'][0]['@id'];
            $io->writeln("  Fetching media: {$mediaApiUrl}");
            try {
                $mediaResp = $this->httpClient->request('GET', $mediaApiUrl, ['timeout' => 10]);
                $media     = $mediaResp->toArray();

                $mediaUrl  = $media['o:original_url'] ?? null;
                $mediaType = $media['o:media_type'] ?? null;
                $ingester  = $media['o:ingester'] ?? null;

                // Detect video embeds (HTML ingester with vimeo/youtube)
                if ($ingester === 'html') {
                    $html = $media['data']['html'] ?? $media['o-cnt:chars'] ?? '';
                    if (preg_match('/vimeo|youtube/i', $html)) {
                        return [
                            'url'        => $pageUrl,
                            'title'      => $title,
                            'type'       => 'video',
                            'provenance' => $pageUrl,
                            'collection' => $collection,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $io->warning("Could not fetch media details: {$e->getMessage()}");
            }
        }

        if (!$mediaUrl) {
            $io->warning('No downloadable media file found on this Omeka item.');
            return [
                'url'        => $pageUrl,
                'title'      => $title,
                'type'       => 'unsupported',
                'provenance' => $pageUrl,
                'collection' => $collection,
            ];
        }

        // Determine type from MIME or extension
        $type = 'image';
        if ($mediaType === 'application/pdf' || preg_match('/\.pdf$/i', $mediaUrl)) {
            $type = 'pdf';
        }

        // Build structured metadata from dcterms fields
        $metadata = $this->extractOmekaMetadata($item);

        return [
            'url'        => $mediaUrl,
            'title'      => $title,
            'type'       => $type,
            'provenance' => $pageUrl,
            'collection' => $collection,
            'metadata'   => $metadata ?: null,
        ];
    }

    /**
     * Extract structured metadata from Omeka-S dcterms fields.
     */
    private function extractOmekaMetadata(array $item): array
    {
        $metadata = [];

        // Map of dcterms fields to metadata keys
        $fieldMap = [
            'dcterms:description' => 'description',
            'dcterms:date'        => 'date',
            'dcterms:creator'     => 'creator',
            'dcterms:publisher'   => 'publisher',
            'dcterms:type'        => 'type',
            'dcterms:language'    => 'language',
            'dcterms:coverage'    => 'coverage',
            'dcterms:rights'      => 'rights',
            'dcterms:subject'     => 'subject',
        ];

        foreach ($fieldMap as $dcField => $key) {
            if (empty($item[$dcField])) {
                continue;
            }
            $values = array_map(fn($v) => $v['@value'] ?? '', $item[$dcField]);
            $values = array_filter($values);
            if (count($values) === 1) {
                $metadata[$key] = $values[0];
            } elseif (count($values) > 1) {
                $metadata[$key] = $values;
            }
        }

        return $metadata;
    }

    // ── National Archives (NARA) ────────────────────────────────────────────

    private function resolveNaraItem(int $naId, string $pageUrl, SymfonyStyle $io): ?array
    {
        $apiUrl = "https://catalog.archives.gov/proxy/records/search?naId_is={$naId}";
        $io->writeln("  Fetching: {$apiUrl}");

        try {
            $resp   = $this->httpClient->request('GET', $apiUrl, ['timeout' => 15]);
            $result = $resp->toArray();
        } catch (\Throwable $e) {
            $io->error("Failed to fetch NARA API: {$e->getMessage()}");
            return null;
        }

        $hits = $result['body']['hits']['hits'] ?? [];
        if (empty($hits)) {
            $io->error("No records found for naId {$naId}.");
            return null;
        }

        $record   = $hits[0]['_source']['record'] ?? [];
        $title    = $record['title'] ?? 'Untitled';

        // Get collection info from ancestors
        $collection = 'National Archives';
        $ancestors = $record['ancestors'] ?? [];
        foreach ($ancestors as $a) {
            if (($a['levelOfDescription'] ?? '') === 'series') {
                $collection = 'NARA — ' . ($a['title'] ?? 'Unknown Series');
                break;
            }
        }

        // Find digital objects — prefer individual page images over the giant PDF
        $digitalObjects = $record['digitalObjects'] ?? [];
        $images = [];
        $pdfUrl = null;

        foreach ($digitalObjects as $obj) {
            $objType = $obj['objectType'] ?? '';
            $objUrl  = $obj['objectUrl'] ?? '';
            if (str_contains($objType, 'Image')) {
                $images[] = $objUrl;
            } elseif (str_contains($objType, 'PDF')) {
                $pdfUrl = $objUrl;
            }
        }

        $io->writeln(sprintf('  Found %d page images and %s', count($images), $pdfUrl ? '1 PDF' : 'no PDF'));

        // For images, use the first page image as the representative URL
        // The full set of page URLs is stored in metadata for the viewer to iterate
        if ($images) {
            // Store all page image URLs as metadata so the pipeline can process each
            $metadata = [
                'naId'              => $naId,
                'scope'             => $record['scopeAndContentNote'] ?? null,
                'date_start'        => $record['coverageStartDate']['year'] ?? null,
                'date_end'          => $record['coverageEndDate']['year'] ?? null,
                'page_count'        => count($images),
            ];
            $metadata = array_filter($metadata, fn($v) => $v !== null);

            return [
                'url'        => $images[0],  // first page for thumbnail / single-image entry
                'title'      => $title,
                'type'       => 'image',
                'provenance' => $pageUrl,
                'collection' => $collection,
                'metadata'   => $metadata,
            ];
        }

        // Fall back to PDF
        if ($pdfUrl) {
            return [
                'url'        => $pdfUrl,
                'title'      => $title,
                'type'       => 'pdf',
                'provenance' => $pageUrl,
                'collection' => $collection,
            ];
        }

        $io->warning('No downloadable images or PDFs found in this NARA record.');
        return [
            'url'   => $pageUrl,
            'title' => $title,
            'type'  => 'unsupported',
        ];
    }
}
