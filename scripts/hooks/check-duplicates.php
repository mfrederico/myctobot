#!/usr/bin/env php
<?php
/**
 * Duplicate Code Scanner
 *
 * Scans the codebase for duplicate/copy-pasted code patterns.
 * Run before commits to identify refactoring opportunities.
 *
 * Usage:
 *   php scripts/check-duplicates.php [--min-lines=5] [--quick] [--json]
 *
 * Options:
 *   --min-lines   Minimum lines for a duplicate block (default: 5)
 *   --quick       Only scan controls/ and services/ (faster)
 *   --json        Output as JSON
 *   --verbose     Show all duplicates, not just top ones
 */

$baseDir = dirname(__DIR__);
chdir($baseDir);

// Parse arguments
$args = getopt('', ['min-lines:', 'quick', 'json', 'verbose', 'ollama', 'patterns:', 'help']);

if (isset($args['help'])) {
    echo <<<HELP
Duplicate Code Scanner

Scans the codebase for duplicate/copy-pasted code patterns.

Usage:
  php scripts/check-duplicates.php [options]

Options:
  --min-lines=N     Minimum lines for a duplicate block (default: 5)
  --quick           Only scan controls/ and services/ (faster)
  --json            Output as JSON
  --verbose         Show all duplicates, not just top ones
  --ollama          Use Ollama for semantic duplicate detection
  --patterns=FILE   Custom patterns JSON file (default: scripts/duplicate-patterns.json)
  --help            Show this help

Environment variables:
  OLLAMA_URL      Ollama API URL (default: http://localhost:11434)
  OLLAMA_MODEL    Model to use (default: deepseek-coder-v2:16b)

Custom Patterns:
  Add your own patterns to scripts/duplicate-patterns.json or specify a custom file.
  Patterns use PHP regex syntax. Severity levels: critical, high, medium, low.

Examples:
  php scripts/check-duplicates.php --quick
  php scripts/check-duplicates.php --min-lines=3 --verbose
  php scripts/check-duplicates.php --quick --ollama
  php scripts/check-duplicates.php --patterns=my-patterns.json

HELP;
    exit(0);
}

$minLines = (int)($args['min-lines'] ?? 5);
$quick = isset($args['quick']);
$jsonOutput = isset($args['json']);
$verbose = isset($args['verbose']);

// Directories to scan
$scanDirs = $quick
    ? ['controls', 'services']
    : ['controls', 'services', 'lib', 'models'];

echo "=== Duplicate Code Scanner ===\n\n";
echo "Settings: min-lines={$minLines}, dirs=" . implode(',', $scanDirs) . "\n\n";

// Collect all PHP files
$files = [];
foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) continue;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

echo "Scanning " . count($files) . " PHP files...\n\n";

// ============================================
// 1. Check for known duplicate patterns
// ============================================
echo "=== Known Pattern Analysis ===\n\n";

// Load patterns from config file
$patternsFile = $args['patterns'] ?? $baseDir . '/scripts/duplicate-patterns.json';
if (!file_exists($patternsFile)) {
    echo "Warning: Patterns file not found: {$patternsFile}\n";
    echo "Using built-in patterns.\n\n";
    $patterns = [];
} else {
    $patternsConfig = json_decode(file_get_contents($patternsFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Invalid JSON in patterns file: " . json_last_error_msg() . "\n";
        exit(1);
    }
    $patterns = [];
    foreach ($patternsConfig['patterns'] ?? [] as $name => $config) {
        $patterns[$name] = [
            'pattern' => '/' . $config['pattern'] . '/',
            'suggestion' => $config['suggestion'],
            'severity' => $config['severity'] ?? 'medium'
        ];
    }
    echo "Loaded " . count($patterns) . " patterns from: {$patternsFile}\n\n";
}

$patternResults = [];
foreach ($files as $filepath) {
    $content = file_get_contents($filepath);
    $relativePath = str_replace($baseDir . '/', '', $filepath);

    foreach ($patterns as $name => $config) {
        if (preg_match_all($config['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) {
            if (!isset($patternResults[$name])) {
                $patternResults[$name] = [
                    'suggestion' => $config['suggestion'],
                    'severity' => $config['severity'] ?? 'medium',
                    'locations' => []
                ];
            }
            foreach ($matches[0] as $match) {
                $lineNum = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $patternResults[$name]['locations'][] = "{$relativePath}:{$lineNum}";
            }
        }
    }
}

// Sort by severity first, then by occurrence count
$severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
uasort($patternResults, function($a, $b) use ($severityOrder) {
    $sevA = $severityOrder[$a['severity']] ?? 2;
    $sevB = $severityOrder[$b['severity']] ?? 2;
    if ($sevA !== $sevB) {
        return $sevA - $sevB;
    }
    return count($b['locations']) - count($a['locations']);
});

// Severity colors/indicators for terminal
$severityIndicators = [
    'critical' => "\033[91m[CRITICAL]\033[0m",
    'high' => "\033[93m[HIGH]\033[0m",
    'medium' => "\033[94m[MEDIUM]\033[0m",
    'low' => "\033[90m[LOW]\033[0m"
];

$totalPatterns = 0;
$criticalCount = 0;
foreach ($patternResults as $name => $data) {
    $count = count($data['locations']);
    if ($count >= 1) {  // Show all occurrences for critical/high
        $severity = $data['severity'] ?? 'medium';

        // Skip low severity if not verbose and < 5 occurrences
        if (!$verbose && $severity === 'low' && $count < 5) {
            continue;
        }
        // Skip medium severity if not verbose and < 3 occurrences
        if (!$verbose && $severity === 'medium' && $count < 3) {
            continue;
        }

        $totalPatterns++;
        if ($severity === 'critical') {
            $criticalCount += $count;
        }

        $indicator = $severityIndicators[$severity] ?? "[{$severity}]";
        echo "{$indicator} [{$count}x] {$name}\n";
        echo "     Suggestion: {$data['suggestion']}\n";
        if ($verbose) {
            foreach ($data['locations'] as $loc) {
                echo "     - {$loc}\n";
            }
        } else {
            foreach (array_slice($data['locations'], 0, 3) as $loc) {
                echo "     - {$loc}\n";
            }
            if ($count > 3) {
                echo "     ... and " . ($count - 3) . " more\n";
            }
        }
        echo "\n";
    }
}

if ($totalPatterns === 0) {
    echo "No significant patterns found\n\n";
}

if ($criticalCount > 0) {
    echo "\033[91mWARNING: {$criticalCount} critical pattern violations found!\033[0m\n\n";
}

// ============================================
// 2. Block-based duplicate detection
// ============================================
echo "=== Code Block Duplicates ===\n\n";

$blocks = [];
$blockIndex = [];

foreach ($files as $filepath) {
    $content = file_get_contents($filepath);
    $lines = explode("\n", $content);
    $relativePath = str_replace($baseDir . '/', '', $filepath);

    // Slide a window of $minLines over the file
    for ($i = 0; $i <= count($lines) - $minLines; $i++) {
        // Extract block and normalize whitespace
        $block = [];
        for ($j = 0; $j < $minLines; $j++) {
            $line = trim($lines[$i + $j]);
            // Skip empty lines and simple braces
            if ($line === '' || $line === '{' || $line === '}' || $line === '<?php') {
                continue;
            }
            // Normalize variable names for comparison
            $normalized = preg_replace('/\$\w+/', '$VAR', $line);
            $normalized = preg_replace('/[\'"][^\'"]+[\'"]/', '"STR"', $normalized);
            $block[] = $normalized;
        }

        if (count($block) < 3) continue; // Need at least 3 meaningful lines

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
$duplicateBlocks = [];
foreach ($blockIndex as $hash => $locations) {
    if (count($locations) < 2) continue;

    // Check if locations are in different files or far apart in same file
    $files = array_unique(array_column($locations, 'file'));
    if (count($files) >= 2 || count($locations) >= 3) {
        $duplicateBlocks[$hash] = $locations;
    }
}

// Sort by occurrence count
uasort($duplicateBlocks, fn($a, $b) => count($b) - count($a));

$shown = 0;
$maxShow = $verbose ? 20 : 5;

foreach ($duplicateBlocks as $hash => $locations) {
    if ($shown >= $maxShow) break;

    $count = count($locations);
    echo "[{$count}x] Duplicate block:\n";
    echo "     Preview: " . substr($locations[0]['preview'], 0, 80) . "...\n";

    $shownLocs = $verbose ? $locations : array_slice($locations, 0, 3);
    foreach ($shownLocs as $loc) {
        echo "     - {$loc['file']}:{$loc['line']}\n";
    }
    if (!$verbose && $count > 3) {
        echo "     ... and " . ($count - 3) . " more\n";
    }
    echo "\n";
    $shown++;
}

if (count($duplicateBlocks) > $maxShow && !$verbose) {
    echo "... and " . (count($duplicateBlocks) - $maxShow) . " more duplicate blocks (use --verbose to see all)\n\n";
}

if (empty($duplicateBlocks)) {
    echo "No significant code block duplicates found.\n\n";
}

// ============================================
// 3. Ollama-based semantic analysis (optional)
// ============================================
$useOllama = isset($args['ollama']) || getenv('USE_OLLAMA_ANALYSIS');
if ($useOllama) {
    echo "=== Ollama Semantic Analysis ===\n\n";

    $ollamaUrl = getenv('OLLAMA_URL') ?: 'http://localhost:11434';
    $ollamaModel = getenv('OLLAMA_MODEL') ?: 'deepseek-coder-v2:16b';

    // Extract function signatures for analysis
    $functions = [];
    foreach ($files as $filepath) {
        $content = file_get_contents($filepath);
        $relativePath = str_replace($baseDir . '/', '', $filepath);

        // Extract functions with their bodies (simplified)
        if (preg_match_all('/(?:public|private|protected)\s+function\s+(\w+)\s*\([^)]*\)\s*(?::\s*\w+)?\s*\{/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $funcName = $matches[1][$idx][0];
                $startPos = $match[1];

                // Find the closing brace (simple approach - count braces)
                $braceCount = 0;
                $started = false;
                $endPos = $startPos;
                for ($i = $startPos; $i < strlen($content); $i++) {
                    if ($content[$i] === '{') {
                        $braceCount++;
                        $started = true;
                    } elseif ($content[$i] === '}') {
                        $braceCount--;
                    }
                    if ($started && $braceCount === 0) {
                        $endPos = $i;
                        break;
                    }
                }

                $funcBody = substr($content, $startPos, $endPos - $startPos + 1);
                $lineNum = substr_count(substr($content, 0, $startPos), "\n") + 1;
                $lineCount = substr_count($funcBody, "\n");

                // Only analyze functions with 10+ lines
                if ($lineCount >= 10 && $lineCount <= 100) {
                    $functions[] = [
                        'name' => $funcName,
                        'file' => $relativePath,
                        'line' => $lineNum,
                        'lines' => $lineCount,
                        'body' => $funcBody
                    ];
                }
            }
        }
    }

    echo "Found " . count($functions) . " functions to analyze (10-100 lines)\n";

    if (count($functions) > 0) {
        // Sample functions for analysis (limit to avoid long processing)
        $sampleSize = min(20, count($functions));
        $sampledFunctions = array_slice($functions, 0, $sampleSize);

        // Build prompt for Ollama
        $functionList = "";
        foreach ($sampledFunctions as $idx => $func) {
            $preview = implode("\n", array_slice(explode("\n", $func['body']), 0, 15));
            $functionList .= "\n--- Function {$idx}: {$func['name']} ({$func['file']}:{$func['line']}) ---\n";
            $functionList .= $preview . "\n...\n";
        }

        $prompt = <<<PROMPT
Analyze these PHP functions for semantic duplicates - functions that do similar things even if the code looks different.

{$functionList}

List any pairs of functions that:
1. Perform similar operations (e.g., both validate input, both query database)
2. Could be merged into a single generic function
3. Have similar structure but different variable names

Format your response as a list:
- Function X and Function Y: [reason they're similar]

Only list clear duplicates, not vague similarities.
PROMPT;

        echo "Querying Ollama ({$ollamaModel})...\n\n";

        $ch = curl_init("{$ollamaUrl}/api/generate");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $ollamaModel,
                'prompt' => $prompt,
                'stream' => false,
                'options' => ['temperature' => 0.3]
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            echo "Ollama Analysis:\n";
            echo $data['response'] ?? 'No response';
            echo "\n\n";
        } else {
            echo "Failed to query Ollama (HTTP {$httpCode}). Is Ollama running?\n";
            echo "Start with: ollama serve\n\n";
        }
    }
} else {
    echo "\nTip: Add --ollama flag for AI-powered semantic duplicate detection\n";
}

// ============================================
// Summary
// ============================================
echo "\n=== Summary ===\n";
echo "Files scanned: " . count($files) . "\n";
echo "Pattern matches: " . array_sum(array_map(fn($p) => count($p['locations']), $patternResults)) . "\n";
echo "Duplicate blocks: " . count($duplicateBlocks) . "\n";

if ($jsonOutput) {
    echo "\n" . json_encode([
        'patterns' => $patternResults,
        'duplicates' => array_values($duplicateBlocks),
    ], JSON_PRETTY_PRINT) . "\n";
}
