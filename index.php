<?php
// Run bookmark build and redirect to generated index.

// Set no-cache headers to prevent caching of this page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

chdir(__DIR__);

$cmd1 = './TomsBookmarks/fetchForced.sh';
$cmd2 = 'python3 xbel_static.py TomsBookmarks/*xbel -t "GICT Bookmarks"';
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

// Clear log file at start
//file_put_contents('cmd.log', '');

// Use bash to expand the glob.
exec('bash -lc ' . escapeshellarg($cmd1), $output, $exitCode);
//file_put_contents('cmd.log', "Command 1: $cmd1\nExit Code: $exitCode\nOutput:\n" . implode("\n", $output) . "\n\n", FILE_APPEND);

exec('bash -lc ' . escapeshellarg($cmd2), $output, $exitCode);
//file_put_contents('cmd.log', "Command 2: $cmd2\nExit Code: $exitCode\nOutput:\n" . implode("\n", $output) . "\n", FILE_APPEND);

if ($exitCode === 0) {
    header('Location: dist/' . $returnTo, true, 302);
    exit;
}

http_response_code(500);
?>
<pre><?php echo htmlspecialchars("Failed to generate bookmarks (exit $exitCode)\n" . implode("\n", $output), ENT_QUOTES, 'UTF-8'); ?></pre>
