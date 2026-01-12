#!/usr/bin/env php
<?php
// Test script to verify git pull works from PHP in Docker container

echo "=== Testing Git Pull from PHP ===\n\n";

// Test 1: DNS Resolution
echo "Test 1: DNS Resolution\n";
exec('getent hosts git.7773.ch 2>&1', $dnsOutput, $dnsCode);
echo "Exit code: $dnsCode\n";
echo "Output: " . implode("\n", $dnsOutput) . "\n\n";

// Test 2: Git version
echo "Test 2: Git Version\n";
exec('git --version 2>&1', $gitVersion, $gitCode);
echo "Exit code: $gitCode\n";
echo "Output: " . implode("\n", $gitVersion) . "\n\n";

// Test 3: Git fetch in TomsBookmarks (without DNS workaround)
echo "Test 3: Git Fetch in TomsBookmarks (without DNS workaround)\n";
$bookmarksDir = __DIR__ . '/TomsBookmarks';
$originalDir = getcwd();

if (!chdir($bookmarksDir)) {
    echo "ERROR: Could not change to bookmarks directory\n";
    exit(1);
}

echo "Working directory: " . getcwd() . "\n";

// Set environment
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
putenv('HOME=' . getenv('HOME'));

exec('git fetch --all 2>&1', $fetchOutput, $fetchCode);
echo "Exit code: $fetchCode\n";
echo "Output:\n";
foreach ($fetchOutput as $line) {
    echo "  $line\n";
}

// Test 4: Git fetch with IP resolve workaround
echo "\nTest 4: Git Fetch with IP resolve workaround\n";
$fetchOutput2 = [];
exec('git -c http.curloptResolve="git.7773.ch:443:95.179.154.27" fetch --all 2>&1', $fetchOutput2, $fetchCode2);
echo "Exit code: $fetchCode2\n";
echo "Output:\n";
foreach ($fetchOutput2 as $line) {
    echo "  $line\n";
}

chdir($originalDir);

if ($fetchCode2 === 0) {
    echo "\n✓ SUCCESS: Git pull works from PHP with IP workaround!\n";
} else {
    echo "\n✗ FAILED: Git pull failed from PHP even with workaround\n";
}
