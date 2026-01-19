<?php
/**
 * Arquivo: includes/db_interaction.php
 * Versão: v1.0
 * Descrição: Funções para interagir com a tabela de log `question_processing_log`.
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/../db.php'; // Para getDbConnection()

/**
 * Busca o estado atual e os dados de uma pergunta específica no log interno (`question_processing_log`).
 * @param int $questionId O ID da pergunta do Mercado Livre.
 * @return array<string, mixed>|false Retorna um array associativo ou `false`.
 * @throws PDOException Em caso de falha grave.
 */
function getQuestionLogStatus(int $questionId): array|false
{
    if ($questionId <= 0) { logMessage("[getQuestionLogStatus] Tentativa de busca com ID inválido: $questionId"); return false; }
    try {
        $pdo = getDbConnection(); $stmt = $pdo->prepare("SELECT * FROM question_processing_log WHERE ml_question_id = :qid LIMIT 1");
        $stmt->execute([':qid' => $questionId]); $logEntry = $stmt->fetch();
        if (is_array($logEntry)) { return $logEntry; } else { if ($logEntry !== false) { logMessage("[getQuestionLogStatus] AVISO: fetch() QID $questionId retornou tipo inesperado: " . gettype($logEntry)); } return false; }
    } catch (\PDOException $e) { logMessage("[getQuestionLogStatus] ERRO DB ao buscar QID $questionId: " . $e->getMessage()); return false; }
}

/**
 * Insere ou atualiza um registro no log de processamento de perguntas (`question_processing_log`).
 * @param int $questionId ID da pergunta ML.
 * @param int $mlUserId ID do vendedor ML.
 * @param string $itemId ID do item ML.
 * @param string $status Novo status da pergunta.
 * @param string|null $questionText Texto da pergunta (opcional).
 * @param string|null $sentAtTimestamp Timestamp ISO 8601 do envio ao WhatsApp (opcional).
 * @param string|null $aiAnsweredTimestamp Timestamp ISO 8601 da resposta da IA (opcional).
 * @param string|null $errorMessage Mensagem de erro (opcional, SEMPRE atualiza).
 * @param int|null $saasUserId ID do usuário SaaS associado (opcional).
 * @param string|null $iaResponseText Texto da resposta gerada pela IA (opcional).
 * @param string|null $whatsappMsgId ID da mensagem de notificação no WhatsApp (opcional).
 * @param string|null $humanAnsweredTimestamp Timestamp ISO 8601 da resposta humana via WhatsApp (opcional).
 * @return bool True se a operação foi bem-sucedida, False em caso de falha.
 * @throws PDOException Em caso de falha grave.
 */
function upsertQuestionLog( int $questionId, int $mlUserId, string $itemId, string $status, ?string $questionText = null, ?string $sentAtTimestamp = null, ?string $aiAnsweredTimestamp = null, ?string $errorMessage = null, ?int $saasUserId = null, ?string $iaResponseText = null, ?string $whatsappMsgId = null, ?string $humanAnsweredTimestamp = null ): bool
{
    if (($questionId <= 0 || $mlUserId <= 0) && strtoupper($status) !== 'ERROR') { logMessage("[upsertQuestionLog] ERRO: Upsert com QID ($questionId) ou ML UID ($mlUserId) inválido para status '$status'."); return false; }
    $maxLengthErrorMessage = 250; $truncatedErrorMessage = $errorMessage !== null ? mb_substr((string)$errorMessage, 0, $maxLengthErrorMessage) : null;
    if ($errorMessage !== null && mb_strlen($errorMessage) > $maxLengthErrorMessage) { logMessage("[upsertQuestionLog] Aviso: Msg erro truncada QID $questionId."); }
    logMessage("[upsertQuestionLog] Iniciando QID: $questionId. Status: '$status'. ML UID: $mlUserId.");
    try {
        $pdo = getDbConnection();
        $sql = "INSERT INTO question_processing_log (ml_question_id, ml_user_id, saas_user_id, item_id, question_text, status, sent_to_whatsapp_at, ai_answered_at, human_answered_at, error_message, ia_response_text, whatsapp_notification_message_id, created_at, last_processed_at) VALUES (:qid, :ml_uid, :saas_uid, :item_id, :q_text, :status, :sent_at, :ai_at, :human_at, :err_msg, :ia_resp, :wa_msg_id, NOW(), NOW()) ON DUPLICATE KEY UPDATE ml_user_id = VALUES(ml_user_id), saas_user_id = COALESCE(VALUES(saas_user_id), saas_user_id), item_id = VALUES(item_id), question_text = COALESCE(VALUES(question_text), question_text), status = VALUES(status), sent_to_whatsapp_at = COALESCE(VALUES(sent_to_whatsapp_at), sent_to_whatsapp_at), ai_answered_at = COALESCE(VALUES(ai_answered_at), ai_answered_at), human_answered_at = COALESCE(VALUES(human_answered_at), human_answered_at), error_message = VALUES(error_message), ia_response_text = COALESCE(VALUES(ia_response_text), ia_response_text), whatsapp_notification_message_id = COALESCE(VALUES(whatsapp_notification_message_id), whatsapp_notification_message_id), last_processed_at = NOW()";
        $stmt = $pdo->prepare($sql); if (!$stmt) { logMessage("[upsertQuestionLog QID: $questionId] ERRO CRÍTICO: Falha preparar query."); return false; }
        $paramsToBind = [ ':qid' => $questionId, ':ml_uid' => $mlUserId, ':saas_uid' => $saasUserId, ':item_id' => $itemId, ':q_text' => $questionText, ':status' => $status, ':sent_at' => $sentAtTimestamp, ':ai_at' => $aiAnsweredTimestamp, ':human_at' => $humanAnsweredTimestamp, ':err_msg' => $truncatedErrorMessage, ':ia_resp' => $iaResponseText, ':wa_msg_id' => $whatsappMsgId ];
        $success = $stmt->execute($paramsToBind);
        if (!$success) { $errorInfo = $stmt->errorInfo(); logMessage("[upsertQuestionLog QID: $questionId] ERRO executar upsert status '$status'. Info: " . ($errorInfo[2] ?? 'N/A')); }
        return $success;
    } catch (\PDOException $e) { logMessage("[upsertQuestionLog QID: $questionId] ERRO DB upsert: " . $e->getMessage()); return false; }
}
?>