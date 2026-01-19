<?php
/**
 * Arquivo: oauth_start.php
 * Versão: v1.3 - Adiciona verificação de assinatura ativa no DB.
 * Descrição: Inicia o fluxo de autorização OAuth2 do Mercado Livre.
 *            Verifica se o usuário tem assinatura ativa (sessão ou DB) antes de permitir.
 *            Redireciona para a página de autorização do ML se a assinatura estiver ativa.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Necessário para verificar DB
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Proteção: Exige Login SaaS ---
if (!isset($_SESSION['saas_user_id'])) {
    logMessage("OAuth Start v1.3: Tentativa de acesso não autorizado (sem sessão SaaS). Redirecionando para login.");
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];

// *** Proteção de Assinatura Ativa (com verificação DB como fallback) ***
$subscriptionStatus = $_SESSION['subscription_status'] ?? null; // Pega status da sessão

// Se o status na SESSÃO não for explicitamente 'ACTIVE', verifica no DB
if ($subscriptionStatus !== 'ACTIVE') {
    $logMsg = "OAuth Start v1.3: Sessão não ativa ($subscriptionStatus) para SaaS ID $saasUserId. Verificando DB...";
    function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);

    try {
        $pdoCheck = getDbConnection();
        // Consulta apenas o status da assinatura no DB
        $stmtCheck = $pdoCheck->prepare("SELECT subscription_status FROM saas_users WHERE id = :id");
        $stmtCheck->execute([':id' => $saasUserId]);
        $dbStatusData = $stmtCheck->fetch();
        // Assume INACTIVE se usuário não for encontrado ou status for NULL/vazio no DB
        $dbStatus = $dbStatusData['subscription_status'] ?? 'INACTIVE';

        // Se o status no DB for ATIVO, atualiza a sessão e permite o acesso
        if ($dbStatus === 'ACTIVE') {
            $_SESSION['subscription_status'] = 'ACTIVE'; // Corrige a sessão
            $subscriptionStatus = 'ACTIVE'; // Atualiza a variável local
             $logMsg = "OAuth Start v1.3: DB está ATIVO para SaaS ID $saasUserId. Sessão atualizada. Prosseguindo com OAuth...";
             function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
             // Permite que o script continue para gerar o link OAuth
        } else {
            // Se o DB também confirma que não está ativo, redireciona para billing
            $logMsg = "OAuth Start v1.3: Assinatura NÃO está ATIVA no DB ($dbStatus) para SaaS ID $saasUserId. Redirecionando para billing.";
             function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
             // Redireciona para billing informando que a assinatura está inativa
            header('Location: billing.php?billing_status=inactive');
            exit;
        }
    } catch (\Exception $e) {
         // Em caso de erro ao verificar o DB, redireciona para billing por segurança
         $logMsg = "OAuth Start v1.3: Erro CRÍTICO ao verificar DB status para $saasUserId: " . $e->getMessage() . ". Redirecionando para billing.";
         function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
         // Limpa status da sessão para evitar loops se o erro DB persistir
         unset($_SESSION['subscription_status']);
         header('Location: billing.php?error=db_check_failed'); // Informa erro na checagem
         exit;
    }
}
// *** FIM PROTEÇÃO ASSINATURA ***

// --- Se chegou aqui, está logado E assinatura está ATIVA ---
logMessage("[OAuth Start v1.3] Iniciando fluxo OAuth2 para SaaS User ID: $saasUserId (Assinatura Ativa)");

// --- Gerar o parâmetro 'state' para segurança (CSRF Protection) ---
// O state contém um valor aleatório (nonce), o ID do usuário SaaS e um timestamp
// É codificado para ser passado na URL
try {
    $statePayload = [
        'nonce' => bin2hex(random_bytes(16)), // String aleatória forte
        'uid'   => $saasUserId,               // ID do usuário logado
        'ts'    => time()                     // Timestamp da geração
    ];
    // Codifica o payload como JSON e depois em Base64 para segurança na URL
    $state = base64_encode(json_encode($statePayload));
} catch (Exception $e) {
     // Erro na geração de bytes aleatórios (raro, mas possível)
     logMessage("OAuth Start v1.3: ERRO CRÍTICO ao gerar state para SaaS User ID $saasUserId: " . $e->getMessage());
     // Redireciona de volta para o dashboard com mensagem de erro
     header('Location: dashboard.php?status=ml_error&code=state_gen_failed#conexao');
     exit;
}

// Armazena o state gerado na sessão para comparar no callback
$_SESSION['oauth_state_expected'] = $state;
logMessage("[OAuth Start v1.3] State CSRF gerado e salvo na sessão para SaaS User ID: $saasUserId");

// --- Montar a URL de autorização do Mercado Livre ---
// Verifica se as constantes de configuração do ML estão definidas
if (!defined('ML_APP_ID') || !defined('ML_REDIRECT_URI') || !defined('ML_AUTH_URL')) {
     logMessage("OAuth Start v1.3: ERRO CRÍTICO - Constantes ML (ML_APP_ID, ML_REDIRECT_URI, ML_AUTH_URL) não definidas em config.php.");
     header('Location: dashboard.php?status=ml_error&code=config_error#conexao');
     exit;
}

// Parâmetros para a URL de autorização
$authParams = [
    'response_type' => 'code',          // Tipo de fluxo OAuth2 (Authorization Code)
    'client_id'     => ML_APP_ID,       // ID da sua aplicação no ML
    'redirect_uri'  => ML_REDIRECT_URI, // URI para onde o ML redirecionará após autorização
    'state'         => $state,          // Parâmetro de segurança CSRF
    'scope'         => 'read write offline_access' // Escopos solicitados (ler/escrever dados, obter refresh token)
];

// Constrói a URL final
$authUrl = ML_AUTH_URL . '?' . http_build_query($authParams);

// --- Redirecionar o Usuário ---
logMessage("[OAuth Start v1.3] Redirecionando SaaS User ID $saasUserId para URL de Autorização ML: " . ML_AUTH_URL . "..."); // Loga sem os parâmetros sensíveis completos
header('Location: ' . $authUrl);
exit; // Finaliza o script após o redirecionamento
?>