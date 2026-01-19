<?php
/**
 * Arquivo: includes/evolution_api.php
 * Versão: v1.0
 * Descrição: Funções para interagir com a Evolution API V2 (WhatsApp).
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php';
// Constantes EVOLUTION_* devem estar definidas em config.php

/**
 * Envia uma notificação de texto simples via API Evolution V2.
 * (Payload v32.7 com texto na raiz)
 * @param string $targetJid O JID do destinatário.
 * @param string $messageText O texto da mensagem a ser enviada.
 * @return string|null O ID da mensagem enviada em caso de sucesso, ou null.
 */
function sendWhatsAppNotification(string $targetJid, string $messageText): ?string
{
    $functionVersion = "v32.7";
    if (!defined('EVOLUTION_API_URL') || !defined('EVOLUTION_INSTANCE_NAME') || !defined('EVOLUTION_API_KEY') || empty(EVOLUTION_API_KEY)) { logMessage("[sendWhatsAppNotification $functionVersion] ERRO FATAL: Configurações da Evolution API incompletas."); return null; }
    if (empty($targetJid) || empty(trim($messageText))) { logMessage("[sendWhatsAppNotification $functionVersion] ERRO: JID ('$targetJid') ou texto da mensagem vazio."); return null; }
    $url = rtrim(EVOLUTION_API_URL, '/') . '/message/sendText/' . EVOLUTION_INSTANCE_NAME; $headers = ['Content-Type: application/json', 'apikey: ' . EVOLUTION_API_KEY];
    $postData = [ 'number' => $targetJid, 'options' => [ 'delay' => 1200, 'presence' => 'composing' ], 'text' => $messageText ];
    $logPreview = mb_substr($messageText, 0, 70) . (mb_strlen($messageText) > 70 ? '...' : ''); logMessage("[sendWhatsAppNotification $functionVersion] Enviando texto para JID: $targetJid. Preview: '$logPreview'. Payload: " . json_encode($postData));
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);
    $messageId = null; if ($result['is_json'] && isset($result['response'])) { $messageId = $result['response']['key']['id'] ?? $result['response']['messageSend']['key']['id'] ?? $result['response']['id'] ?? null; }
    if (($result['httpCode'] == 200 || $result['httpCode'] == 201) && $messageId) { logMessage("[sendWhatsAppNotification $functionVersion] SUCESSO envio para JID: $targetJid. Message ID: $messageId"); return $messageId; }
    else { $apiErrorMsg = $result['is_json'] ? json_encode($result['response']) : mb_substr($result['response'] ?? '', 0, 200); logMessage("[sendWhatsAppNotification $functionVersion] ERRO envio para JID: $targetJid. HTTP: {$result['httpCode']}. cURL Error: ".($result['error'] ?? 'N/A').". API Response: $apiErrorMsg."); if ($messageId) { logMessage("[sendWhatsAppNotification $functionVersion] (AVISO: ID msg '$messageId' extraído apesar do erro HTTP {$result['httpCode']}.)"); } return null; }
}

/**
 * Envia uma notificação com IMAGEM (via URL) e legenda via API Evolution V2.
 * (Payload v32.5 com caption na raiz)
 * @param string $targetJid O JID do destinatário.
 * @param string $imageUrl A URL pública da imagem.
 * @param string $captionText O texto da legenda.
 * @return string|null O ID da mensagem enviada em caso de sucesso, ou null.
 */
function sendWhatsAppImageNotification(string $targetJid, string $imageUrl, string $captionText): ?string
{
    $functionVersion = "v32.5";
    if (!defined('EVOLUTION_API_URL') || !defined('EVOLUTION_INSTANCE_NAME') || !defined('EVOLUTION_API_KEY') || empty(EVOLUTION_API_KEY)) { logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO FATAL: Configurações da Evolution API incompletas."); return null; }
    if (empty($targetJid) || empty(trim($captionText)) || empty(trim($imageUrl))) { logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO: JID, URL Imagem ou legenda vazios."); return null; }
    if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) { logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO: URL imagem inválida: '$imageUrl'"); return null; }
    $url = rtrim(EVOLUTION_API_URL, '/') . '/message/sendMedia/' . EVOLUTION_INSTANCE_NAME; $headers = ['Content-Type: application/json', 'apikey: ' . EVOLUTION_API_KEY];
    $imagePathInfo = pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: ''); $imageExtension = strtolower($imagePathInfo['extension'] ?? 'jpg'); $fileName = "imagem_anuncio." . $imageExtension;
    logMessage("[sendWhatsAppImageNotification $functionVersion] Nome arquivo: '$fileName' para URL: $imageUrl");
    $postData = [ 'number' => $targetJid, 'options' => [ 'delay' => 1500, 'presence' => 'upload_photo' ], 'mediatype' => 'image', 'media' => $imageUrl, 'caption' => $captionText, 'fileName' => $fileName ];
    $logCaptionPreview = mb_substr($captionText, 0, 70) . (mb_strlen($captionText) > 70 ? '...' : ''); logMessage("[sendWhatsAppImageNotification $functionVersion] Enviando Imagem+Legenda JID: $targetJid. Caption Preview: '$logCaptionPreview'. Payload: " . json_encode($postData));
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);
    $messageId = null; if ($result['is_json'] && isset($result['response'])) { $messageId = $result['response']['key']['id'] ?? $result['response']['messageSend']['key']['id'] ?? $result['response']['id'] ?? null; }
    if (($result['httpCode'] == 200 || $result['httpCode'] == 201) && $messageId) { logMessage("[sendWhatsAppImageNotification $functionVersion] SUCESSO envio Img+Legenda JID: $targetJid. Message ID: $messageId"); return $messageId; }
    else { $apiErrorMsg = $result['is_json'] ? json_encode($result['response']) : mb_substr($result['response'] ?? '', 0, 200); logMessage("[sendWhatsAppImageNotification $functionVersion] ERRO envio Img+Legenda JID: $targetJid. HTTP: {$result['httpCode']}. cURL Error: ".($result['error'] ?? 'N/A').". API Response: $apiErrorMsg."); if ($messageId) { logMessage("[sendWhatsAppImageNotification $functionVersion] (AVISO: ID msg '$messageId' extraído apesar do erro HTTP {$result['httpCode']}.)"); } return null; }
}
?>