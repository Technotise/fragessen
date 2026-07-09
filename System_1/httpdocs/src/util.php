<?php
declare(strict_types=1);

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensure_dir(string $path): void {
  if (!is_dir($path)) {
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
      throw new RuntimeException('Konnte Verzeichnis nicht anlegen: ' . $path);
    }
  }
}

function sha256_file(string $tmpPath): string {
  $hash = hash_file('sha256', $tmpPath);
  if (!$hash) throw new RuntimeException('Konnte Hash nicht berechnen');
  return $hash;
}
