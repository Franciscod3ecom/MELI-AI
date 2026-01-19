<?php
/**
 * Arquivo: ml_webhook_receiver.php
 * Versão: v1.3 - Adiciona nome da loja (nickname) na notificação do WhatsApp.
 * Descrição: Endpoint para receber notificações POST do ML sobre novas perguntas.
 */

// --- Includes Essenciais ---
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';
require_once __DIR__ . '/includes/db_interaction.php';
require_once __DIR__ . '/includes/ml_api.php';
require_once __DIR__ . '/includes/evolution_api.php';

if (!function_exists('logMessage')) {
    function logMessage(string $message): void { error_log($message); }
}

logMessage("==== [ML Webhook Receiver v1.3] Notificação Recebida ====");

// --- Validação Inicial ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("[ML Webhook Receiver] ERRO: Método HTTP inválido.");
    http_response_code(405); exit;
}

// --- Processamento do Payload ---
$payload = file_get_contents('php://input');
$notificationData = $payload ? json_decode($payload, true) : null;
if (!$notificationData || json_last_error() !== JSON_ERROR_NONE) {
    logMessage("[ML Webhook Receiver] ERRO: Payload JSON inválido.");
    http_response_code(400); exit;
}

// --- Extração e Validação dos Dados ---
$topic = $notificationData['topic'] ?? null;
$resource = $notificationData['resource'] ?? null;
$userIdML = $notificationData['user_id'] ?? null;
$attempts = $notificationData['attempts'] ?? 1;
logMessage("[ML Webhook Receiver] Notificação: Topic='{$topic}', Resource='{$resource}', UserID_ML='{$userIdML}', Attempts='{$attempts}'");

if ($topic !== 'questions' || !$resource || !$userIdML) {
    logMessage("[ML Webhook Receiver] Ignorada (tópico não é 'questions' ou dados ausentes).");
    http_response_code(200); exit;
}

if (!preg_match('/\/questions\/(\d+)/', $resource, $matches)) {
    logMessage("[ML Webhook Receiver] ERRO: ID da pergunta não extraído do resource: '$resource'");
    http_response_code(400); exit;
}
$questionId = (int)$matches[1];
$mlUserId = (int)$userIdML;
logMessage("[ML Webhook Receiver] Pergunta ID: $questionId para Vendedor ML ID: $mlUserId");

// --- Lógica Principal ---
try {
    $pdo = getDbConnection();
    $globalNow = new DateTimeImmutable();

    $logEntry = getQuestionLogStatus($questionId);
    if ($logEntry) {
        logMessage("  [QID $questionId] Já existe no log (Status: {$logEntry['status']}). Ignorando notificação webhook.");
        http_response_code(200); exit;
    }

    logMessage("  [QID $questionId] Buscando conexão ML/SaaS ativa para ML User ID: $mlUserId...");
    $stmtMLUser = $pdo->prepare(
        "SELECT mlu.id AS connection_id, mlu.saas_user_id, mlu.access_token, mlu.refresh_token, mlu.token_expires_at,
                su.whatsapp_jid, su.subscription_status
         FROM mercadolibre_users mlu
         JOIN saas_users su ON mlu.saas_user_id = su.id
         WHERE mlu.ml_user_id = :ml_uid AND mlu.is_active = TRUE AND su.is_saas_active = TRUE
         LIMIT 1"
    );
    $stmtMLUser->execute([':ml_uid' => $mlUserId]);
    $mlUserConn = $stmtMLUser->fetch();

    if (!$mlUserConn) {
        logMessage("  [QID $questionId] ERRO: Conexão ML ativa ou usuário SaaS ativo não encontrado para ML User ID: $mlUserId.");
        http_response_code(200); exit;
    }

    $connectionIdInDb = $mlUserConn['connection_id'];
    $saasUserId = (int)$mlUserConn['saas_user_id'];
    $whatsappTargetJid = $mlUserConn['whatsapp_jid'];
    $dbAccessTokenEncrypted = $mlUserConn['access_token'];
    $dbRefreshTokenEncrypted = $mlUserConn['refresh_token'];
    $tokenExpiresAtStr = $mlUserConn['token_expires_at'];
    $subscriptionStatus = $mlUserConn['subscription_status'];
    $currentAccessToken = null;

    logMessage("  [QID $questionId] Conexão encontrada: DB ID=$connectionIdInDb, SaaS ID=$saasUserId, JID=$whatsappTargetJid, Sub Status=$subscriptionStatus");

    if ($subscriptionStatus !== 'ACTIVE') {
        logMessage("  [QID $questionId] Processamento IGNORADO: Assinatura do usuário SaaS ID $saasUserId não está ATIVA (Status: $subscriptionStatus).");
        http_response_code(200);
        exit;
    }

    logMessage("    [ML $mlUserId QID $questionId] Validando/Refrescando token ML...");
    try {
        if (empty($dbAccessTokenEncrypted) || empty($dbRefreshTokenEncrypted)) { throw new Exception("Tokens criptografados vazios no DB."); }
        $currentAccessToken = decryptData($dbAccessTokenEncrypted);
        $refreshTokenDecrypted = decryptData($dbRefreshTokenEncrypted);
        if (empty($tokenExpiresAtStr)) { throw new Exception("Data de expiração do token vazia no DB."); }
        $tokenExpiresAt = new DateTimeImmutable($tokenExpiresAtStr);

        if ($globalNow >= $tokenExpiresAt->modify("-10 minutes")) {
            logMessage("    [ML $mlUserId QID $questionId] Token precisa ser renovado...");
            $refreshResult = refreshMercadoLibreToken($refreshTokenDecrypted);

            if ($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])) {
                $newData = $refreshResult['response'];
                $currentAccessToken = $newData['access_token'];
                $newRefreshToken = $newData['refresh_token'] ?? $refreshTokenDecrypted;
                $newExpiresIn = $newData['expires_in'] ?? 21600;
                $newExpAt = $globalNow->modify("+" . (int)$newExpiresIn . " seconds")->format('Y-m-d H:i:s');

                $encAT = encryptData($currentAccessToken);
                $encRT = encryptData($newRefreshToken);

                $upSql = "UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id";
                $upStmt = $pdo->prepare($upSql);
                $upStmt->execute([':at' => $encAT, ':rt' => $encRT, ':exp' => $newExpAt, ':id' => $connectionIdInDb]);
                logMessage("    [ML $mlUserId QID $questionId] Refresh do token ML OK e salvo no DB.");
            } else {
                $errorResponse = json_encode($refreshResult['response'] ?? $refreshResult['error'] ?? 'N/A');
                logMessage("    [ML $mlUserId QID $questionId] ERRO FATAL ao renovar token ML. HTTP: {$refreshResult['httpCode']}. Desativando conexão. Resp: " . $errorResponse);
                @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb);
                upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Falha refresh token API ML (Webhook)', $saasUserId);
                http_response_code(200);
                exit;
            }
        } else {
            logMessage("    [ML $mlUserId QID $questionId] Token ML ainda válido.");
        }
    } catch (Exception $e) {
        logMessage("    [ML $mlUserId QID $questionId] ERRO CRÍTICO ao lidar com token ML: ".$e->getMessage());
        @$pdo->exec("UPDATE mercadolibre_users SET is_active = FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb);
        upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Erro decrypt/process token ML (Webhook): '.substr($e->getMessage(),0,100), $saasUserId);
        http_response_code(200);
        exit;
    }
    if (empty($currentAccessToken)) {
         logMessage("    [ML $mlUserId QID $questionId] ERRO FATAL INESPERADO: Access token vazio após lógica.");
         upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Token ML vazio inesperado (Webhook)', $saasUserId);
         http_response_code(200); exit;
    }
    logMessage("    [ML $mlUserId QID $questionId] Token ML pronto.");

    logMessage("  [QID $questionId] Buscando detalhes da pergunta no ML...");
    $mlQuestionData = getMercadoLibreQuestionStatus($questionId, $currentAccessToken);
    if ($mlQuestionData['httpCode'] != 200 || !$mlQuestionData['is_json'] || !isset($mlQuestionData['response']['status'])) {
        $apiError = json_encode($mlQuestionData['response'] ?? $mlQuestionData['error'] ?? 'N/A');
        logMessage("  [QID $questionId] ERRO: Falha ao buscar detalhes/status da pergunta no ML. HTTP: {$mlQuestionData['httpCode']}. Detalhe: $apiError");
        upsertQuestionLog($questionId, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Falha API ML get status (Webhook)', $saasUserId);
        http_response_code(200); exit;
    }
    $questionDetails = $mlQuestionData['response'];
    $currentMLStatus = $questionDetails['status'];
    $itemId = $questionDetails['item_id'] ?? 'N/A';
    $questionTextRaw = $questionDetails['text'] ?? '';
    logMessage("  [QID $questionId] Detalhes obtidos. Status ML: '$currentMLStatus'. Item ID: '$itemId'.");

    if ($currentMLStatus !== 'UNANSWERED') {
        logMessage("  [QID $questionId] Status no ML não é 'UNANSWERED' (é '$currentMLStatus'). Ignorando.");
        http_response_code(200); exit;
    }
    if (empty(trim($questionTextRaw)) || empty($itemId) || $itemId === 'N/A') {
        logMessage("  [QID $questionId] ERRO: Texto da pergunta ou Item ID ausentes na resposta da API ML.");
        upsertQuestionLog($questionId, $mlUserId, $itemId ?: 'N/A', 'ERROR', $questionTextRaw, null, null, 'Dados inválidos API ML (Webhook)', $saasUserId);
        http_response_code(200); exit;
    }

    if (empty($whatsappTargetJid)) {
        logMessage("  [QID $questionId] Usuário SaaS ID $saasUserId não possui JID configurado. Marcando pergunta como PENDING_WHATSAPP.");
        upsertQuestionLog($questionId, $mlUserId, $itemId, 'PENDING_WHATSAPP', $questionTextRaw, null, null, 'JID usuário não configurado (Webhook)', $saasUserId);
        http_response_code(200); exit;
    }

    logMessage("  [QID $questionId] Buscando detalhes do item $itemId para imagem...");
    $itemTitle = '[Produto não encontrado]'; $itemImageUrl = null;
    $itemResult = getMercadoLibreItemDetails($itemId, $currentAccessToken);
    if ($itemResult['httpCode'] == 200 && $itemResult['is_json']) {
        $itemData = $itemResult['response'];
        $itemTitle = $itemData['title'] ?? $itemTitle;
        $itemImageUrl = $itemData['pictures'][0]['secure_url'] ?? $itemData['thumbnail'] ?? null;
        logMessage("  [QID $questionId] Detalhes do item obtidos. Título: '$itemTitle'. URL Imagem: " . ($itemImageUrl ? 'OK' : 'NÃO ENCONTRADA'));
    } else {
        logMessage("  [QID $questionId] AVISO: Falha ao buscar detalhes do item $itemId. HTTP: {$itemResult['httpCode']}. Tentará enviar notificação sem imagem.");
    }

    // BUSCAR NOME DE USUÁRIO ML
    $mlUserNickname = '[Loja não identificada]';
    $mlUserDetails = getMercadoLivreUserDetails($mlUserId, $currentAccessToken);
    if ($mlUserDetails && isset($mlUserDetails['nickname'])) {
        $mlUserNickname = $mlUserDetails['nickname'];
    }
    logMessage("  [QID $questionId] Nome da loja ML obtido: $mlUserNickname");

    // Montar e Enviar Notificação WhatsApp (MODIFICADA)
    $timeoutMinutes = defined('AI_FALLBACK_TIMEOUT_MINUTES') ? AI_FALLBACK_TIMEOUT_MINUTES : 10;
    
    $captionText = "🔔 *Nova pergunta para [$mlUserNickname]*\n\n";
    $captionText .= "Anúncio: ```" . htmlspecialchars($itemTitle) . "```\n";
    $captionText .= "Pergunta: ```" . htmlspecialchars(trim($questionTextRaw)) . "```\n\n";
    $captionText .= "1️⃣ *Responder Manualmente:*\n   _(Responda esta mensagem com o texto)_.\n";
    $captionText .= "2️⃣ *Usar Resposta da IA:*\n   _(Responda esta mensagem apenas com o número `2`)_.\n\n";
    $captionText .= "⏳ A IA responderá automaticamente em *{$timeoutMinutes} minutos* se não houver ação.\n\n";
    $captionText .= "_(Ref: Q#{$questionId} | Item: {$itemId})_";

    $whatsappMessageId = null;
    if ($itemImageUrl && filter_var($itemImageUrl, FILTER_VALIDATE_URL)) {
        logMessage("  [QID $questionId] Enviando notificação COM IMAGEM para $whatsappTargetJid...");
        $whatsappMessageId = sendWhatsAppImageNotification($whatsappTargetJid, $itemImageUrl, $captionText);
    } else {
        logMessage("  [QID $questionId] Enviando notificação SEM IMAGEM para $whatsappTargetJid...");
        $whatsappMessageId = sendWhatsAppNotification($whatsappTargetJid, $captionText);
    }

    $initialStatus = $whatsappMessageId ? 'AWAITING_TEXT_REPLY' : 'PENDING_WHATSAPP';
    $sentTimestamp = $whatsappMessageId ? $globalNow->format('Y-m-d H:i:s') : null;
    $errorMsg = ($initialStatus === 'PENDING_WHATSAPP') ? 'Falha envio WhatsApp via webhook (ver logs evolution_api)' : null;

    logMessage("  [QID $questionId] Resultado envio WhatsApp: " . ($whatsappMessageId ? "Sucesso (MsgID: $whatsappMessageId)" : "Falha"));

    $upsertOK = upsertQuestionLog(
        $questionId, $mlUserId, $itemId, $initialStatus, $questionTextRaw,
        $sentTimestamp, null, $errorMsg, $saasUserId, null, $whatsappMessageId
    );

    if ($upsertOK) {
        logMessage("  [QID $questionId] UPSERT no log do banco de dados OK (Status: $initialStatus).");
    } else {
        logMessage("  [QID $questionId] ERRO ao executar UPSERT no log do banco de dados (Status: $initialStatus)!");
    }

    http_response_code(200);
    logMessage("==== [ML Webhook Receiver v1.3] Processamento concluído para QID $questionId ====");
    exit;

} catch (\Throwable $e) {
    $errorFile = basename($e->getFile()); $errorLine = $e->getLine();
    logMessage("[ML Webhook Receiver QID ".($questionId ?? 'N/A')."] **** ERRO FATAL INESPERADO ($errorFile Linha $errorLine) ****");
    logMessage("  Mensagem: {$e->getMessage()}");
    if (isset($questionId) && $questionId > 0 && isset($mlUserId) && $mlUserId > 0) {
        $errorMsgForDb = "Exceção fatal webhook ($errorFile:$errorLine): ".substr($e->getMessage(),0,150);
        @upsertQuestionLog($questionId, $mlUserId, ($itemId ?? 'N/A'), 'ERROR', ($questionTextRaw ?? null), null, null, $errorMsgForDb, ($saasUserId ?? null));
    }
    http_response_code(500);
    exit;
}