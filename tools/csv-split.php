<?php

/**
 * Splits a big CSV into parts of N rows, each with the header row. Multi-line quoted cells
 * (post bodies) stay intact, unlike a plain line split. The original file is not changed.
 *
 *   php tools/csv-split.php C:\Code\import-files\posts.csv 2000
 *   -> posts.part1.csv, posts.part2.csv, ... next to the original
 *
 * Import the parts one after another with the same Importer settings (Unique Field wp_id).
 */
[$self, $file, $rows] = array_pad($argv, 3, null);
$rows = (int) ($rows ?: 2000);

if (! $file || ! is_file($file) || $rows < 1) {
    fwrite(STDERR, "Usage: php tools/csv-split.php <file.csv> [rows per part, default 2000]\n");
    exit(1);
}

$in = fopen($file, 'rb');
$header = fgetcsv($in, null, ',', '"', '');
$base = preg_replace('/\.csv$/i', '', $file);
$part = 0;
$count = 0;
$out = null;

while (($line = fgetcsv($in, null, ',', '"', '')) !== false) {
    if ($out === null || $count === $rows) {
        if ($out) {
            fclose($out);
        }
        $part++;
        $count = 0;
        $name = "{$base}.part{$part}.csv";
        if (file_exists($name)) {
            fwrite(STDERR, "{$name} already exists. Delete the old parts first.\n");
            exit(1);
        }
        $out = fopen($name, 'wb');
        fputcsv($out, $header, ',', '"', '');
        echo "writing {$name}\n";
    }
    fputcsv($out, $line, ',', '"', '');
    $count++;
}

if ($out) {
    fclose($out);
}
fclose($in);
echo "Done: {$part} part(s).\n";
