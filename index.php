<?php
// Run bookmark build and redirect to generated index.
chdir(__DIR__);

$cmd = 'python3 xbel_static.py data/*xbel -t "GICT Bookmarks"';
$lastLine = '';
$output = [];
$exitCode = 0;

// Use bash to expand the glob.
exec('bash -lc ' . escapeshellarg($cmd), $output, $exitCode);

if ($exitCode === 0) {
    header('Location: dist/index.html', true, 302);
    exit;
}

http_response_code(500);
?>
<pre><?php echo htmlspecialchars("Failed to generate bookmarks (exit $exitCode)\n" . implode("\n", $output), ENT_QUOTES, 'UTF-8'); ?></pre>
