<?php

/**
 * Merges KEY=value lines from one env file into another, without creating duplicate keys.
 *   - key already in the target  -> that line is replaced (value from the source wins)
 *   - key not in the target      -> appended at the end
 *   - comments / blank lines in the source are ignored
 * Makes a backup first (storage/app/env-backups/<file>.YYYYmmdd-His, git-ignored by Laravel). Prints every change.
 *
 *   php tools/env-merge.php .env.example.additions .env            # preview only
 *   php tools/env-merge.php .env.example.additions .env --write    # write (with backup)
 */
[$self, $source, $target] = array_pad($argv, 3, null);
$write = in_array('--write', $argv, true);

if (! $source || ! $target || ! is_file($source) || ! is_file($target)) {
    fwrite(STDERR, "Usage: php tools/env-merge.php <source> <target> [--write]\n");
    exit(1);
}

$parse = static function (string $line): ?array {
    return preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=(.*)$/', $line, $m) ? [$m[1], $line] : null;
};

$incoming = [];
foreach (file($source, FILE_IGNORE_NEW_LINES) as $line) {
    if ($kv = $parse($line)) {
        $incoming[$kv[0]] = rtrim(preg_replace('/\s+#.*$/', '', $kv[1])); // drop trailing comments
    }
}

$lines = file($target, FILE_IGNORE_NEW_LINES);
$seen = [];
foreach ($lines as $i => $line) {
    $kv = $parse($line);
    if (! $kv || ! array_key_exists($kv[0], $incoming)) {
        continue;
    }
    if (isset($seen[$kv[0]])) {          // a duplicate already in the target: comment it out
        $lines[$i] = '# (duplicate, disabled by env-merge) '.$line;
        echo "disable  {$kv[0]} (duplicate)\n";
        continue;
    }
    $seen[$kv[0]] = true;
    if (trim($line) !== $incoming[$kv[0]]) {
        echo "replace  {$line}\n      -> {$incoming[$kv[0]]}\n";
        $lines[$i] = $incoming[$kv[0]];
    }
}

$append = array_diff_key($incoming, $seen);
foreach ($append as $key => $line) {
    echo "append   {$line}\n";
}

if (! $write) {
    echo "\nPreview only. Re-run with --write to apply (a backup is made first).\n";
    exit(0);
}

$dir = is_dir('storage/app') ? 'storage/app/env-backups' : dirname($target);
@mkdir($dir, 0700, true);
$backup = $dir.'/'.basename($target).'.'.date('Ymd-His');
copy($target, $backup);
$out = implode(PHP_EOL, $lines);
if ($append) {
    $out = rtrim($out).PHP_EOL.PHP_EOL.'# ---- added by tools/env-merge.php ----'.PHP_EOL.implode(PHP_EOL, $append);
}
file_put_contents($target, $out.PHP_EOL);
echo "\nWritten {$target} (backup: {$backup})\n";
