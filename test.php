<?php
require_once __DIR__ . '/../includes/gemini_api.php';

$payload = [
  "contents" => [
    ["role" => "user", "parts" => [["text" => "Diga oi em PT-BR"]]]
  ],
  "generationConfig" => ["temperature" => 0.2, "maxOutputTokens" => 60]
];

$r = callGeminiAPI($payload, false);
echo "HTTP: " . $r['httpCode'] . PHP_EOL;
echo $r['is_json'] ? ($r['response']['candidates'][0]['content']['parts'][0]['text'] ?? json_encode($r['response'])) : $r['response'];
echo PHP_EOL;
