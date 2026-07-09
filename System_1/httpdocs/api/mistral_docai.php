<?php
declare(strict_types=1);

/*
  api/mistral_docai.php

  CLI:
    php api/mistral_docai.php <slice.pdf> --schema /absoluter/pfad/schema.json

  Output:
    <slice>.mistral.json
*/

if (php_sapi_name() !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

function arg(string $name, array $argv): ?string {
  foreach ($argv as $a) {
    if (str_starts_with($a, $name . '=')) {
      return substr($a, strlen($name) + 1);
    }
  }
  return null;
}

$pdfPath = $argv[1] ?? null;
$schemaPath = arg('--schema', $argv);

if (!$pdfPath || !is_file($pdfPath)) {
  fwrite(STDERR, "PDF fehlt\n");
  exit(2);
}
if (!$schemaPath || !is_file($schemaPath)) {
  fwrite(STDERR, "Schema fehlt\n");
  exit(2);
}

$apiKey = getenv('MISTRAL_API_KEY');
if (!$apiKey) {
  fwrite(STDERR, "MISTRAL_API_KEY fehlt\n");
  exit(2);
}

$schemaWrapper = json_decode(file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
$schemaDef = $schemaWrapper['json_schema']['schema'];
$schemaName = $schemaWrapper['json_schema']['name'] ?? 'protokoll_extraktion';

$base64 = base64_encode(file_get_contents($pdfPath));
$dataUrl = 'data:application/pdf;base64,' . $base64;

$payload = [
  'document' => [
    'type' => 'document_url',
    'document_url' => $dataUrl,
  ],
  'model' => 'mistral-ocr-latest',
  'include_image_base64' => false,
  'document_annotation_format' => [
    'type' => 'json_schema',
    'json_schema' => [
      'name' => $schemaName,
      'schema' => $schemaDef,
    ],
  ],
  'document_annotation_prompt' =>
    "Extrahiere nur Inhalte aus dem oeffentlichen Teil.\n" .
    "Ab 'Nicht oeffentlicher Teil' nichts mehr extrahieren, damit endet oft die Agenda.\n" .
    "Entschuldigt fehlend = Rolle 'Fehlt: entschuldigt'.\n" .
    "Unentschuldigt fehlend = Rolle 'Fehlt: unentschuldigt'.\n" .
    "Wenn keine Drucksache vorhanden ist, nutze einen leeren String.\n" .
	"Achte unbedingt auch Unterpunkte wie 19.X mitzunehmen und als Agendapunkte aufzulisten.\n" .
    "Gib ausschliesslich ein JSON Objekt gemaess Schema aus.",
];

$ch = curl_init('https://api.mistral.ai/v1/ocr');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 300,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
  fwrite(STDERR, "cURL error: $err\n");
  exit(3);
}

if ($httpCode < 200 || $httpCode >= 300) {
  fwrite(STDERR, "HTTP $httpCode\n$response\n");
  exit(4);
}

$data = json_decode($response, true);
if (!is_array($data)) {
  fwrite(STDERR, "Ungueltiges JSON\n");
  exit(5);
}

/* Annotation finden */
$annotation =
  $data['document_annotation']
  ?? ($data['document_annotations'][0] ?? null)
  ?? ($data['pages'][0]['document_annotation'] ?? null)
  ?? null;

if (!$annotation) {
  fwrite(STDERR, "Keine document annotation\n");
  exit(6);
}

$outPath = preg_replace('/\.pdf$/i', '.mistral.json', $pdfPath);
file_put_contents(
  $outPath,
  json_encode($annotation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo $outPath . "\n";
exit(0);
