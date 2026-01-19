<?php
/**
 * Arquivo: go_to_asaas_payment.php
 * Versão: v1.1 - Implementa busca de link para assinaturas existentes PENDENTE/OVERDUE.
 * Descrição: Se assinatura não existe, cria no Asaas e redireciona para pagamento.
 *            Se assinatura existe e está PENDENTE ou OVERDUE, tenta buscar o link
 *            da fatura existente no Asaas e redireciona para ele.
 *            Caso contrário (status existente não pagável), redireciona para billing.php.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Contém session_start()
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/log_helper.php';
require_once __DIR__ . '/includes/asaas_api.php'; // Agora inclui getAsaasPendingPaymentLink

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    // Se não estiver logado, redireciona para o login
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];
logMessage("[GoToAsaas v1.1] Iniciando processo para SaaS User ID: $saasUserId");

// --- Buscar Asaas Customer ID e verificar Assinatura localmente ---
$asaasCustomerId = null;
$currentSubscriptionId = null;
$currentStatus = null; // Status local da assinatura
$pdo = null; // Inicializa PDO

try {
    $pdo = getDbConnection();
    logMessage("[GoToAsaas v1.1] Buscando dados Asaas do usuário $saasUserId no DB local...");
    // Busca dados relevantes do usuário
    $stmtUser = $pdo->prepare("SELECT asaas_customer_id, asaas_subscription_id, subscription_status FROM saas_users WHERE id = :id");
    $stmtUser->execute([':id' => $saasUserId]);
    $userData = $stmtUser->fetch();

    // Validações essenciais dos dados do usuário
    if (!$userData) {
        logMessage("[GoToAsaas v1.1] ERRO CRÍTICO: Usuário SaaS $saasUserId não encontrado no DB local.");
        header('Location: billing.php?billing_status=db_error&code=user_not_found');
        exit;
    }
    if (empty($userData['asaas_customer_id'])) {
         logMessage("[GoToAsaas v1.1] ERRO CRÍTICO: Usuário SaaS $saasUserId sem Asaas Customer ID.");
         // Isso indica um problema no cadastro ou fluxo anterior
         header('Location: billing.php?billing_status=asaas_error&code=no_customer_id');
         exit;
    }

    $asaasCustomerId = $userData['asaas_customer_id'];
    $currentSubscriptionId = $userData['asaas_subscription_id']; // Pode ser NULL
    $currentStatus = $userData['subscription_status'];           // Status local (PENDING, OVERDUE, etc.)
    logMessage("[GoToAsaas v1.1] Dados encontrados: CustID=$asaasCustomerId, SubID Local=" . ($currentSubscriptionId ?: 'NENHUM') . ", StatusLocal=$currentStatus");

    // --- Lógica Principal ---

    // Cenário 1: JÁ EXISTE uma assinatura Asaas registrada localmente
    if (!empty($currentSubscriptionId)) {
        logMessage("[GoToAsaas v1.1] Assinatura Asaas ID $currentSubscriptionId já existe localmente.");

        // Verifica se o status local permite tentar buscar um link de pagamento existente
        if ($currentStatus === 'PENDING' || $currentStatus === 'OVERDUE') {
            logMessage("  -> Status local é '$currentStatus'. Tentando buscar link de pagamento existente no Asaas...");

            // Chama a nova função para buscar o link da fatura PENDENTE ou OVERDUE
            $paymentUrl = getAsaasPendingPaymentLink($currentSubscriptionId);

            if ($paymentUrl) {
                // Encontrou o link da fatura existente! Redireciona para ele.
                logMessage("  -> Link da fatura existente encontrado: $paymentUrl. Redirecionando usuário...");
                // --- PONTO DE REDIRECIONAMENTO PARA FATURA EXISTENTE ---
                header('Location: ' . $paymentUrl);
                exit; // Garante a finalização do script
            } else {
                // Não encontrou link (pode ser erro na API Asaas ou não há fatura pendente/vencida lá)
                logMessage("  -> ERRO/AVISO: Não foi possível obter link de pagamento para a assinatura existente $currentSubscriptionId (Status Local: $currentStatus). Ver logs asaas_api. Redirecionando para billing.");
                // --- PONTO DE REDIRECIONAMENTO DE VOLTA (FALHA NA BUSCA) ---
                header('Location: billing.php?billing_status=link_error&reason=existing_not_found');
                exit; // Garante a finalização do script
            }
        } else {
            // O status local é diferente de PENDING/OVERDUE (ex: ACTIVE, CANCELED). Não há o que pagar.
            logMessage("  -> Status local é '$currentStatus', não é PENDENTE/OVERDUE. Redirecionando de volta para billing.php para exibir status.");
            // --- PONTO DE REDIRECIONAMENTO DE VOLTA (STATUS NÃO PAGÁVEL) ---
            header('Location: billing.php');
            exit; // Garante a finalização do script
        }
    }
    // Cenário 2: NÃO existe ID de assinatura local -> Tenta CRIAR uma nova
    else {
         logMessage("[GoToAsaas v1.1] Nenhuma assinatura Asaas registrada localmente. Tentando criar uma nova...");

         // Chama a função para criar a assinatura e obter link/dados da 1a cobrança
         $subscriptionData = createAsaasSubscriptionRedirect($asaasCustomerId, (string) $saasUserId);


         if ($subscriptionData && isset($subscriptionData['id'])) {
             $newSubscriptionId = $subscriptionData['id'];
             $paymentUrl = $subscriptionData['paymentLink'] ?? null; // Link da 1a cobrança

             logMessage("[GoToAsaas v1.1] Nova assinatura Asaas criada (ID: $newSubscriptionId). Link Pagamento: " . ($paymentUrl ?: 'NÃO OBTIDO'));

             // Atualiza DB local com o novo ID
             logMessage("[GoToAsaas v1.1] Atualizando DB local (SaaS ID $saasUserId) com Sub ID: $newSubscriptionId...");
             $stmtUpdate = $pdo->prepare("UPDATE saas_users SET asaas_subscription_id = :sub_id, updated_at = NOW() WHERE id = :saas_id");
             $updateSuccess = $stmtUpdate->execute([':sub_id' => $newSubscriptionId, ':saas_id' => $saasUserId]);

             if ($updateSuccess) {
                 logMessage("[GoToAsaas v1.1] DB local atualizado.");
                 $_SESSION['asaas_subscription_id'] = $newSubscriptionId; // Opcional: Atualiza sessão
             } else {
                  logMessage("[GoToAsaas v1.1] ERRO ao atualizar DB local com novo Sub ID $newSubscriptionId (usuário $saasUserId).");
             }

             // Redireciona para pagamento se obteve o link
             if ($paymentUrl) {
                 logMessage("[GoToAsaas v1.1] Redirecionando usuário para URL de pagamento da nova assinatura: $paymentUrl");
                 // --- PONTO DE REDIRECIONAMENTO PARA NOVA FATURA ---
                 header('Location: ' . $paymentUrl);
                 exit; // Garante a finalização do script
             } else {
                 // Assinatura criada, mas não conseguiu link
                 logMessage("[GoToAsaas v1.1] ERRO: Nova assinatura $newSubscriptionId criada, mas URL de pagamento não obtida. Redirecionando para billing com erro.");
                 // --- PONTO DE REDIRECIONAMENTO DE VOLTA (FALHA LINK NOVA FATURA) ---
                 header('Location: billing.php?billing_status=link_error&reason=new_sub_no_link');
                 exit; // Garante a finalização do script
             }
         } else {
             // Falha ao criar assinatura na API Asaas
             logMessage("[GoToAsaas v1.1] ERRO CRÍTICO: Falha ao chamar createAsaasSubscriptionRedirect para Customer ID $asaasCustomerId.");
              // --- PONTO DE REDIRECIONAMENTO DE VOLTA (FALHA CRIAÇÃO ASSINATURA) ---
             header('Location: billing.php?billing_status=asaas_error&code=sub_create_failed');
             exit; // Garante a finalização do script
         }
    } // Fim Cenário 2

} catch (\PDOException $e) {
    logMessage("[GoToAsaas v1.1] Erro CRÍTICO DB (SaaS ID $saasUserId): " . $e->getMessage());
    // --- PONTO DE REDIRECIONAMENTO DE VOLTA (ERRO DB) ---
    header('Location: billing.php?billing_status=db_error');
    exit; // Garante a finalização do script
} catch (\Throwable $e) { // Captura outros erros (ex: API Asaas, lógica)
    logMessage("[GoToAsaas v1.1] Erro CRÍTICO Geral (SaaS ID $saasUserId): " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
     // --- PONTO DE REDIRECIONAMENTO DE VOLTA (ERRO GERAL) ---
    header('Location: billing.php?billing_status=internal_error');
    exit; // Garante a finalização do script
}

// Código de fallback caso algo muito estranho aconteça
logMessage("[GoToAsaas v1.1] AVISO: Script terminou inesperadamente sem redirecionamento (SaaS ID $saasUserId).");
echo "Ocorreu um erro inesperado no servidor ao processar sua solicitação. Por favor, <a href='billing.php'>clique aqui para voltar</a> ou contate o suporte.";
exit; // Garante que nada mais seja impresso
?>