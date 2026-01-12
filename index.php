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
logMessage('Return parameter: ' . ($_GET['return'] ?? 'empty'));

// Get the return page from the query parameter
$returnTo = $_GET['return'] ?? '';
$hasReturnParam = !empty($returnTo);

// If no return parameter, redirect to default page
if (!$hasReturnParam) {
    logMessage("No return parameter provided, redirecting to default page");
    logMessage('=== Request completed ===');
    header('Location: dist/index.html', true, 302);
    exit;
}

// Sanitize the return path to prevent directory traversal
$returnTo = basename($returnTo);
if (!preg_match('/^[a-z0-9_-]+\.html$/i', $returnTo)) {
    $returnTo = 'index.html';
}
logMessage("Sanitized return path: $returnTo");

// When return parameter is provided: delete dist files, pull from git, then regenerate
$output = [];
$exitCode = 0;

// Step 1: Delete all HTML files in dist directory
logMessage("Step 1: Deleting HTML files in dist directory");
$distDir = __DIR__ . '/dist';
if (is_dir($distDir)) {
    $htmlFiles = glob($distDir . '/*.html');
    $deletedCount = 0;
    foreach ($htmlFiles as $htmlFile) {
        if (unlink($htmlFile)) {
            $deletedCount++;
        }
    }
    logMessage("Deleted $deletedCount HTML files from dist directory");
} else {
    logMessage("Dist directory does not exist, creating it");
    mkdir($distDir, 0755, true);
}

// Step 2: Pull latest from git remote for TomsBookmarks
logMessage("Step 2: Pulling latest from git remote for TomsBookmarks");
$bookmarksDir = __DIR__ . '/TomsBookmarks';
$startTime = microtime(true);

// Change to the bookmarks directory and execute git commands
// Using chdir() instead of cd in shell for better reliability
$originalDir = getcwd();
if (!chdir($bookmarksDir)) {
    logMessage("ERROR: Could not change to bookmarks directory");
    http_response_code(500);
    echo '<pre>Failed to access TomsBookmarks directory</pre>';
    exit;
}

// Execute git fetch and reset
// Set environment variables to ensure proper DNS resolution and PATH
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
putenv('HOME=' . getenv('HOME'));

// Workaround for DNS resolution issues in Docker container:
// Use git's resolve option to specify IP address for hostname
logMessage("Setting git resolve to use IP 95.179.154.27 for git.7773.ch");

// Git supports --resolve option (like curl's --resolve) since version 2.18
// Format: --resolve=<host>:<port>:<ip>
$gitResolve = '--resolve=git.7773.ch:443:95.179.154.27';

$gitCommands = [
    "git -c http.curloptResolve=\"git.7773.ch:443:95.179.154.27\" fetch --all 2>&1",
    'git reset --hard origin/main 2>&1'
];

foreach ($gitCommands as $cmd) {
    $cmdOutput = [];
    $cmdExitCode = 0;
    logMessage("Executing: $cmd");
    exec($cmd, $cmdOutput, $cmdExitCode);
    $output = array_merge($output, $cmdOutput);

    if ($cmdExitCode !== 0) {
        $exitCode = $cmdExitCode;
        logMessage("ERROR: Git command failed with exit code $cmdExitCode");
        logMessage("Command: $cmd");

        // Log detailed output for debugging
        if (!empty($cmdOutput)) {
            logMessage("Command output:");
            foreach ($cmdOutput as $line) {
                logMessage("  > " . $line);
            }
        }
        break;
    }
}

// Change back to original directory
chdir($originalDir);

$duration = round((microtime(true) - $startTime) * 1000, 2);
logMessage("Git pull completed in {$duration}ms with exit code: $exitCode");

// Log git output line by line
if (!empty($output)) {
    logMessage("Git output:");
    foreach ($output as $line) {
        logMessage("  > " . $line);
    }
} else {
    logMessage("Git commands produced no output");
}

if ($exitCode !== 0) {
    // Git pull failed, stop here
    logMessage("ERROR: Git pull failed!");
    http_response_code(500);
    echo '<pre>' . htmlspecialchars("Failed to pull bookmarks from git (exit $exitCode)\n" . implode("\n", $output), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// Step 3: Regenerate HTML files (always regenerate after git pull)
logMessage("Step 3: Regenerating HTML files from XBEL sources");

require_once __DIR__ . '/xbel_static.php';

try {
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
