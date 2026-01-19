<?php
/**
 * Arquivo: includes/gemini_api.php
 * Versão: v1.2.1 (2025-10-07)
 * Descrição: Client Gemini API com grounding opcional, validações e fallbacks.
 *
 * Requisitos em config.php:
 *   define('GOOGLE_API_KEY', ''); // use env var preferencialmente
 *   define('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash-lite:generateContent');
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php';

/** Normaliza versão do endpoint (v1 ou v1beta) e previne "models/models". */
function normalizeEndpointVersion(string $endpoint, bool $useBeta): string {
    $endpoint = preg_replace('#/v1beta/#', '/v1/', $endpoint, 1);
    if ($useBeta) $endpoint = preg_replace('#/v1/#', '/v1beta/', $endpoint, 1);
    $endpoint = preg_replace('#/models/models/#', '/models/', $endpoint);
    return $endpoint;
}

/** Garante que contents[0].parts[0].text exista e não seja vazio. */
function ensureContentText(array &$promptData, string $fallbackText = 'Responda cordialmente ao cliente.'): void {
    if (empty($promptData['contents']) || !is_array($promptData['contents'])) {
        $promptData['contents'] = [['role' => 'user', 'parts' => []]];
    }
    if (!isset($promptData['contents'][0]['parts']) || !is_array($promptData['contents'][0]['parts'])) {
        $promptData['contents'][0]['parts'] = [];
    }
    $text = $promptData['contents'][0]['parts'][0]['text'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        $promptData['contents'][0]['parts'][0]['text'] = $fallbackText;
        logMessage("[callGeminiAPI] Aviso: parts[0].text vazio/ausente. Texto padrão inserido.");
    }
}

/** Remove configs incompatíveis com grounding (JSON mode/response schema). */
function stripIncompatibleJsonModeWhenGrounding(array &$promptData): void {
    if (isset($promptData['generationConfig'])) {
        unset($promptData['generationConfig']['responseMimeType']);
        unset($promptData['generationConfig']['responseSchema']);
        unset($promptData['generationConfig']['response_mime_type']);
        unset($promptData['generationConfig']['response_schema']);
    }
}

/** Preview curto para logs. */
function previewText(?string $t, int $len = 120): string {
    if (!is_string($t) || $t === '') return '[Vazio]';
    $t = trim($t);
    return mb_substr($t, 0, $len) . (mb_strlen($t) > $len ? '...' : '');
}

/**
 * Envia um prompt para a Gemini API.
 * Alterna /v1beta quando grounding = true (tools.google_search).
 *
 * @param array<string,mixed> $promptData
 * @param bool $enableGrounding
 * @return array{httpCode:int,is_json:bool,response:mixed,error:?string}
 */
function callGeminiAPI(array $promptData, bool $enableGrounding = false): array
{
    if (!defined('GOOGLE_API_KEY') || empty(GOOGLE_API_KEY)) {
        logMessage("[callGeminiAPI] ERRO: GOOGLE_API_KEY não definida.");
        return ['httpCode' => 0, 'is_json' => false, 'response' => null, 'error' => 'Chave API ausente'];
    }
    if (!defined('GEMINI_API_ENDPOINT') || empty(GEMINI_API_ENDPOINT)) {
        logMessage("[callGeminiAPI] ERRO: GEMINI_API_ENDPOINT não definida.");
        return ['httpCode' => 0, 'is_json' => false, 'response' => null, 'error' => 'Endpoint ausente'];
    }

    $endpoint = normalizeEndpointVersion(GEMINI_API_ENDPOINT, $enableGrounding);

    if ($enableGrounding) {
        $promptData['tools'] = [[ 'google_search' => new \stdClass() ]]; // 2.x
        stripIncompatibleJsonModeWhenGrounding($promptData);
        logMessage("[callGeminiAPI] Grounding ON (+ google_search). Endpoint: $endpoint");
    } else {
        unset($promptData['tools']); // não enviar tools no /v1
        logMessage("[callGeminiAPI] Grounding OFF. Endpoint: $endpoint");
    }

    ensureContentText($promptData);

    $url     = $endpoint . '?key=' . urlencode(GOOGLE_API_KEY);
    $headers = ['Content-Type: application/json'];

    $promptPreview = $promptData['contents'][0]['parts'][0]['text'] ?? '';
    logMessage("[callGeminiAPI] Enviando prompt. Preview: '" . previewText($promptPreview) . "'");

    $res = makeCurlRequest($url, 'POST', $headers, $promptData, true);

    // Fallback 1: /v1 recebeu tools → tente /v1beta
    if (($res['httpCode'] ?? 0) === 400) {
        $raw = is_array($res['response']) ? json_encode($res['response']) : (string)($res['response'] ?? '');
        if (stripos($raw, 'Unknown name "tools"') !== false) {
            logMessage("[callGeminiAPI] 400 Unknown name \"tools\" → retry em /v1beta.");
            $endpointRetry = normalizeEndpointVersion(GEMINI_API_ENDPOINT, true);
            $res = makeCurlRequest($endpointRetry . '?key=' . urlencode(GOOGLE_API_KEY), 'POST', $headers, $promptData, true);
        }
    }
    // Fallback 2: Grounding não suportado → tente sem tools em /v1
    if (($res['httpCode'] ?? 0) === 400) {
        $raw = is_array($res['response']) ? json_encode($res['response']) : (string)($res['response'] ?? '');
        if (stripos($raw, 'Search Grounding is not supported') !== false) {
            logMessage("[callGeminiAPI] 400 Grounding não suportado → retry sem tools em /v1.");
            $retryData = $promptData; unset($retryData['tools']);
            $endpointRetry = normalizeEndpointVersion(GEMINI_API_ENDPOINT, false);
            $res = makeCurlRequest($endpointRetry . '?key=' . urlencode(GOOGLE_API_KEY), 'POST', $headers, $retryData, true);
        }
    }

    $preview = '[Resposta Omitida]';
    if ($res['is_json'] && isset($res['response']['candidates'][0]['content']['parts'])) {
        $parts = $res['response']['candidates'][0]['content']['parts'];
        $txt = '';
        if (is_array($parts)) foreach ($parts as $p) if (isset($p['text'])) $txt .= $p['text']."\n";
        elseif (isset($parts['text'])) $txt = $parts['text'];
        $preview = previewText($txt);
    } elseif (!$res['is_json'] && is_string($res['response'])) {
        $preview = '[Texto] ' . previewText($res['response']);
    } elseif (!empty($res['error'])) {
        $preview = "[Erro] {$res['error']}";
    }
    logMessage("[callGeminiAPI] Resultado: HTTP {$res['httpCode']}. Preview: '$preview'.");

    return $res;
}

/**
 * interpretUserIntent — classifica a resposta do WhatsApp (TRIGGER_AI / MANUAL_ANSWER / INVALID_FORMAT).
 * Mantida aqui por praticidade (usa callGeminiAPI).
 */
function interpretUserIntent(string $userReplyText, string $originalQuestionText): array
{
    $functionVersion = "v30.7";
    logMessage("[interpretUserIntent $functionVersion] Início. User: '" . mb_substr($userReplyText,0,50) . "...' | Pergunta: '" . mb_substr($originalQuestionText,0,30) . "...'");
    $default = ['intent' => 'INVALID_FORMAT', 'cleaned_text' => null];

    if (trim($userReplyText) === '' || trim($originalQuestionText) === '') {
        logMessage("[interpretUserIntent $functionVersion] Entrada vazia → INVALID_FORMAT.");
        return $default;
    }

    $timeoutMinutes = defined('AI_FALLBACK_TIMEOUT_MINUTES') ? AI_FALLBACK_TIMEOUT_MINUTES : 10;

    $prompt = "Você é um assistente especialista em analisar respostas de usuários do WhatsApp, enviadas em réplica a uma notificação sobre uma pergunta do Mercado Livre. "
            . "Interprete a intenção e extraia o texto limpo, se aplicável. "
            . "1) Pergunta ML:\n```\n{$originalQuestionText}\n```\n"
            . "2) Notificação (resumo):\n```\n"
            . "Nova pergunta: ```{$originalQuestionText}```\n"
            . "1) Responda com seu texto\n2) Responda com '2' para usar IA\n"
            . "Se não responder em {$timeoutMinutes} min, a IA responde.\n```\n"
            . "3) Resposta do usuário:\n```\n{$userReplyText}\n```\n"
            . "Classifique nessa ordem: TRIGGER_AI / INVALID_FORMAT / MANUAL_ANSWER. "
            . "Saída APENAS JSON: {\"intent\":\"...\",\"cleaned_text\":TEXTO_OU_NULL}.";

    $payload = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.25,
            'maxOutputTokens' => 200
        ]
    ];

    $api = callGeminiAPI($payload, false);

    if ($api['httpCode'] === 200 && $api['is_json'] && isset($api['response']['candidates'][0]['content']['parts'][0]['text'])) {
        $resp = $api['response']['candidates'][0]['content']['parts'][0]['text'];
        $clean = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/s', '', $resp);
        $json = json_decode($clean, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($json['intent']) && is_string($json['intent'])) {
            $valid = ['MANUAL_ANSWER','TRIGGER_AI','INVALID_FORMAT'];
            if (in_array($json['intent'], $valid, true)) {
                $ct = isset($json['cleaned_text']) && is_string($json['cleaned_text']) ? trim($json['cleaned_text']) : null;
                if ($json['intent'] === 'MANUAL_ANSWER' && ($ct === null || $ct === '')) return $default;
                return ['intent' => $json['intent'], 'cleaned_text' => $ct];
            }
        }
    }

    return $default;
}
