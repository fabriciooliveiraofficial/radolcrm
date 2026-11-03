<?php

declare(strict_types=1);

// Garante que todo INSERT literal em app/ tenha o mesmo número de colunas e de valores
// (evita o erro SQLSTATE[21S01] 1136 "Column count doesn't match value count").

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));

$checked = 0;
$errors = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    $pattern = '/INSERT\s+(?:IGNORE\s+)?INTO\s+(\w+)\s*\(([^)]*)\)\s*VALUES\s*\(([^;]*?)\)\s*(?:ON\s+DUPLICATE|[\'"`])/is';
    if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($matches as $match) {
        $columns = array_filter(array_map('trim', explode(',', $match[2][0])), static fn(string $c): bool => $c !== '');
        $values = trim($match[3][0]);
        if (substr_count($values, '?') === 0) {
            continue; // valores literais/NOW(): não dá para comparar só por "?"
        }
        $placeholders = substr_count($values, '?');
        $literals = count(array_filter(array_map('trim', preg_split('/,(?![^(]*\))/', $values)), static fn(string $v): bool => $v !== '' && $v !== '?'));
        $totalValues = $placeholders + $literals;
        $checked++;
        if (count($columns) !== $totalValues) {
            $line = substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
            $errors[] = sprintf('%s:%d INSERT INTO %s tem %d colunas e %d valores', str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname()), $line, $match[1][0], count($columns), $totalValues);
        }
    }
}

echo "INSERTs com placeholders verificados: {$checked}\n";
if ($errors !== []) {
    foreach ($errors as $error) {
        echo "✗ {$error}\n";
    }
    exit(1);
}
echo "✓ Todos os INSERTs têm colunas e valores em igual número.\n";
