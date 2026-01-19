<?php
/**
 * Arquivo: includes/core_logic.php
 * Versão: v1.3 - Orquestra a nova arquitetura de dois agentes.
 * Descrição: Lógica principal de processamento de IA para responder perguntas.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/db_interaction.php';
require_once __DIR__ . '/ml_api.php';
require_once __DIR__ . '/evolution_api.php';
require_once __DIR__ . '/agent1.php'; // Inclui nossos novos agentes

/**
 * **Processamento de Resposta Automática via IA (v34.0 - Arquitetura 2 Agentes)**
 * Orquestra o processo completo para responder a uma pergunta do ML.
 */
function triggerAiForQuestion(int $questionId): bool
{
    $functionVersion = "v34.0";
    logMessage("      [AI_TRIGGER QID $questionId] --- INÍCIO IA ($functionVersion) ---");
    $pdo = null; $mlUserId = 0; $itemId = 'N/A'; $qSaasUserId = null; $whatsappTargetJid = null; $currentAccessToken = null; $questionText = null; $logEntry = null; $itemTitle = '[Item não carregado]';

    try {
        // 1. Conexão DB e Busca Log
        logMessage("      [AI_TRIGGER QID $questionId] Obtendo DB e buscando log...");
        $pdo = getDbConnection();
        $now = new DateTimeImmutable();
        $logEntry = getQuestionLogStatus($questionId);
        if (!$logEntry) { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Log não encontrado."); return false; }

        $mlUserId = (int)($logEntry['ml_user_id'] ?? 0);
        $itemId = $logEntry['item_id'] ?? 'N/A';
        $questionText = $logEntry['question_text'] ?? null;
        $qSaasUserId = (int)($logEntry['saas_user_id'] ?? 0);

        if ($mlUserId <= 0 || empty($itemId) || $itemId === 'N/A' || empty(trim((string)$questionText))) {
            logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Dados inválidos no log (ML UID, Item ID ou Texto da Pergunta).");
            @upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, 'Dados inválidos no log para IA', $qSaasUserId);
            return false;
        }
        logMessage("      [AI_TRIGGER QID $questionId] Log OK. Status DB: '{$logEntry['status']}'. ML UID: $mlUserId.");

        // 2. Busca JID e Credenciais ML
        if ($qSaasUserId > 0) {
            $stmtSaas = $pdo->prepare("SELECT whatsapp_jid FROM saas_users WHERE id = :id AND is_saas_active = TRUE LIMIT 1");
            $stmtSaas->execute([':id' => $qSaasUserId]);
            $saasUser = $stmtSaas->fetch();
            $whatsappTargetJid = ($saasUser && !empty($saasUser['whatsapp_jid'])) ? $saasUser['whatsapp_jid'] : null;
        }

        $stmtMLUser = $pdo->prepare("SELECT id, access_token, refresh_token, token_expires_at FROM mercadolibre_users WHERE ml_user_id = :ml_uid AND saas_user_id = :saas_uid AND is_active = TRUE LIMIT 1");
        $stmtMLUser->execute([':ml_uid' => $mlUserId, ':saas_uid' => $qSaasUserId]);
        $mlUserConn = $stmtMLUser->fetch();
        if (!$mlUserConn) { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Conexão ML $mlUserId (SaaS $qSaasUserId) inativa/não encontrada."); upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, 'Conn ML inativa IA', $qSaasUserId); return false; }
        $mlConnectionDbId = $mlUserConn['id'];

        // 3. Refresh Token
        $refreshToken = decryptData($mlUserConn['refresh_token']);
        $tokenExpiresAt = new DateTimeImmutable($mlUserConn['token_expires_at']);
        if ($now >= $tokenExpiresAt->modify("-10 minutes")) {
            logMessage("      [AI_TRIGGER QID $questionId] Refresh de token necessário...");
            $refreshResult = refreshMercadoLibreToken($refreshToken);
            if ($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])) {
                $newData = $refreshResult['response'];
                $currentAccessToken = $newData['access_token'];
                $newRefreshToken = $newData['refresh_token'] ?? $refreshToken;
                $newExpAt = $now->modify("+" . ($newData['expires_in'] ?? 21600) . " seconds")->format('Y-m-d H:i:s');
                $encAT = encryptData($currentAccessToken);
                $encRT = encryptData($newRefreshToken);
                $upStmt = $pdo->prepare("UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id");
                $upStmt->execute([':at' => $encAT, ':rt' => $encRT, ':exp' => $newExpAt, ':id' => $mlConnectionDbId]);
                logMessage("      [AI_TRIGGER QID $questionId] Refresh OK.");
            } else { 
                logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL no refresh do token. Desativando conexão.");
                @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE WHERE id=".$mlConnectionDbId);
                upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, 'Falha refresh token API (IA)', $qSaasUserId);
                return false;
            }
        } else {
            $currentAccessToken = decryptData($mlUserConn['access_token']);
        }
        if (empty($currentAccessToken)) { logMessage("      [AI_TRIGGER QID $questionId] ERRO FATAL: Access token vazio."); return false; }
        logMessage("      [AI_TRIGGER QID $questionId] Token ML pronto.");
        
        // 4. Verifica Status da Pergunta no ML
        $mlQuestionData = getMercadoLibreQuestionStatus($questionId, $currentAccessToken);
        $currentMLStatus = $mlQuestionData['response']['status'] ?? 'UNKNOWN';
        if ($currentMLStatus !== 'UNANSWERED') {
            logMessage("      [AI_TRIGGER QID $questionId] Pergunta ML não 'UNANSWERED' ($currentMLStatus). Saindo.");
            upsertQuestionLog($questionId, $mlUserId, $itemId, 'HUMAN_ANSWERED_ON_ML', $questionText, null, null, null, $qSaasUserId);
            return false;
        }
        logMessage("      [AI_TRIGGER QID $questionId] Status ML confirmado 'UNANSWERED'.");

        // 5. Atualiza Status Log para 'AI_PROCESSING'
        upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_PROCESSING', null, null, null, null, $qSaasUserId, null, $logEntry['whatsapp_notification_message_id'] ?? null);
        logMessage("      [AI_TRIGGER QID $questionId] Status log interno -> 'AI_PROCESSING'.");

        // 6. Busca TODO o Contexto do Item
        logMessage("      [AI_TRIGGER QID $questionId] Buscando contexto completo do item $itemId...");
        $itemResult = getMercadoLibreItemDetails($itemId, $currentAccessToken);
        if ($itemResult['httpCode'] != 200) { 
            logMessage("      [AI_TRIGGER QID $questionId] ERRO ao buscar detalhes do item. Abortando.");
            upsertQuestionLog($questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, "Falha API ML get item details", $qSaasUserId);
            return false;
        }
        $itemData = $itemResult['response'];
        $itemTitle = $itemData['title'] ?? '[Título indisponível]';
        $itemAttributes = $itemData['attributes'] ?? null;
        $itemDescription = getMercadoLivreItemDescription($itemId, $currentAccessToken);
        logMessage("      [AI_TRIGGER QID $questionId] Contexto do item carregado (Título, Atributos, Descrição).");
        
        // 7. CHAMAR O AGENTE 1 (ANALISTA) PARA DECIDIR
        $analysis = agent1_analyze_context($questionText, $itemTitle, $itemDescription, $itemAttributes);
        $finalAnswerText = null;

        // 8. DECIDIR O PRÓXIMO PASSO
        if ($analysis['requires_external_search'] === false && !empty(trim((string)$analysis['answer']))) {
            logMessage("      [AI_TRIGGER QID $questionId] Agente 1 encontrou resposta internamente. Não precisará de busca externa.");
            $finalAnswerText = $analysis['answer'];
        } else {
            logMessage("      [AI_TRIGGER QID $questionId] Agente 1 determinou que a busca externa é necessária. Chamando Agente 2...");
            $agent2Result = agent2_generate_grounded_answer($questionText, $itemId, $itemTitle, $itemDescription, $itemAttributes);

            if (!$agent2Result['ok'] || empty(trim($agent2Result['text']))) {
                $agentErrorReason = "Falha do Agente 2. HTTP: {$agent2Result['http']}. Erro: " . ($agent2Result['error'] ?? json_encode($agent2Result['raw']));
                logMessage("      [AI_TRIGGER QID $questionId] ERRO: Agente 2 (Pesquisador) não gerou resposta válida. " . $agentErrorReason);
                upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_FAILED', $questionText, null, null, $agentErrorReason, $qSaasUserId, $agent2Result['text'], $logEntry['whatsapp_notification_message_id'] ?? null);
                return false;
            }
            $finalAnswerText = $agent2Result['text'];
        }
        
        logMessage("      [AI_TRIGGER QID $questionId] Resposta final determinada: '$finalAnswerText'");
        
        // 9. SANITIZAÇÃO E ENVIO
        logMessage("      [AI_TRIGGER QID $questionId] Sanitizando resposta para filtros do ML...");
        $sanitizedAnswerText = preg_replace_callback('/(\d{7,})/', function($matches) {
            return trim(chunk_split($matches[1], 4, ' '));
        }, $finalAnswerText);
        
        if ($sanitizedAnswerText !== $finalAnswerText) {
            logMessage("      [AI_TRIGGER QID $questionId] Resposta modificada para: '$sanitizedAnswerText'");
        }
        
        logMessage("      [AI_TRIGGER QID $questionId] Tentando postar resposta no ML...");
        $answerResult = postMercadoLibreAnswer($questionId, $sanitizedAnswerText, $currentAccessToken);
        $aiAnsweredTimestamp = $now->format('Y-m-d H:i:s');
        
        // 10. Processa Resultado Final
        if ($answerResult['httpCode'] == 200 || $answerResult['httpCode'] == 201) {
            logMessage("      [AI_TRIGGER QID $questionId] Resposta postada SUCESSO no ML.");
            upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_ANSWERED', null, null, $aiAnsweredTimestamp, null, $qSaasUserId, $sanitizedAnswerText, $logEntry['whatsapp_notification_message_id'] ?? null);

            if ($whatsappTargetJid) {
                try {
                    $waMsg = "🤖 *Resposta Automática Enviada (IA)*\n\n";
                    $waMsg .= "A pergunta sobre o item '$itemTitle' foi respondida automaticamente:\n\n";
                    $waMsg .= "*Pergunta:* ```" . htmlspecialchars($questionText) . "```\n";
                    $waMsg .= "*Resposta:* ```" . htmlspecialchars($sanitizedAnswerText) . "```\n\n";
                    $waMsg .= "_(Ref. Q#{$questionId})_";
                    sendWhatsAppNotification($whatsappTargetJid, $waMsg);
                } catch (Exception $e) { logMessage("      [AI_TRIGGER QID $questionId] AVISO: Exceção ao enviar notificação Wpp de sucesso: ".$e->getMessage()); }
            }
            logMessage("      [AI_TRIGGER QID $questionId] --- FIM IA (SUCESSO) ---");
            return true;
        } else {
            $postErrorMessage = "Falha postar resposta IA no ML (Code: {$answerResult['httpCode']})";
            logMessage("      [AI_TRIGGER QID $questionId] ERRO post resposta no ML. " . $postErrorMessage);
            upsertQuestionLog($questionId, $mlUserId, $itemId, 'AI_FAILED', null, null, null, $postErrorMessage, $qSaasUserId, $sanitizedAnswerText, $logEntry['whatsapp_notification_message_id'] ?? null);
            logMessage("      [AI_TRIGGER QID $questionId] --- FIM IA (ERRO POST ML) ---");
            return false;
        }

    } catch (\Throwable $e) {
        logMessage("      [AI_TRIGGER QID $questionId] **** ERRO FATAL INESPERADO IA ****");
        logMessage("      Mensagem: {$e->getMessage()} | Arquivo: " . basename($e->getFile()) . " | Linha: {$e->getLine()}");
        if ($questionId > 0 && $mlUserId > 0) {
            $errorMsgForDb = 'Exceção fatal IA: ' . mb_substr($e->getMessage(), 0, 150);
            @upsertQuestionLog( $questionId, $mlUserId, $itemId, 'ERROR', $questionText, null, null, $errorMsgForDb, $qSaasUserId, null, ($logEntry['whatsapp_notification_message_id'] ?? null) );
        }
        logMessage("      [AI_TRIGGER QID $questionId] --- FIM IA (ERRO FATAL INESPERADO) ---");
        return false;
    }
}
?>