<?php
/**
 * Arquivo: asaas_webhook_receiver.php
 * Versão: v1.2 - Garante limpeza de expire_date se não ATIVO
 * Descrição: Endpoint para receber e processar notificações (Webhooks) do Asaas.
 *            Valida assinatura e atualiza status/expiração no DB local.
 * !! SEGURANÇA CRÍTICA: Defina ASAAS_WEBHOOK_SECRET em config.php !!
 *    Verifique também o firewall/WAF do servidor se ocorrer erro 403.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Para ASAAS_WEBHOOK_SECRET, constantes DB
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

logMessage("==== [Asaas Webhook Receiver v1.2] Notificação Recebida ====");

// --- Validação da Requisição ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("[Asaas Webhook v1.2] ERRO: Método HTTP inválido ({$_SERVER['REQUEST_METHOD']}).");
    http_response_code(405); // Method Not Allowed
    exit;
}

// --- Validação da Assinatura (ESSENCIAL PARA SEGURANÇA) ---
$payload = file_get_contents('php://input'); // Lê o corpo da requisição ANTES de decodificar
$receivedSignature = $_SERVER['HTTP_ASAAS_SIGNATURE'] ?? null; // Header esperado do Asaas
$data = null; // Inicializa $data

// A validação só ocorre se a constante estiver definida e não vazia
if (defined('ASAAS_WEBHOOK_SECRET') && !empty(ASAAS_WEBHOOK_SECRET)) {
    $webhookSecret = ASAAS_WEBHOOK_SECRET;

    // Verifica se o segredo placeholder ainda está sendo usado (ajuste conforme seu placeholder)
    $placeholders = [
        'SUBSTITUA_PELO_SEU_TOKEN_SECRETO_CONFIGURADO_NO_ASAAS',
        'SEU_TOKEN_SECRETO_WEBHOOK_ASAAS',
        'zL9qR+sTvXuYwZ1eFgHjKlMnO/pQrStUvWxY/Z012=' // Exemplo que usamos
    ];
    if (in_array($webhookSecret, $placeholders)) {
         logMessage("[Asaas Webhook v1.2] ALERTA DE SEGURANÇA GRAVE: ASAAS_WEBHOOK_SECRET está com valor placeholder ('".substr($webhookSecret,0,10)."...')! Validação efetivamente DESATIVADA. Configure um segredo real URGENTEMENTE!");
         // Permite continuar para não parar o fluxo durante a configuração, mas é INSEGURO.
         $data = $payload ? json_decode($payload, true) : null;
    }
    // Se tem um segredo (aparentemente) real, valida
    elseif (!$receivedSignature) {
        logMessage("[Asaas Webhook v1.2] ERRO: Header de assinatura 'Asaas-Signature' ausente na requisição. Retornando 403.");
        http_response_code(403); // Forbidden - Assinatura esperada, mas não veio
        exit;
    } else {
        // Calcula a assinatura esperada usando HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        // Compara as assinaturas de forma segura contra timing attacks
        if (!hash_equals($expectedSignature, $receivedSignature)) {
            logMessage("[Asaas Webhook v1.2] ERRO: Assinatura inválida. Esperada: $expectedSignature Recebida: $receivedSignature Payload (inicio): ".substr($payload, 0, 100) . ". Retornando 403.");
            http_response_code(403); // Forbidden - Assinatura inválida
            exit;
        }
        logMessage("[Asaas Webhook v1.2] Assinatura HMAC-SHA256 validada com sucesso.");
        // Decodifica o JSON *APÓS* validar a assinatura
        $data = json_decode($payload, true);
    }
} else {
    // Se a constante não está definida ou está vazia, loga um aviso e processa sem validar
    logMessage("[Asaas Webhook v1.2] AVISO: Validação de assinatura DESABILITADA (ASAAS_WEBHOOK_SECRET não definida ou vazia). Processando sem validar origem (INSEGURO)!");
    $data = $payload ? json_decode($payload, true) : null;
}
// --- Fim Validação de Assinatura ---

// --- Validação Payload JSON ---
if (!$data || json_last_error() !== JSON_ERROR_NONE || !isset($data['event'])) {
    logMessage("[Asaas Webhook v1.2] ERRO: Payload JSON inválido ou campo 'event' ausente. JSON Error: " . json_last_error_msg() . ". Payload (inicio): ".substr($payload, 0, 100));
    http_response_code(400); // Bad Request
    exit;
}

$eventName = $data['event'] ?? 'UNKNOWN';
logMessage("[Asaas Webhook v1.2] Evento: $eventName");

// --- Processamento ---
$pdo = null;
try {
    $pdo = getDbConnection();
    $pdo->beginTransaction(); // Usa transação para garantir atomicidade

    $subscriptionId = null; $paymentData = null; $subscriptionData = null;
    $newLocalStatus = null; $newExpireDate = null; // Resetados a cada webhook

    // Extrai ID da assinatura Asaas do payload
    if (isset($data['payment']['subscription'])) {
        $subscriptionId = $data['payment']['subscription'];
        $paymentData = $data['payment'];
    } elseif (isset($data['subscription']['id'])) {
        $subscriptionId = $data['subscription']['id'];
        $subscriptionData = $data['subscription'];
    }

    // Se não conseguiu extrair ID, ignora o evento
    if (!$subscriptionId) {
        logMessage("  [WH v1.2] Ignorando evento $eventName sem ID de assinatura Asaas reconhecido.");
        $pdo->rollBack(); // Cancela transação
        http_response_code(200); // OK para Asaas, não é erro nosso
        exit;
    }
    logMessage("  [WH v1.2] Processando para Asaas Sub ID: $subscriptionId");

    // Mapeia evento do Asaas para status local e data de expiração
    switch ($eventName) {
        // Pagamento Recebido/Confirmado -> Status ATIVO
        case 'PAYMENT_RECEIVED':
        case 'PAYMENT_CONFIRMED':
            $newLocalStatus = 'ACTIVE';
            $newExpireDate = $paymentData['nextDueDate'] ?? null; // Data de vencimento da *próxima* fatura
            break;

        // Pagamento Atualizado
        case 'PAYMENT_UPDATED':
            $paymentStatus = $paymentData['status'] ?? 'UNKNOWN';
            if (in_array($paymentStatus, ['RECEIVED', 'CONFIRMED'])) {
                $newLocalStatus = 'ACTIVE';
                $newExpireDate = $paymentData['nextDueDate'] ?? null;
            } elseif (in_array($paymentStatus, ['OVERDUE', 'FAILED'])) {
                $newLocalStatus = 'OVERDUE'; // Ou 'FAILED' se quiser diferenciar
            }
            // Ignora outros status como PENDING, AWAITING_RISK_ANALYSIS, etc.
            break;

        // Pagamento Vencido/Falhado -> Status OVERDUE (ou FAILED)
        case 'PAYMENT_OVERDUE':
        case 'PAYMENT_FAILED':
            $newLocalStatus = 'OVERDUE';
            break;

        // Assinatura Atualizada (Cancelada, Expirada, Reativada)
        case 'SUBSCRIPTION_UPDATED':
             $newAsaasStatus = $subscriptionData['status'] ?? 'UNKNOWN';
             if ($newAsaasStatus === 'ACTIVE') {
                 $newLocalStatus = 'ACTIVE';
                 $newExpireDate = $subscriptionData['nextDueDate'] ?? null; // Data da própria assinatura
             } elseif (in_array($newAsaasStatus, ['EXPIRED', 'CANCELLED'])) {
                 $newLocalStatus = 'CANCELED'; // Mapeia ambos para CANCELED localmente
             }
             // Ignora outros status da assinatura
             break;

        // Assinatura Deletada (se Asaas enviar e for relevante tratar)
        // case 'SUBSCRIPTION_DELETED':
        //     $newLocalStatus = 'CANCELED'; // Ou um status 'DELETED' se preferir
        //     break;

        // Evento de Criação (geralmente não muda status local)
        case 'PAYMENT_CREATED':
             logMessage("  [WH v1.2] Evento PAYMENT_CREATED para Sub: $subscriptionId (informativo).");
             break;

        default:
            // Eventos não mapeados são ignorados para atualização de status
            logMessage("  [WH v1.2] Evento $eventName não mapeado para mudança de status local.");
            break;
    }

    // Se um novo status local foi determinado pelo switch, prossegue com a atualização no DB
    if ($newLocalStatus !== null) {
        logMessage("  [WH v1.2 Update DB] Novo Status Local Determinado: '$newLocalStatus'. Próx Venc: " . ($newExpireDate ?: 'N/A'));

        // Busca o usuário local associado a esta assinatura Asaas
        $stmtFindUser = $pdo->prepare("SELECT id, subscription_status, subscription_expires_at FROM saas_users WHERE asaas_subscription_id = :sub_id");
        $stmtFindUser->execute([':sub_id' => $subscriptionId]);
        $foundUser = $stmtFindUser->fetch();

        if ($foundUser) {
            $saasUserId = $foundUser['id'];
            $currentLocalStatus = $foundUser['subscription_status'];
            $currentExpireDate = $foundUser['subscription_expires_at'];
            logMessage("    -> Usuário SaaS encontrado (ID: $saasUserId). Status Atual DB: '$currentLocalStatus'. Expira Atual DB: " . ($currentExpireDate ?: 'N/A'));

            // Determina se a atualização é realmente necessária
            $needsUpdate = false;
            if ($currentLocalStatus !== $newLocalStatus) {
                $needsUpdate = true;
                logMessage("    -> Mudança de Status: '$currentLocalStatus' -> '$newLocalStatus'. Update necessário.");
            }
            // Se o status for ACTIVE, verifica se a data de expiração precisa ser atualizada
            if ($newLocalStatus === 'ACTIVE' && $newExpireDate !== null && $currentExpireDate !== $newExpireDate) {
                 $needsUpdate = true;
                 logMessage("    -> Atualização da Data de Expiração: " . ($currentExpireDate ?: 'NULA') . " -> '$newExpireDate'. Update necessário.");
            }
            // Se o status NÃO for ACTIVE, verifica se a data de expiração precisa ser limpa (definida como NULL)
            elseif ($newLocalStatus !== 'ACTIVE' && $currentExpireDate !== null) {
                $needsUpdate = true;
                logMessage("    -> Limpeza da Data de Expiração necessária (status '$newLocalStatus'). Update necessário.");
            }

            // Executa o UPDATE apenas se necessário
            if ($needsUpdate) {
                $sqlUpdate = "UPDATE saas_users SET
                                subscription_status = :local_status,
                                is_saas_active = :is_active,
                                subscription_expires_at = :expires, -- Será definido como data ou NULL
                                updated_at = NOW()
                              WHERE asaas_subscription_id = :sub_id";

                $params = [
                    ':local_status' => $newLocalStatus,
                    ':sub_id' => $subscriptionId,
                    ':is_active' => ($newLocalStatus === 'ACTIVE'), // Define flag de atividade SaaS
                    // Define o valor para :expires
                    ':expires' => ($newLocalStatus === 'ACTIVE' && $newExpireDate !== null) ? $newExpireDate : null
                 ];

                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $success = $stmtUpdate->execute($params);

                if ($success) {
                    logMessage("    -> SUCESSO: Update DB para Status '$newLocalStatus' e Expiração '" . ($params[':expires'] ?: 'NULL') . "'.");
                    // Opcional: Limpar cache de sessão do usuário aqui, se houver
                } else {
                    $errorInfo = $stmtUpdate->errorInfo();
                    logMessage("    -> ERRO SQL ao executar Update: " . ($errorInfo[2] ?? 'N/A'));
                    // Considerar lançar exceção para rollback? Por ora, só loga.
                }
            } else {
                 logMessage("    -> Nenhuma atualização necessária no DB (status e data já corretos).");
            }
        } else {
            // Recebeu webhook para uma assinatura que não está em nenhum usuário local
            logMessage("  [WH v1.2] AVISO: Nenhum usuário local encontrado para Asaas Subscription ID $subscriptionId. Verificar vínculo no DB.");
        }
    } else {
        // O evento recebido não resultou em nenhuma mudança de status local planejada
        logMessage("  [WH v1.2] Evento $eventName não resultou em mudança de status local. Nenhuma ação no DB.");
    }

    $pdo->commit(); // Confirma a transação se tudo correu bem
    http_response_code(200); // Responde OK para o Asaas
    logMessage("==== [Asaas Webhook Receiver v1.2] Processamento concluído para evento: $eventName ====");
    exit;

} catch (\PDOException $e) { // Captura erros de Banco de Dados
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    logMessage("[Asaas Webhook v1.2] **** ERRO FATAL PDOException ****");
    logMessage("  Mensagem: {$e->getMessage()} | Evento: $eventName | Payload: " . substr($payload, 0, 500) . "...");
    http_response_code(500); // Erro interno do servidor
    exit;
} catch (\Throwable $e) { // Captura outros erros inesperados
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    logMessage("[Asaas Webhook v1.2] **** ERRO FATAL INESPERADO (Throwable) ****");
    logMessage("  Tipo: " . get_class($e) . " | Mensagem: {$e->getMessage()} | Arquivo: {$e->getFile()} | Linha: {$e->getLine()} | Evento: $eventName");
    http_response_code(500); // Erro interno do servidor
    exit;
}
?>