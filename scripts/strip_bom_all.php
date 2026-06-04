<?php
// Recursively strip UTF-8 BOM (0xEF 0xBB 0xBF) and leading blank lines before <?php
$root = __DIR__ . "/..";
$exts = ['php', 'inc', 'phtml'];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $path = $f->getPathname();
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if (!in_array(strtolower($ext), $exts)) continue;
    $files[] = $path;
}

$changed = 0;
foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) continue;
    $orig = $content;
    // remove BOM
    if (strpos($content, "\xEF\xBB\xBF") === 0) {
        $content = substr($content, 3);
    }
    // remove whitespace or newlines before <?php
    $content = preg_replace("/^\s*(<\?php)/s", "$1", $content, 1);
    if ($content !== $orig) {
        file_put_contents($file, $content);
        echo "Fixed BOM/leading whitespace: $file\n";
        $changed++;
    }
}

if ($changed === 0) {
    echo "No files needed BOM removal.\n";
} else {
    echo "Processed $changed files.\n";
}
