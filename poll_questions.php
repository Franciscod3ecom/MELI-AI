<?php
/**
 * Arquivo: poll_questions.php
 * Versão: v21 - Adiciona nome da loja (nickname) na notificação do WhatsApp.
 * Descrição: Script CRON híbrido (Fallback + Gerenciador de Timeout).
 */

// Configurações de execução
set_time_limit(900); // 15 minutos
date_default_timezone_set('America/Sao_Paulo');

// --- Includes Essenciais ---
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';
require_once __DIR__ . '/includes/db_interaction.php';
require_once __DIR__ . '/includes/ml_api.php';
require_once __DIR__ . '/includes/evolution_api.php';
require_once __DIR__ . '/includes/core_logic.php';

if (!function_exists('logMessage')) {
    function logMessage(string $message): void { error_log($message); }
}

logMessage("==== [CRON START v21] Iniciando ciclo Híbrido (Fallback + Timeout) ====");

try {
    $pdo = getDbConnection();
    $globalNow = new DateTimeImmutable();

    logMessage("[CRON v21] Buscando conexões ativas...");
    $sql_connections = "SELECT mlu.id AS connection_id, mlu.saas_user_id, mlu.ml_user_id,
                               mlu.access_token, mlu.refresh_token, mlu.token_expires_at,
                               su.whatsapp_jid, su.email AS saas_user_email
                        FROM mercadolibre_users mlu
                        JOIN saas_users su ON mlu.saas_user_id = su.id
                        WHERE mlu.is_active = TRUE AND su.is_saas_active = TRUE
                        ORDER BY mlu.updated_at ASC";
    $stmt_connections = $pdo->query($sql_connections);
    $activeConnections = $stmt_connections->fetchAll();

    if (!$activeConnections) {
        logMessage("[CRON v21 INFO] Nenhuma conexão ativa válida encontrada.");
        logMessage("==== [CRON END v21] Ciclo finalizado sem usuários ativos ====");
        exit;
    }
    $totalActiveConnections = count($activeConnections);
    logMessage("[CRON v21 INFO] Conexões ativas encontradas: " . $totalActiveConnections);

    $processedConnectionCount = 0;
    foreach ($activeConnections as $conn) {
        $processedConnectionCount++;
        $connectionIdInDb = $conn['connection_id'];
        $mlUserId = $conn['ml_user_id'];
        $saasUserId = $conn['saas_user_id'];
        $saasUserEmail = $conn['saas_user_email'];
        $whatsappTargetJid = $conn['whatsapp_jid'];
        $dbAccessTokenEncrypted = $conn['access_token'];
        $dbRefreshTokenEncrypted = $conn['refresh_token'];
        $tokenExpiresAtStr = $conn['token_expires_at'];
        $currentAccessToken = null;

        logMessage("--> [ML $mlUserId / SaaS $saasUserId ($processedConnectionCount/$totalActiveConnections)] Processando...");

        try {
            // --- 2.1. Refresh Token ---
            logMessage("    [ML $mlUserId] Verificando token...");
            try {
                 if (empty($tokenExpiresAtStr)) { throw new Exception("Data expiração vazia DB."); }
                 $tokenExpiresAt = new DateTimeImmutable($tokenExpiresAtStr);
                 if ($globalNow >= $tokenExpiresAt->modify("-10 minutes")) {
                     logMessage("    [ML $mlUserId] REFRESH NECESSÁRIO...");
                     $decryptedRefreshToken = decryptData($dbRefreshTokenEncrypted);
                     $refreshResult = refreshMercadoLibreToken($decryptedRefreshToken);
                     if ($refreshResult['httpCode'] == 200 && isset($refreshResult['response']['access_token'])) {
                         $newData = $refreshResult['response'];
                         $currentAccessToken = $newData['access_token'];
                         $newRefreshToken = $newData['refresh_token'] ?? $decryptedRefreshToken;
                         $newExpiresIn = $newData['expires_in'] ?? 21600;
                         $newExpAt = $globalNow->modify("+" . (int)$newExpiresIn . " seconds")->format('Y-m-d H:i:s');
                         $encAT = encryptData($currentAccessToken);
                         $encRT = encryptData($newRefreshToken);
                         $upSql = "UPDATE mercadolibre_users SET access_token = :at, refresh_token = :rt, token_expires_at = :exp, updated_at = NOW() WHERE id = :id";
                         $upStmt = $pdo->prepare($upSql);
                         if($upStmt->execute([':at'=>$encAT, ':rt'=>$encRT,':exp'=>$newExpAt,':id'=>$connectionIdInDb])) {
                             logMessage("    [ML $mlUserId] Refresh OK, DB atualizado.");
                         } else {
                             logMessage("    [ML $mlUserId] ERRO SQL ao salvar token pós-refresh.");
                             continue;
                         }
                     } else {
                         $errorResponse = json_encode($refreshResult['response'] ?? $refreshResult['error'] ?? 'N/A');
                         logMessage("    [ML $mlUserId] ERRO FATAL no refresh API ML. Desativando conexão. Code: {$refreshResult['httpCode']}. Resp: $errorResponse");
                         @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb);
                         @upsertQuestionLog(0, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Falha refresh token API ML (CRON)', $saasUserId);
                         continue;
                     }
                 } else {
                     logMessage("    [ML $mlUserId] Token válido, descriptografando...");
                     $currentAccessToken = decryptData($dbAccessTokenEncrypted);
                 }
            } catch (Exception $e) {
                 logMessage("    [ML $mlUserId] ERRO validação/refresh/decrypt token: ".$e->getMessage());
                 @upsertQuestionLog(0, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Erro token ML (CRON): '.substr($e->getMessage(),0,150), $saasUserId);
                 continue;
            }
            if (empty($currentAccessToken)) {
                logMessage("    [ML $mlUserId] ERRO INTERNO INESPERADO: Access token vazio após lógica. Pulando usuário.");
                continue;
            }
            logMessage("    [ML $mlUserId] Token pronto.");

            // --- 2.2. [FASE 1 - FALLBACK] Buscar Perguntas RECENTES Perdidas ---
            logMessage("    [ML $mlUserId - Fallback] Buscando perguntas recentes (últimos 7 dias) não registradas...");
            $daysToLookBackFallback = 7;
            $dateFromFilterFallback = $globalNow->modify("-{$daysToLookBackFallback} days")->format(DateTime::ATOM);
            $limitPerPageFallback = 50; $offsetFallback = 0; $processedInFallback = 0;
            $maxPagesFallback = 5; $currentPageFallback = 0;

            do {
                $currentPageFallback++;
                logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] Buscando...");
                $questionsResult = getMercadoLibreQuestions($mlUserId, $currentAccessToken, $dateFromFilterFallback, $limitPerPageFallback, $offsetFallback);
                $returnedQuestions = []; $returnedCount = 0;

                if ($questionsResult['httpCode'] == 200 && $questionsResult['is_json'] && isset($questionsResult['response']['questions'])) {
                    $returnedQuestions = $questionsResult['response']['questions'];
                    $returnedCount = count($returnedQuestions);
                    logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] Recebidas $returnedCount perguntas.");

                    if ($returnedCount > 0) {
                        foreach ($returnedQuestions as $question) {
                             $questionId = $question['id'] ?? null; if (!$questionId) continue; $questionId = (int)$questionId;
                             $logStatus = getQuestionLogStatus($questionId);
                             if (!$logStatus) {
                                 $processedInFallback++;
                                 logMessage("        [QID $questionId / Fallback] Pergunta RECENTE encontrada e NÃO está no log. Processando...");
                                 $itemId = $question['item_id'] ?? 'N/A'; $questionTextRaw = $question['text'] ?? '';
                                 if (empty(trim($questionTextRaw)) || empty($itemId) || $itemId === 'N/A') { logMessage("          [QID $questionId / Fallback] ERRO: Dados inválidos da pergunta. Pulando."); continue; }
                                 if (empty($whatsappTargetJid)) { logMessage("          [QID $questionId / Fallback] Sem JID para notificar. Marcando PENDING."); upsertQuestionLog($questionId, $mlUserId, $itemId, 'PENDING_WHATSAPP', $questionTextRaw, null, null, 'JID não config (Detectado CRON)', $saasUserId); continue; }

                                 logMessage("          [QID $questionId / Fallback] Buscando item $itemId...");
                                 $itemTitle = '[Prod não encontrado]'; $itemImageUrl = null;
                                 $itemResult = getMercadoLibreItemDetails($itemId, $currentAccessToken);
                                 if ($itemResult['httpCode'] == 200 && $itemResult['is_json']) { $itemData = $itemResult['response']; $itemTitle = $itemData['title'] ?? $itemTitle; $itemImageUrl = $itemData['pictures'][0]['secure_url'] ?? $itemData['thumbnail'] ?? null; }
                                 else { logMessage("          [QID $questionId / Fallback] WARN: Falha detalhes item $itemId."); }

                                 // BUSCAR NOME DE USUÁRIO ML
                                 $mlUserNickname = '[Loja não identificada]';
                                 $mlUserDetails = getMercadoLivreUserDetails($mlUserId, $currentAccessToken);
                                 if ($mlUserDetails && isset($mlUserDetails['nickname'])) {
                                     $mlUserNickname = $mlUserDetails['nickname'];
                                 }
                                 logMessage("          [QID $questionId / Fallback] Nome da loja ML obtido: $mlUserNickname");

                                 // Montar caption (ATUALIZADA)
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
                                     logMessage("          [QID $questionId / Fallback] Enviando notificação COM IMAGEM para $whatsappTargetJid...");
                                     $whatsappMessageId = sendWhatsAppImageNotification( $whatsappTargetJid, $itemImageUrl, $captionText );
                                 } else {
                                     logMessage("          [QID $questionId / Fallback] Enviando notificação SEM IMAGEM para $whatsappTargetJid...");
                                     $whatsappMessageId = sendWhatsAppNotification( $whatsappTargetJid, $captionText );
                                 }

                                 $initialStatus = $whatsappMessageId ? 'AWAITING_TEXT_REPLY' : 'PENDING_WHATSAPP';
                                 $sentTimestamp = $whatsappMessageId ? $globalNow->format('Y-m-d H:i:s') : null;
                                 $errorMsg = ($initialStatus === 'PENDING_WHATSAPP') ? 'Falha envio Wpp via CRON Fallback' : null;
                                 logMessage("          [QID $questionId / Fallback] Resultado envio Wpp: " . ($whatsappMessageId ? "Sucesso (MsgID: $whatsappMessageId)" : "Falha"));
                                 $upsertOK = upsertQuestionLog($questionId, $mlUserId, $itemId, $initialStatus, $questionTextRaw, $sentTimestamp, null, $errorMsg, $saasUserId, null, $whatsappMessageId);
                                 if($upsertOK){ logMessage("          [QID $questionId / Fallback] UPSERT LOG OK (Status: $initialStatus)."); } else { logMessage("          [QID $questionId / Fallback] ERRO UPSERT LOG (Status: $initialStatus)!"); }
                                 sleep(mt_rand(1, 2));
                             }
                        }
                        $offsetFallback += $returnedCount;
                    }
                } else {
                    logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] ERRO ao buscar perguntas recentes. HTTP: {$questionsResult['httpCode']}. Error: " . ($questionsResult['error'] ?? 'N/A'));
                     if ($questionsResult['httpCode'] == 403 || $questionsResult['httpCode'] == 401) { logMessage("      [ML $mlUserId Fallback Page $currentPageFallback] ERRO 401/403. Desativando conexão."); @$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb); }
                    $returnedCount = 0;
                }
            } while ($returnedCount === $limitPerPageFallback && $currentPageFallback < $maxPagesFallback);

            if ($currentPageFallback >= $maxPagesFallback && $returnedCount === $limitPerPageFallback) { logMessage("      [ML $mlUserId Fallback] ATENÇÃO: Limite máximo de páginas ($maxPagesFallback) atingido."); }
            logMessage("    [ML $mlUserId - Fallback] Processadas $processedInFallback perguntas recentes que não estavam no log.");

            // --- 2.3. [FASE 2 - TIMEOUT] Verificar Timeout e Acionar IA ---
            $aiTimeoutMinutes = defined('AI_FALLBACK_TIMEOUT_MINUTES') ? AI_FALLBACK_TIMEOUT_MINUTES : 10;
            logMessage("    [ML $mlUserId - Timeout Check] Verificando perguntas 'AWAITING_TEXT_REPLY' com timeout > $aiTimeoutMinutes min...");
            $timeoutThreshold = $globalNow->modify("-" . $aiTimeoutMinutes . " minutes")->format('Y-m-d H:i:s');
            $sqlTimeout = "SELECT ml_question_id FROM question_processing_log
                           WHERE ml_user_id = :ml_uid
                             AND status = 'AWAITING_TEXT_REPLY'
                             AND sent_to_whatsapp_at IS NOT NULL
                             AND sent_to_whatsapp_at <= :limit
                           ORDER BY sent_to_whatsapp_at DESC";
            $stmtTimeout = $pdo->prepare($sqlTimeout);
            $stmtTimeout->execute([':ml_uid' => $mlUserId, ':limit' => $timeoutThreshold]);
            $pendingTimeoutQuestions = $stmtTimeout->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($pendingTimeoutQuestions)) {
                $countTimeout = count($pendingTimeoutQuestions);
                logMessage("    [ML $mlUserId - Timeout Check] Encontradas $countTimeout perguntas com timeout para IA: " . implode(', ', $pendingTimeoutQuestions));
                $processedAiCount = 0;
                $maxAiPerCron = 20;

                foreach ($pendingTimeoutQuestions as $questionIdToProcess) {
                    if ($processedAiCount >= $maxAiPerCron) {
                        logMessage("    [ML $mlUserId - Timeout Check] Limite processamento IA por usuário ($maxAiPerCron) atingido neste ciclo.");
                        break;
                    }
                    $questionIdToProcess = (int)$questionIdToProcess;
                    logMessage("      [QID $questionIdToProcess / Timeout] Acionando IA via core_logic...");

                    $aiSuccess = triggerAiForQuestion($questionIdToProcess);

                    if ($aiSuccess) {
                        logMessage("      [QID $questionIdToProcess / Timeout] triggerAiForQuestion retornou SUCESSO.");
                        $processedAiCount++;
                    } else {
                        logMessage("      [QID $questionIdToProcess / Timeout] triggerAiForQuestion retornou FALHA (ver logs anteriores).");
                    }
                    sleep(mt_rand(3, 6));
                }
                 logMessage("    [ML $mlUserId - Timeout Check] Processadas $processedAiCount perguntas via IA neste ciclo.");
            } else {
                logMessage("    [ML $mlUserId - Timeout Check] Nenhuma pergunta encontrada com timeout para IA.");
            }

        } catch (\Exception $userProcessingError) {
            $errorFile = basename($userProcessingError->getFile()); $errorLine = $userProcessingError->getLine();
            logMessage("!! ERRO GERAL INESPERADO processando ML ID $mlUserId ($errorFile Linha $errorLine): " . $userProcessingError->getMessage());
            @upsertQuestionLog(0, $mlUserId, 'N/A', 'ERROR', null, null, null, 'Exceção CRON usuário: '.substr($userProcessingError->getMessage(),0,150), $saasUserId);
        } finally {
             sleep(mt_rand(1, 2));
        }

    } // Fim foreach

} catch (\PDOException $dbErr) {
    logMessage("!!!! ERRO FATAL CRON v21 (DB Connection/Query): " . $dbErr->getMessage());
} catch (\Throwable $e) {
    $errorFile = basename($e->getFile()); $errorLine = $e->getLine();
    logMessage("!!!! ERRO FATAL CRON v21 (Geral - $errorFile Linha $errorLine): " . $e->getMessage());
}

logMessage("==== [CRON END v21] Ciclo Híbrido finalizado ====\n");