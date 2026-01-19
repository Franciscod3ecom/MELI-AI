<?php
/**
 * Arquivo: evolution_webhook_receiver.php
 * Versão: v15.4 - Chama triggerAiForQuestion diretamente para TRIGGER_AI (Confirmado)
 *
 * Descrição:
 * Endpoint de webhook para a API Evolution V2. Processa respostas do usuário via WhatsApp.
 * - Identifica a pergunta original pela mensagem citada (reply).
 * - Usa `interpretUserIntent` (Gemini) para classificar a intenção da resposta.
 * - Se a intenção for responder manualmente, envia a resposta para o Mercado Livre.
 * - Se a intenção for usar IA (`TRIGGER_AI`), chama `triggerAiForQuestion` imediatamente.
 * - Se o formato for inválido, envia feedback ao usuário.
 * - Envia notificações de sucesso/erro para o JID CADASTRADO do usuário SaaS.
 *
 * !! ALERTA DE SEGURANÇA: Validar a origem do webhook (ex: por IP ou token,
 *    se a Evolution API permitir) é altamente recomendado em produção para
 *    evitar processamento de requisições maliciosas. !!
 */

// Includes Essenciais Refatorados
require_once __DIR__ . '/config.php';             // Constantes e Configurações (DB, APIs, etc.)
require_once __DIR__ . '/db.php';                 // getDbConnection() e Funções de Criptografia (Placeholders)
require_once __DIR__ . '/includes/log_helper.php';   // logMessage()
require_once __DIR__ . '/includes/db_interaction.php'; // getQuestionLogStatus(), upsertQuestionLog()
require_once __DIR__ . '/includes/gemini_api.php';   // interpretUserIntent()
require_once __DIR__ . '/includes/ml_api.php';       // postMercadoLibreAnswer(), refreshMercadoLibreToken()
require_once __DIR__ . '/includes/evolution_api.php'; // sendWhatsAppNotification()
require_once __DIR__ . '/includes/core_logic.php';   // triggerAiForQuestion()

logMessage("[Webhook Receiver EVOLUTION v15.4 ENTRY POINT] Script acessado.");

// --- Obter e Validar Payload JSON da Requisição ---
$payload = file_get_contents('php://input');
$data = $payload ? json_decode($payload, true) : null;

// Log do Payload Bruto para Depuração (opcional, cuidado com dados sensíveis)
// logMessage("[Webhook Receiver v15.4 DEBUG] Raw Payload: " . $payload);

// Verifica se o JSON é válido
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    logMessage("[Webhook Receiver v15.4] ERRO JSON Decode: " . json_last_error_msg());
    http_response_code(400); // Bad Request
    exit;
}
if (!$data) {
    logMessage("[Webhook Receiver v15.4] ERRO: Payload inválido ou vazio recebido.");
    http_response_code(400); // Bad Request
    exit;
}

// --- Extração de Dados Principais do Webhook ---
// Tenta extrair dados comuns de diferentes estruturas de webhook da Evolution API
$eventType = $data['event'] ?? ($data['type'] ?? null); // Tipo de evento (ex: 'messages.upsert')
$messageData = $data['data'] ?? ($data['message'] ?? null); // Dados da mensagem
$sender = $data['sender'] ?? ($messageData['key']['remoteJid'] ?? null); // Quem enviou
// Verifica se a mensagem foi enviada pela própria instância da API
$isFromMe = isset($messageData['key']['fromMe']) ? $messageData['key']['fromMe'] : false;

// --- Filtros Iniciais ---
// Ignora mensagens enviadas pela própria API (evita loops)
if ($isFromMe === true) {
    logMessage("[Webhook Receiver v1.5.4] Ignorado (isFromMe=true). Sender: $sender");
    http_response_code(200); // OK, apenas ignoramos
    exit;
}

// Processa apenas eventos de mensagem relevantes
$allowedEvents = ['messages.upsert', 'message']; // Adapte se a Evolution usar outros nomes
if (!in_array($eventType, $allowedEvents)) {
    logMessage("[Webhook Receiver v15.4] Ignorado (evento não é de mensagem): '$eventType'.");
    http_response_code(200); // OK, apenas ignoramos
    exit;
}

// Verifica se a estrutura básica da mensagem está presente
if (!$messageData || !isset($messageData['key']) || empty($sender)) {
    logMessage("[Webhook Receiver v15.4] ERRO: Estrutura da mensagem inválida ou remetente ausente.");
    http_response_code(400); // Bad Request
    exit;
}

// --- Processar Apenas Mensagens de Texto que são Respostas (Replies) ---
$messageContent = $messageData['message'] ?? null;
$userReplyText = null; // Texto da resposta do usuário

// Tenta extrair o texto da mensagem (pode estar em 'conversation' ou 'extendedTextMessage.text')
if (isset($messageContent['conversation'])) {
    $userReplyText = trim($messageContent['conversation']);
} elseif (isset($messageContent['extendedTextMessage']['text'])) {
    $userReplyText = trim($messageContent['extendedTextMessage']['text']);
}

// Tenta extrair informações da mensagem citada (contexto)
// A estrutura pode variar um pouco dependendo da versão da API ou do tipo de mensagem citada
$contextInfo = $messageData['contextInfo'] ?? ($messageContent['extendedTextMessage']['contextInfo'] ?? null);
// O ID da mensagem original (stanzaId) é crucial para encontrar a pergunta no nosso log
$quotedMessageId = $contextInfo['stanzaId'] ?? null;

logMessage("[Webhook Receiver v15.4 DEBUG] Sender: '$sender', UserReplyText: '" . ($userReplyText ?: 'VAZIO/NÃO_TEXTO') . "', QuotedMsgID: " . ($quotedMessageId ?: 'NULL'));

// --- Lógica Principal: Processar Resposta se for um Reply de Texto Válido ---
if (!empty($userReplyText) && $quotedMessageId) {

    logMessage("[Webhook Receiver v15.4] Processando REPLY de '$sender' para Msg Citada ID: '$quotedMessageId'");
    $pdo = null; // Inicializa conexão PDO
    $feedbackTargetJid = null; // JID para enviar feedback (deve ser o JID cadastrado)

    try {
        $pdo = getDbConnection();
        $now = new DateTimeImmutable(); // Timestamp atual

        // 1. Buscar Log da Pergunta pelo ID da MENSAGEM CITADA (whatsapp_notification_message_id)
        logMessage("  [DB Lookup] Buscando log para WA_MSG_ID: '$quotedMessageId'");
        $stmtLog = $pdo->prepare(
            "SELECT ml_question_id, ml_user_id, saas_user_id, item_id, status, question_text
             FROM question_processing_log
             WHERE whatsapp_notification_message_id = :wa_msg_id
             LIMIT 1"
        );
        $stmtLog->execute([':wa_msg_id' => $quotedMessageId]);
        $logEntry = $stmtLog->fetch();

        // Se não encontrou o log correspondente à mensagem citada
        if (!$logEntry) {
            logMessage("  [DB Lookup] Reply - Log NÃO encontrado para WA_MSG_ID: '$quotedMessageId'. Mensagem de '$sender' ignorada.");
            // Considerar enviar uma mensagem de erro para o remetente? ("Não sei a qual pergunta você se refere.")
            // Por ora, apenas ignora.
            http_response_code(200); // OK, pois processamos a lógica de ignorar
            exit;
        }

        // Extrai dados do log encontrado
        $currentStatus = $logEntry['status'];
        $mlQuestionId = (int)$logEntry['ml_question_id'];
        $mlUserId = (int)$logEntry['ml_user_id'];
        $itemId = $logEntry['item_id'];
        $saasUserId = (int)$logEntry['saas_user_id']; // ID do usuário SaaS dono da pergunta
        $originalQuestionText = $logEntry['question_text'];
        logMessage("  [DB Lookup] Reply para QID $mlQuestionId (SaaS $saasUserId) encontrada. Status log: '$currentStatus'");

        // 2. Buscar JID CADASTRADO do usuário SaaS para enviar feedback
        // Importante: O feedback NÃO deve ser enviado para o $sender (que pode ser qualquer número),
        // mas sim para o número que o usuário cadastrou no perfil dele.
        if ($saasUserId > 0) {
            $stmtSaasJid = $pdo->prepare("SELECT whatsapp_jid FROM saas_users WHERE id = :id LIMIT 1");
            $stmtSaasJid->execute([':id' => $saasUserId]);
            $saasUserData = $stmtSaasJid->fetch();
            if ($saasUserData && !empty($saasUserData['whatsapp_jid'])) {
                $feedbackTargetJid = $saasUserData['whatsapp_jid'];
                logMessage("  [DB Lookup] JID CADASTRADO para feedback encontrado: $feedbackTargetJid");
            } else {
                logMessage("  [DB Lookup] AVISO: Usuário SaaS ID $saasUserId não possui whatsapp_jid cadastrado no banco. Feedback não será enviado.");
            }
        } else {
            logMessage("  [DB Lookup] AVISO: SaaS User ID inválido ($saasUserId) encontrado no log da pergunta $mlQuestionId.");
        }

        // 3. Verifica Status Atual do Log
        // Só processa se a pergunta estiver aguardando resposta humana
        if ($currentStatus !== 'AWAITING_TEXT_REPLY') {
            logMessage("  [QID $mlQuestionId] Reply recebido, mas status do log é '$currentStatus' (não é AWAITING_TEXT_REPLY). Ignorando.");
            // Envia feedback informando que a pergunta já foi tratada (ou está em outro estado)
            if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "ℹ️ A pergunta ($mlQuestionId) não está mais aguardando sua resposta (Status atual: $currentStatus). Sua mensagem foi ignorada."); }
            http_response_code(200); // OK, processamos a lógica de ignorar
            exit;
        }
        // Validação extra: verifica se temos o texto da pergunta original (necessário para a IA)
        if (empty(trim($originalQuestionText))) {
            logMessage("  [QID $mlQuestionId] Reply - ERRO CRÍTICO: Texto da pergunta original está vazio no log do banco de dados.");
            if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro interno ao processar sua resposta para a pergunta $mlQuestionId (dados da pergunta ausentes). Por favor, tente responder diretamente no Mercado Livre ou contate o suporte."); }
            upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Texto pergunta original vazio no log (Webhook Evolution)', $saasUserId);
            http_response_code(500); // Erro interno
            exit;
        }

        // 4. Interpreta Intenção do Usuário com IA (Gemini)
        logMessage("  [QID $mlQuestionId] Chamando interpretador de intenção para texto: '$userReplyText'");
        $intentResult = interpretUserIntent($userReplyText, $originalQuestionText); // Chama a função em gemini_api.php
        $replyAction = $intentResult['intent'];          // MANUAL_ANSWER, TRIGGER_AI, INVALID_FORMAT
        $manualAnswerText = $intentResult['cleaned_text']; // Texto limpo se for MANUAL_ANSWER, null caso contrário
        logMessage("  [QID $mlQuestionId] Intenção Interpretada: $replyAction");

        // --- Bloco de Ação Baseado na Intenção ---
        try {
            // 5. Obter/Refrescar Token ML (Necessário apenas para MANUAL_ANSWER)
            $currentAccessToken = null;
            if ($replyAction === 'MANUAL_ANSWER') {
                logMessage("    [Action MANUAL QID $mlQuestionId] Validando e preparando token ML...");
                $stmtMLUser = $pdo->prepare("SELECT id, access_token, refresh_token, token_expires_at FROM mercadolibre_users WHERE ml_user_id = :ml_uid AND saas_user_id = :saas_uid AND is_active = TRUE LIMIT 1");
                $stmtMLUser->execute([':ml_uid' => $mlUserId, ':saas_uid' => $saasUserId]);
                $mlUserConn = $stmtMLUser->fetch();

                if (!$mlUserConn) {
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO FATAL: Conexão ML para $mlUserId (SaaS $saasUserId) não encontrada ou inativa no DB.");
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Conn ML Inativa (Webhook Evolution)', $saasUserId);
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro ao tentar responder pergunta $mlQuestionId: Sua conexão com o Mercado Livre está inativa. Reconecte no painel."); }
                    http_response_code(500); exit;
                }

                try {
                    // !! ALERTA SEGURANÇA !! Usando decryptData placeholder
                    $currentAccessToken = decryptData($mlUserConn['access_token']);
                    $refreshToken = decryptData($mlUserConn['refresh_token']);
                } catch (Exception $e){
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO CRÍTICO decrypt tokens: ".$e->getMessage());
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Falha Decrypt Token (Webhook Evolution)', $saasUserId);
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro interno de segurança ao processar sua resposta para $mlQuestionId."); }
                    http_response_code(500); exit;
                }

                // Verifica se token precisa ser renovado
                $tokenExpiresAt = new DateTimeImmutable($mlUserConn['token_expires_at']);
                 if ($now >= $tokenExpiresAt->modify("-10 minutes")) { // 10 min de margem
                     logMessage("    [Action MANUAL QID $mlQuestionId] Token ML precisa ser renovado...");
                     $refreshResult = refreshMercadoLibreToken($refreshToken); // Chama a função de refresh
                     if($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])){
                         $newData = $refreshResult['response'];
                         $currentAccessToken = $newData['access_token']; // Novo access token
                         $newRefreshToken = $newData['refresh_token'] ?? $refreshToken; // Usa novo refresh token se vier, senão mantém o antigo
                         $newExpAt = $now->modify("+" . ($newData['expires_in'] ?? 21600) . " seconds")->format('Y-m-d H:i:s'); // Calcula nova expiração

                         try {
                             // !! ALERTA SEGURANÇA !! Usando encryptData placeholder
                             $encAT = encryptData($currentAccessToken);
                             $encRT = encryptData($newRefreshToken);
                         } catch(Exception $e) {
                              logMessage("    [Action MANUAL QID $mlQuestionId] ERRO CRÍTICO encrypt pós-refresh: ".$e->getMessage());
                              // Considerar continuar com o token antigo ou falhar? Por segurança, falha.
                              http_response_code(500); exit;
                         }
                         // Atualiza tokens e expiração no banco de dados
                         $upSql = "UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id";
                         $upStmt = $pdo->prepare($upSql);
                         $upStmt->execute([':at'=>$encAT, ':rt'=>$encRT, ':exp'=>$newExpAt, ':id'=>$mlUserConn['id']]);
                         logMessage("    [Action MANUAL QID $mlQuestionId] Refresh do token ML realizado com sucesso.");
                     } else {
                         // Falha ao renovar o token
                         logMessage("    [Action MANUAL QID $mlQuestionId] ERRO FATAL ao renovar token ML. HTTP: {$refreshResult['httpCode']}. Response: " . json_encode($refreshResult['response']));
                         upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Falha Refresh Token ML (Webhook Evolution)', $saasUserId);
                         if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro ao conectar com Mercado Livre para responder $mlQuestionId. Tente reconectar no painel."); }
                         http_response_code(500); exit;
                     }
                 } else {
                     logMessage("    [Action MANUAL QID $mlQuestionId] Token ML ainda válido.");
                 }
                 logMessage("    [Action MANUAL QID $mlQuestionId] Token ML pronto para uso.");
            } // Fim if ($replyAction === 'MANUAL_ANSWER')

            // 6. Executar Ação Baseada na Intenção
            if ($replyAction === 'MANUAL_ANSWER') {
                // Verifica se o texto extraído não é vazio
                if (empty($manualAnswerText)) {
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO: Intenção manual, mas texto extraído vazio após limpeza. Resposta original: '$userReplyText'");
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Não identifiquei um texto válido na sua resposta para a pergunta $mlQuestionId. Tente novamente."); }
                    // Mantém status como AWAITING_TEXT_REPLY? Ou marca como erro? Por ora, mantém.
                    http_response_code(400); // Bad request (resposta vazia)
                    exit;
                }

                // Tenta postar a resposta no Mercado Livre
                logMessage("    [Action MANUAL QID $mlQuestionId] Postando resposta manual no ML: '$manualAnswerText'");
                $answerResult = postMercadoLibreAnswer($mlQuestionId, $manualAnswerText, $currentAccessToken);

                // Processa resultado da postagem no ML
                if ($answerResult['httpCode'] == 200 || $answerResult['httpCode'] == 201) {
                    // Sucesso!
                    logMessage("    [Action MANUAL QID $mlQuestionId] Resposta manual postada no ML com sucesso.");
                    $humanAnsweredTimestamp = $now->format('Y-m-d H:i:s');
                    // Atualiza log local para HUMAN_ANSWERED_VIA_WHATSAPP
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'HUMAN_ANSWERED_VIA_WHATSAPP', null, null, null, null, $saasUserId, null, $quotedMessageId, $humanAnsweredTimestamp);
                    // Envia feedback de sucesso para o JID cadastrado
                    if ($feedbackTargetJid) {
                        logMessage("    [Action MANUAL QID $mlQuestionId] Enviando feedback de sucesso para JID CADASTRADO: $feedbackTargetJid");
                        sendWhatsAppNotification($feedbackTargetJid, "✅ Respondido no Mercado Livre!\n\nSua resposta para a pergunta ($mlQuestionId) foi enviada com sucesso.");
                    }
                    http_response_code(200); // OK
                    exit;
                } else {
                    // Falha ao postar no ML
                    logMessage("    [Action MANUAL QID $mlQuestionId] ERRO ao postar resposta manual no ML. HTTP Code: {$answerResult['httpCode']}. Response: " . json_encode($answerResult['response']));
                    // Atualiza log local para ERROR
                    upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, "Falha Post ML (Webhook Evolution): HTTP {$answerResult['httpCode']}", $saasUserId, null, $quotedMessageId);
                    // Envia feedback de erro para o JID cadastrado
                    if ($feedbackTargetJid) { sendWhatsAppNotification($feedbackTargetJid, "⚠️ Falha ao enviar sua resposta para a pergunta $mlQuestionId no Mercado Livre (Erro: {$answerResult['httpCode']}). Tente responder diretamente no ML."); }
                    http_response_code(500); // Erro interno do servidor (falha na comunicação com ML)
                    exit;
                }
            }
            elseif ($replyAction === 'TRIGGER_AI') {
                // Usuário pediu para a IA responder
                logMessage("    [Action TRIGGER_AI QID $mlQuestionId] Acionando IA (intenção '2' detectada)...");
                // **** Chama a função core_logic imediatamente ****
                $aiSuccess = triggerAiForQuestion($mlQuestionId); // Esta função já lida com logs e notificações

                if ($aiSuccess) {
                    logMessage("    [Action TRIGGER_AI QID $mlQuestionId] Função triggerAiForQuestion retornou SUCESSO.");
                    // A notificação de sucesso/falha já foi enviada de dentro de triggerAiForQuestion
                    http_response_code(200); // OK
                    exit;
                } else {
                    logMessage("    [Action TRIGGER_AI QID $mlQuestionId] Função triggerAiForQuestion retornou FALHA (ver logs anteriores).");
                    // A notificação de falha também já foi enviada de dentro de triggerAiForQuestion (ou deveria)
                    http_response_code(500); // Indica que houve uma falha no processamento da IA
                    exit;
                 }
                 // **** FIM DA LÓGICA TRIGGER_AI ****
            }
            elseif ($replyAction === 'INVALID_FORMAT') {
                 // IA classificou a resposta como inválida/não processável
                 logMessage("    [Action INVALID QID $mlQuestionId] Intenção classificada como inválida pela IA: '$userReplyText'");
                 // Envia feedback de formato inválido para o JID cadastrado
                 if ($feedbackTargetJid) {
                     logMessage("    [Action INVALID QID $mlQuestionId] Enviando feedback de formato inválido para JID CADASTRADO: $feedbackTargetJid");
                     sendWhatsAppNotification($feedbackTargetJid, "⚠️ Não entendi sua resposta para a pergunta $mlQuestionId.\n\n➡️ Para responder manualmente, apenas digite o texto da sua resposta.\n➡️ Para usar a IA, responda apenas com o número `2`.");
                 }
                 // Não altera o status no DB, mantém como AWAITING_TEXT_REPLY
                 http_response_code(200); // OK, processamos a lógica de formato inválido
                 exit;
            } else {
                // Situação inesperada (não deveria acontecer se interpretUserIntent funciona)
                logMessage("  [QID $mlQuestionId] ERRO INTERNO: Intenção desconhecida retornada por interpretUserIntent: '$replyAction'");
                http_response_code(500); // Erro interno
                exit;
            }

        } catch (\Exception $e) {
            // Captura erros durante a obtenção/refresh do token ou execução da ação
            logMessage("  [Action QID $mlQuestionId] ERRO CRÍTICO durante ação '$replyAction': " . $e->getMessage());
            // Tenta atualizar o log como erro, mesmo dentro do catch
            @upsertQuestionLog($mlQuestionId, $mlUserId, $itemId, 'ERROR', null, null, null, 'Exceção Ação Webhook: '.substr($e->getMessage(),0,150), $saasUserId);
            // Tenta enviar feedback de erro genérico
            if ($feedbackTargetJid) { @sendWhatsAppNotification($feedbackTargetJid, "⚠️ Erro interno ao processar sua resposta para a pergunta $mlQuestionId. Contate o suporte se persistir."); }
            http_response_code(500); // Erro interno
            exit;
        }

    } catch (\Throwable $e) { // Captura erros na busca inicial do log ou conexão DB
        logMessage("[Webhook Receiver v15.4 Lookup/Init] ERRO CRÍTICO processando WA Msg ID '$quotedMessageId': " . $e->getMessage());
        // Não temos $feedbackTargetJid aqui ainda, então não podemos notificar o usuário facilmente
        http_response_code(500); // Erro interno
        exit;
    }

} else {
    // Mensagem não é texto ou não é um reply (quotedMessageId está null)
    // Loga apenas se for mensagem de texto para não poluir com status, etc.
    if($userReplyText !== null) {
      // logMessage("[Webhook Receiver v15.4] Mensagem de texto ignorada (não é reply ou texto vazio). Sender: $sender");
    }
    http_response_code(200); // OK, apenas ignoramos
    exit;
}

?>