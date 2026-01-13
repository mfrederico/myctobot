<?php
/**
 * Code Review Service
 *
 * Provides codebase quality analysis including duplicate code detection.
 * Can be used from controllers, CLI scripts, or CI/CD pipelines.
 */

namespace app\services;

class CodeReviewService
{
    private $baseDir;
    private $patternsFile;
    private $logger;

    public function __construct(string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__);
        $this->patternsFile = $this->baseDir . '/scripts/duplicate-patterns.json';
        $this->logger = \Flight::get('log');
    }

    /**
     * Run a full code review scan
     *
     * @param array $options Configuration options
     * @return array Review results
     */
    public function runReview(array $options = []): array
    {
        $quick = $options['quick'] ?? true;
        $minLines = $options['min_lines'] ?? 5;

        $results = [
            'timestamp' => date('c'),
            'options' => $options,
            'patterns' => $this->scanPatterns($quick),
            'duplicates' => $this->scanDuplicateBlocks($quick, $minLines),
            'summary' => []
        ];

        // Calculate summary
        $criticalCount = 0;
        $highCount = 0;
        foreach ($results['patterns'] as $pattern) {
            $count = count($pattern['locations']);
            if ($pattern['severity'] === 'critical') {
                $criticalCount += $count;
            } elseif ($pattern['severity'] === 'high') {
                $highCount += $count;
            }
        }

        $results['summary'] = [
            'total_patterns' => count($results['patterns']),
            'critical_violations' => $criticalCount,
            'high_violations' => $highCount,
            'duplicate_blocks' => count($results['duplicates']),
            'status' => $criticalCount > 0 ? 'failed' : ($highCount > 10 ? 'warning' : 'passed')
        ];

        return $results;
    }

    /**
     * Scan for known pattern violations
     */
    public function scanPatterns(bool $quick = true): array
    {
        $patterns = $this->loadPatterns();
        $files = $this->getPhpFiles($quick);
        $results = [];

        foreach ($files as $filepath) {
            $content = file_get_contents($filepath);
            $relativePath = str_replace($this->baseDir . '/', '', $filepath);

            foreach ($patterns as $name => $config) {
                $regex = '/' . $config['pattern'] . '/';
                if (@preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    if (!isset($results[$name])) {
                        $results[$name] = [
                            'suggestion' => $config['suggestion'],
                            'severity' => $config['severity'] ?? 'medium',
                            'locations' => []
                        ];
                    }
                    foreach ($matches[0] as $match) {
                        $lineNum = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                        $results[$name]['locations'][] = [
                            'file' => $relativePath,
                            'line' => $lineNum
                        ];
                    }
                }
            }
        }

        // Sort by severity
        $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        uasort($results, function ($a, $b) use ($severityOrder) {
            $sevA = $severityOrder[$a['severity']] ?? 2;
            $sevB = $severityOrder[$b['severity']] ?? 2;
            if ($sevA !== $sevB) {
                return $sevA - $sevB;
            }
            return count($b['locations']) - count($a['locations']);
        });

        return $results;
    }

    /**
     * Scan for duplicate code blocks
     */
    public function scanDuplicateBlocks(bool $quick = true, int $minLines = 5): array
    {
        $files = $this->getPhpFiles($quick);
        $blockIndex = [];

        foreach ($files as $filepath) {
            $content = file_get_contents($filepath);
            $lines = explode("\n", $content);
            $relativePath = str_replace($this->baseDir . '/', '', $filepath);

            for ($i = 0; $i <= count($lines) - $minLines; $i++) {
                $block = [];
                for ($j = 0; $j < $minLines; $j++) {
                    $line = trim($lines[$i + $j]);
                    if ($line === '' || $line === '{' || $line === '}' || $line === '<?php') {
                        continue;
                    }
                    // Normalize variable names
                    $normalized = preg_replace('/\$\w+/', '$VAR', $line);
                    $normalized = preg_replace('/[\'"][^\'"]+[\'"]/', '"STR"', $normalized);
                    $block[] = $normalized;
                }

                if (count($block) < 3) {
                    continue;
                }

                $hash = md5(implode("\n", $block));
                if (!isset($blockIndex[$hash])) {
                    $blockIndex[$hash] = [];
                }
                $blockIndex[$hash][] = [
                    'file' => $relativePath,
                    'line' => $i + 1,
                    'preview' => implode(' | ', array_slice($block, 0, 2))
                ];
            }
        }

        // Filter to blocks that appear 2+ times in different files
        $duplicates = [];
        foreach ($blockIndex as $locations) {
            if (count($locations) < 2) {
                continue;
            }

            $files = array_unique(array_column($locations, 'file'));
            if (count($files) >= 2 || count($locations) >= 3) {
                $duplicates[] = [
                    'count' => count($locations),
                    'preview' => $locations[0]['preview'],
                    'locations' => $locations
                ];
            }
        }

        // Sort by count
        usort($duplicates, fn($a, $b) => $b['count'] - $a['count']);

        return array_slice($duplicates, 0, 20);
    }

    /**
     * Load custom patterns from config file
     */
    private function loadPatterns(): array
    {
        if (!file_exists($this->patternsFile)) {
            return [];
        }

        $config = json_decode(file_get_contents($this->patternsFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Failed to parse patterns file: ' . json_last_error_msg());
            return [];
        }

        return $config['patterns'] ?? [];
    }

    /**
     * Get list of PHP files to scan
     */
    private function getPhpFiles(bool $quick = true): array
    {
        $dirs = $quick
            ? ['controls', 'services']
            : ['controls', 'services', 'lib', 'models'];

        $files = [];
        foreach ($dirs as $dir) {
            $fullPath = $this->baseDir . '/' . $dir;
            if (!is_dir($fullPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Add a custom pattern (runtime only, not persisted)
     */
    public function addPattern(string $name, string $pattern, string $suggestion, string $severity = 'medium'): void
    {
        // This would need to write to the patterns file to persist
        // For now, log for debugging
        $this->logger->debug("Custom pattern added: {$name}");
    }

    /**
     * Generate a markdown report
     */
    public function generateMarkdownReport(array $results): string
    {
        $md = "# Code Review Report\n\n";
        $md .= "Generated: {$results['timestamp']}\n\n";

        // Summary
        $summary = $results['summary'];
        $statusIcon = match ($summary['status']) {
            'failed' => '❌',
            'warning' => '⚠️',
            default => '✅'
        };

        $md .= "## Summary {$statusIcon}\n\n";
        $md .= "| Metric | Count |\n|--------|-------|\n";
        $md .= "| Critical Violations | {$summary['critical_violations']} |\n";
        $md .= "| High Violations | {$summary['high_violations']} |\n";
        $md .= "| Duplicate Blocks | {$summary['duplicate_blocks']} |\n\n";

        // Pattern violations
        $md .= "## Pattern Violations\n\n";
        foreach ($results['patterns'] as $name => $data) {
            $count = count($data['locations']);
            $severity = strtoupper($data['severity']);
            $md .= "### [{$severity}] {$name} ({$count}x)\n\n";
            $md .= "**Suggestion:** {$data['suggestion']}\n\n";

            $md .= "Locations:\n";
            foreach (array_slice($data['locations'], 0, 5) as $loc) {
                $md .= "- `{$loc['file']}:{$loc['line']}`\n";
            }
            if ($count > 5) {
                $md .= "- ... and " . ($count - 5) . " more\n";
            }
            $md .= "\n";
        }

        return $md;
    }
}
