<?php
/**
 * Arquivo: includes/curl_helper.php
 * Versão: v1.3 - URL primeiro, compatível com o projeto + garante User-Agent (requisito Asaas).
 */

function makeCurlRequest(
    string $url,
    string $method = 'GET',
    array $headers = [],
    $body = null,
    bool $isJsonRequest = true
): array {
    // Garante User-Agent (Asaas exige para contas mais novas)
    $hasUserAgent = false;
    foreach ($headers as $h) {
        if (stripos($h, 'User-Agent:') === 0) {
            $hasUserAgent = true;
            break;
        }
    }

    if (!$hasUserAgent) {
        $headers[] = 'User-Agent: ' . (defined('ASAAS_USER_AGENT') ? ASAAS_USER_AGENT : 'MeliAI/1.0 (PHP; producao)');
    }

    // PONTO CRÍTICO: SEMPRE usar a URL real aqui
    $ch = curl_init($url);

    $opts = [
        CURLOPT_CUSTOMREQUEST   => strtoupper($method),
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_HTTPHEADER      => $headers,
    ];

    // Corpo da requisição
    if ($body !== null) {
        if ($isJsonRequest) {
            $payload = is_string($body)
                ? $body
                : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $opts[CURLOPT_POSTFIELDS] = $payload;
        } else {
            if (is_array($body)) {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
            } else {
                $opts[CURLOPT_POSTFIELDS] = (string) $body;
            }
        }
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $is_json = false;
    $decoded = null;

    if (is_string($response)) {
        $decoded = json_decode($response, true);
        $is_json = (json_last_error() === JSON_ERROR_NONE);
    }

    return [
        'httpCode' => $httpCode,
        'is_json'  => $is_json,
        'response' => $is_json ? $decoded : $response,
        'error'    => $error,
    ];
}
