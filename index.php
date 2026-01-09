<?php
// Run bookmark build and redirect to generated index.

// Set no-cache headers to prevent caching of this page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

chdir(__DIR__);

// Logging function with rotation
function logMessage(string $message): void {
    $logFile = __DIR__ . '/index.log';
    $maxLines = 500;

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";

    // Read existing log
    $lines = file_exists($logFile) ? file($logFile) : [];

    // Add new entry
    $lines[] = $logEntry;

    // Keep only last $maxLines entries
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }

    // Write back
    file_put_contents($logFile, implode('', $lines));
}

logMessage('=== Request started ===');
logMessage('Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
logMessage('Return to: ' . ($_GET['return'] ?? 'index.html'));

$cmd1 = './TomsBookmarks/fetchForced.sh';
$lastLine = '';
$output = [];
$exitCode = 0;

// Get the return page from the query parameter (default to index.html)
$returnTo = $_GET['return'] ?? 'index.html';
// Sanitize the return path to prevent directory traversal
$returnTo = basename($returnTo);
if (!preg_match('/^[a-z0-9_-]+\.html$/i', $returnTo)) {
    $returnTo = 'index.html';
}
logMessage("Sanitized return path: $returnTo");

// ALWAYS run the fetch script to get latest bookmarks
logMessage("Executing fetch script: $cmd1");
$startTime = microtime(true);
exec('bash -lc ' . escapeshellarg($cmd1), $output, $exitCode);
$duration = round((microtime(true) - $startTime) * 1000, 2);
logMessage("Fetch script completed in {$duration}ms with exit code: $exitCode");

// Log fetch output line by line
if (!empty($output)) {
    logMessage("Fetch script output:");
    foreach ($output as $line) {
        logMessage("  > " . $line);
    }
} else {
    logMessage("Fetch script produced no output");
}

if ($exitCode !== 0) {
    // Fetch failed, stop here
    logMessage("ERROR: Fetch script failed!");
    http_response_code(500);
    echo '<pre>' . htmlspecialchars("Failed to fetch bookmarks (exit $exitCode)\n" . implode("\n", $output), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// Check if we need to regenerate HTML files
function needsRegeneration(string $baseDir): bool {
    $xbelFiles = glob($baseDir . '/TomsBookmarks/*.xbel');
    $distDir = $baseDir . '/dist';

    if (empty($xbelFiles)) {
        return false; // No source files, nothing to do
    }

    // If dist directory doesn't exist or is empty, we need to generate
    if (!is_dir($distDir) || count(glob($distDir . '/*.html')) === 0) {
        return true;
    }

    // Get the newest XBEL modification time
    $newestXbelTime = 0;
    foreach ($xbelFiles as $xbelFile) {
        $mtime = filemtime($xbelFile);
        if ($mtime > $newestXbelTime) {
            $newestXbelTime = $mtime;
        }
    }

    // Get the oldest HTML modification time
    $oldestHtmlTime = PHP_INT_MAX;
    $htmlFiles = glob($distDir . '/*.html');
    foreach ($htmlFiles as $htmlFile) {
        $mtime = filemtime($htmlFile);
        if ($mtime < $oldestHtmlTime) {
            $oldestHtmlTime = $mtime;
        }
    }

    // Regenerate if any XBEL file is newer than the oldest HTML file
    return $newestXbelTime > $oldestHtmlTime;
}

// Generate HTML if needed
logMessage("Checking if HTML regeneration is needed...");
$needsRegen = needsRegeneration(__DIR__);
logMessage("Needs regeneration: " . ($needsRegen ? 'YES' : 'NO'));

require_once __DIR__ . '/xbel_static.php';

try {
    if ($needsRegen) {
        $xbelFiles = glob(__DIR__ . '/TomsBookmarks/*.xbel');
        logMessage("Found " . count($xbelFiles) . " XBEL files");

        if (empty($xbelFiles)) {
            throw new Exception('No XBEL files found');
        }

        $startTime = microtime(true);
        buildSite($xbelFiles, __DIR__ . '/dist', 'GICT Bookmarks');
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        logMessage("HTML files regenerated in {$duration}ms");
        $output[] = 'HTML files regenerated';
    } else {
        logMessage("HTML files are up-to-date, skipping regeneration");
        $output[] = 'HTML files are up-to-date, skipping regeneration';
    }
    $exitCode = 0;
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    $output[] = 'PHP Error: ' . $e->getMessage();
    $exitCode = 1;
}

if ($exitCode === 0) {
    logMessage("Redirecting to: dist/$returnTo");
    logMessage('=== Request completed successfully ===');
    header('Location: dist/' . $returnTo, true, 302);
    exit;
}

logMessage("ERROR: Failed with exit code $exitCode");
logMessage('=== Request failed ===');
http_response_code(500);
?>
<pre><?php echo htmlspecialchars("Failed to generate bookmarks (exit $exitCode)\n" . implode("\n", $output), ENT_QUOTES, 'UTF-8'); ?></pre>
