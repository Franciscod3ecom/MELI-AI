<?php
/**
 * Arquivo: dashboard.php
 * Versão: v7.5 - Verifica DB status se sessão não ativa, Exibe Status Assinatura.
 * Descrição: Painel de controle do usuário SaaS. Garante que o acesso só é permitido
 *            se a assinatura estiver ativa (verificando sessão e, se necessário, DB).
 *            Exibe o status da assinatura no cabeçalho e o link para Billing.
 *            Confirma ID da div#tab-historico.
 */

// --- Includes Essenciais ---
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage() (se existir no include path)
require_once __DIR__ . '/includes/helpers.php'; // Inclui getSubscriptionStatusClass() e getStatusTagClasses()

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];
$saasUserEmail = $_SESSION['saas_user_email'] ?? 'Usuário'; // Pega email da sessão (definido no login)

// *** Proteção de Assinatura Ativa (com verificação DB como fallback) ***
$subscriptionStatus = $_SESSION['subscription_status'] ?? null; // Pega status da sessão

// Se o status na SESSÃO não for explicitamente 'ACTIVE', verifica no DB
if ($subscriptionStatus !== 'ACTIVE') {
    $logMsg = "Dashboard v7.5: Sessão não ativa ($subscriptionStatus) para SaaS ID $saasUserId. Verificando DB...";
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
            $subscriptionStatus = 'ACTIVE'; // Atualiza a variável local para o resto do script
            $logMsg = "Dashboard v7.5: DB está ATIVO para SaaS ID $saasUserId. Sessão atualizada. Acesso permitido.";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
            // Permite que o script continue para carregar o dashboard
        } else {
            // Se o DB também confirma que não está ativo, redireciona para billing
            $logMsg = "Dashboard v7.5: DB também NÃO está ATIVO ($dbStatus) para SaaS ID $saasUserId. Redirecionando para billing.";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
            header('Location: billing.php?error=subscription_required'); // Informa o motivo
            exit;
        }
    } catch (\Exception $e) {
         // Em caso de erro ao verificar o DB, redireciona para billing por segurança
         $logMsg = "Dashboard v7.5: Erro CRÍTICO ao verificar DB status para $saasUserId: " . $e->getMessage() . ". Redirecionando para billing.";
         function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
         // Limpa status da sessão para evitar loops se o erro DB persistir
         unset($_SESSION['subscription_status']);
         header('Location: billing.php?error=db_check_failed'); // Informa erro na checagem
         exit;
    }
}
// *** FIM PROTEÇÃO ASSINATURA ***

// --- Se chegou aqui, a assinatura está ATIVA (confirmado via sessão ou DB) ---
logMessage("Dashboard v7.5: Acesso permitido para SaaS User ID $saasUserId (Status: $subscriptionStatus)");

// --- Inicialização de Variáveis do Dashboard ---
$mlConnection = null;          // Dados da conexão ML
$logsParaHistorico = [];     // Array para todos os logs (histórico completo)
$saasUserProfile = null;       // Dados do perfil do usuário SaaS
$currentDDDNumber = '';        // DDD + Número do WhatsApp (para preencher campo)
$dashboardMessage = null;      // Mensagens de feedback (ex: conexão ML, perfil salvo)
$dashboardMessageClass = ''; // Classe CSS para a mensagem de feedback
$isCurrentUserSuperAdmin = false; // Flag se o usuário é Super Admin

// --- Conexão DB e Busca de Dados ---
try {
    $pdo = getDbConnection();

    // 1. Buscar Dados do Perfil SaaS (Email, JID, Flag Super Admin)
    $stmtProfile = $pdo->prepare("SELECT email, whatsapp_jid, is_super_admin FROM saas_users WHERE id = :saas_user_id LIMIT 1");
    $stmtProfile->execute([':saas_user_id' => $saasUserId]);
    $saasUserProfile = $stmtProfile->fetch();

    // Define flag Super Admin e atualiza email na sessão se necessário
    if ($saasUserProfile && isset($saasUserProfile['is_super_admin']) && $saasUserProfile['is_super_admin']) {
        $isCurrentUserSuperAdmin = true;
    }
    if ($saasUserProfile && empty($saasUserEmail) && !empty($saasUserProfile['email'])) {
        $saasUserEmail = $saasUserProfile['email'];
        $_SESSION['saas_user_email'] = $saasUserEmail; // Atualiza sessão para consistência
    }

    // 2. Buscar Dados da Conexão Mercado Livre
    $stmtML = $pdo->prepare("SELECT id, ml_user_id, is_active, updated_at FROM mercadolibre_users WHERE saas_user_id = :saas_user_id LIMIT 1");
    $stmtML->execute([':saas_user_id' => $saasUserId]);
    $mlConnection = $stmtML->fetch();

    // 3. Buscar Histórico de Logs de Processamento de Perguntas
    $logLimit = 150; // Define quantos logs buscar para o histórico
    $logStmtHist = $pdo->prepare(
        "SELECT ml_question_id, item_id, question_text, status, ia_response_text, error_message, sent_to_whatsapp_at, ai_answered_at, human_answered_at, last_processed_at
         FROM question_processing_log
         WHERE saas_user_id = :saas_user_id
         ORDER BY last_processed_at DESC
         LIMIT :limit"
    );
    $logStmtHist->bindParam(':saas_user_id', $saasUserId, PDO::PARAM_INT);
    $logStmtHist->bindParam(':limit', $logLimit, PDO::PARAM_INT);
    $logStmtHist->execute();
    $logsParaHistorico = $logStmtHist->fetchAll();

    // 4. Extrair DDD + Número do JID (para preencher campo no perfil)
    if ($saasUserProfile && !empty($saasUserProfile['whatsapp_jid'])) {
        if (preg_match('/^55(\d{10,11})@s\.whatsapp\.net$/', $saasUserProfile['whatsapp_jid'], $matches)) {
            $currentDDDNumber = $matches[1]; // Captura DDD+Número
        }
    }

} catch (\PDOException | \Exception $e) {
    $logMsg = "Erro DB/Geral Dashboard v7.5 (SaaS User ID $saasUserId): " . $e->getMessage();
    function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
    // Define uma mensagem de erro para exibir no dashboard
    $dashboardMessage = ['type' => 'is-danger is-light', 'text' => '⚠️ Erro ao carregar dados do dashboard. Algumas informações podem não estar disponíveis.'];
}

// --- Trata mensagens de status vindas da URL (igual anterior) ---
$message_classes = [
    'is-success' => 'bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300',
    'is-info is-light' => 'bg-blue-100 dark:bg-blue-900 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300',
    'is-danger is-light' => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
    'is-warning is-light' => 'bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300',
    // Adicione outros mapeamentos se necessário
];

if (isset($_GET['status'])) {
    $status = $_GET['status'];
    if ($status === 'ml_connected') { $dashboardMessage = ['type' => 'is-success', 'text' => '✅ Conta Mercado Livre conectada/atualizada com sucesso!']; }
    elseif ($status === 'ml_error') { $code = $_GET['code'] ?? 'unknown'; $dashboardMessage = ['type' => 'is-danger is-light', 'text' => "❌ Erro ao conectar com Mercado Livre (Código: $code). Tente novamente."]; }
} elseif (isset($_GET['profile_status'])) {
    $p_status = $_GET['profile_status'];
    if ($p_status === 'updated') { $dashboardMessage = ['type' => 'is-success', 'text' => '✅ Perfil atualizado com sucesso!']; }
    elseif ($p_status === 'error') { $code = $_GET['code'] ?? 'generic'; $dashboardMessage = ['type' => 'is-danger is-light', 'text' => "❌ Erro ao atualizar perfil (Código: $code). Verifique os dados e tente novamente."]; }
}

// Define a classe CSS da mensagem se houver uma
if ($dashboardMessage && isset($message_classes[$dashboardMessage['type']])) {
    $dashboardMessageClass = $message_classes[$dashboardMessage['type']];
}
// Limpa os parâmetros da URL para não persistirem
if (isset($_GET['status']) || isset($_GET['profile_status'])){
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname + window.location.hash); }, 1); } </script>";
}

// (A função getStatusTagClasses agora está em helpers.php e é incluída no início)

?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <!-- Adicione outros links CSS ou JS aqui se necessário -->
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col transition-colors duration-300">
    <section class="main-content container mx-auto px-4 py-8">
        <!-- Cabeçalho -->
        <header class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-6">
            <div class="flex justify-between items-center flex-wrap gap-y-2">
                <h1 class="text-xl font-semibold">🤖 Meli AI</h1>
                <div class="flex items-center space-x-3 sm:space-x-4">
                    <span class="text-sm text-gray-600 dark:text-gray-400 hidden sm:inline" title="Usuário Logado">Olá, <?php echo htmlspecialchars($saasUserEmail); ?></span>
                    <!-- Exibição do Status da Assinatura -->
                    <span class="<?php echo getSubscriptionStatusClass($subscriptionStatus); // Usa helper ?> text-xs !px-2 !py-0.5" title="Status da Assinatura">
                        <?php echo htmlspecialchars(ucfirst(strtolower($subscriptionStatus ?? 'N/A'))); ?>
                    </span>
                    <!-- Link para Gerenciar Assinatura -->
                    <a href="billing.php" class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1" title="Gerenciar Assinatura">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 21.75Z" /></svg>
                        <span class="hidden sm:inline">Assinatura</span>
                    </a>
                    <!-- Link Admin (Condicional) -->
                    <?php if ($isCurrentUserSuperAdmin): ?>
                        <a href="super_admin.php" class="text-sm font-medium text-purple-600 hover:text-purple-800 dark:text-purple-400 dark:hover:text-purple-300 flex items-center gap-1" title="Painel Super Admin">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                            <span class="hidden sm:inline">Admin</span>
                        </a>
                    <?php endif; ?>
                    <!-- Botão Sair -->
                    <a href="logout.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 dark:focus:ring-offset-gray-800">
                       <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1 hidden sm:inline"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
                        Sair
                    </a>
                </div>
            </div>
        </header>

        <!-- Mensagem de Status Global -->
        <?php if ($dashboardMessage && $dashboardMessageClass): ?>
            <div id="dashboard-message" class="<?php echo htmlspecialchars($dashboardMessageClass); ?> px-4 py-3 rounded-md text-sm mb-6 flex justify-between items-center" role="alert">
                <span><?php echo htmlspecialchars($dashboardMessage['text']); ?></span>
                <button onclick="document.getElementById('dashboard-message').style.display='none';" class="ml-4 -mr-1 p-1 rounded-md focus:outline-none focus:ring-2 focus:ring-current hover:bg-opacity-20 hover:bg-current" aria-label="Fechar">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        <?php endif; ?>

        <!-- Abas de Navegação -->
        <div class="mb-6">
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav id="dashboard-tabs" class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">
                    <a href="#conexao" data-tab="conexao" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                        <span>Conexão</span>
                    </a>
                    <a href="#atividade" data-tab="atividade" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <span>Atividade</span>
                    </a>
                    <a href="#historico" data-tab="historico" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" /></svg>
                        <span>Histórico</span>
                    </a>
                    <a href="#perfil" data-tab="perfil" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527c.47-.336 1.06-.336 1.53 0l.772.55c.47.336.699.93.55 1.452l-.298 1.043c-.16.562-.16.948 0 1.51l.298 1.043c.149.521-.08.1.116-.55 1.452l-.772.55c-.47.336-1.06.336-1.53 0l-.737-.527c-.35-.25-.807-.272-1.205-.108-.396.165-.71.506-.78.93l-.149.894c-.09.542-.56.94-1.11.94h-1.093c-.55 0-1.02-.398-1.11-.94l-.149-.894c-.07-.424-.384-.764-.78-.93-.398-.164-.855-.142-1.205.108l-.737.527c-.47.336-1.06.336-1.53 0l-.772-.55c-.47-.336-.699-.93-.55-1.452l.298-1.043c.16-.562.16-.948 0-1.51l-.298-1.043c-.149-.521.08-1.116.55-1.452l.772-.55c.47-.336 1.06-.336 1.53 0l.737.527c.35.25.807.272 1.205.108.396-.165.71-.506.78-.93l.149-.894Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <span>Perfil</span>
                    </a>
                </nav>
            </div>
        </div>

        <!-- Container Conteúdo das Abas -->
        <div class="space-y-6">
             <!-- Aba Conexão -->
             <div id="tab-conexao" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-4">🔗 Conexão Mercado Livre</h2>
                 <?php if ($mlConnection): ?>
                     <div class="space-y-3 mb-4">
                         <div><span class="text-sm font-medium text-gray-600 dark:text-gray-400">Status:</span> <span class="ml-2 inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100">✅ Conectada</span></div>
                         <div><span class="text-sm font-medium text-gray-600 dark:text-gray-400">ID Vendedor ML:</span> <span class="ml-2 text-sm font-semibold text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($mlConnection['ml_user_id']); ?></span></div>
                         <div><span class="text-sm font-medium text-gray-600 dark:text-gray-400">Automação:</span> <span class="ml-2 inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium <?php echo $mlConnection['is_active'] ? 'bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100'; ?>"><?php echo $mlConnection['is_active'] ? 'Ativa' : 'Inativa'; ?></span></div>
                     </div>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Última atualização da conexão: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($mlConnection['updated_at']))); ?></p>
                     <div class="flex space-x-3">
                         <a href="oauth_start.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                            🔄 Reconectar / Atualizar Permissões
                         </a>
                         <!-- Poderia adicionar botão para DESCONECTAR (desativar is_active e talvez limpar tokens) -->
                     </div>
                 <?php else: ?>
                     <div class="flex items-center space-x-2 mb-3">
                         <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Status:</span>
                         <span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100">❌ Não Conectada</span>
                     </div>
                     <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                         Para começar a usar o Meli AI, conecte sua conta do Mercado Livre.
                     </p>
                     <a href="oauth_start.php" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                        🔗 Conectar Conta Mercado Livre
                     </a>
                 <?php endif; ?>
             </div>

             <!-- Aba Atividade Recente -->
             <div id="tab-atividade" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-4">⏱️ Atividade Recente (Últimos 30 Logs)</h2>
                 <?php $recentLogs = array_slice($logsParaHistorico, 0, 30); ?>
                 <?php if (empty($recentLogs)): ?>
                     <p class="text-center text-gray-500 dark:text-gray-400 py-10 text-sm">Nenhuma atividade recente registrada.</p>
                 <?php else: ?>
                     <div class="log-container custom-scrollbar border border-gray-200 dark:border-gray-700 rounded-lg max-h-[60vh] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
                         <?php foreach ($recentLogs as $log): ?>
                             <div class="log-entry px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                 <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-1"> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">P: <?php echo htmlspecialchars($log['ml_question_id']); ?></span> <span class="text-sm text-gray-600 dark:text-gray-400">Item: <?php echo htmlspecialchars($log['item_id']); ?></span> <span class="<?php echo getStatusTagClasses($log['status']); ?>" title="Status"><?php echo htmlspecialchars(str_replace('_', ' ', $log['status'])); ?></span> </div>
                                 <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1"> <?php if (!empty($log['sent_to_whatsapp_at'])): ?> <span title="Notif Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['sent_to_whatsapp_at']))); ?>">🔔 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['sent_to_whatsapp_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['human_answered_at'])): ?> <span title="Resp Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['human_answered_at']))); ?>">✍️ <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['human_answered_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['ai_answered_at'])): ?> <span title="Resp IA: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['ai_answered_at']))); ?>">🤖 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['ai_answered_at']))); ?></span> <?php endif; ?> </div>
                                 <?php if (!empty($log['question_text'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Pergunta <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-300 max-h-40 overflow-y-auto whitespace-pre-wrap break-words"><code><?php echo htmlspecialchars($log['question_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['ia_response_text']) && in_array(strtoupper($log['status']), ['AI_ANSWERED', 'AI_FAILED', 'AI_PROCESSING', 'AI_TRIGGERED_BY_TEXT'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Resposta IA <?php if (strtoupper($log['status']) == 'AI_ANSWERED') echo '(Enviada)'; elseif (strtoupper($log['status']) == 'AI_FAILED') echo '(Inválida/Falhou)'; else echo '(Gerada/Tentada)'; ?> <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 border rounded text-xs max-h-40 overflow-y-auto whitespace-pre-wrap break-words <?php echo strtoupper($log['status']) == 'AI_ANSWERED' ? 'bg-green-50 dark:bg-green-900/50 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300'; ?>"><code><?php echo htmlspecialchars($log['ia_response_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['error_message'])): ?><p class="text-red-600 dark:text-red-400 text-xs mt-1"><strong>Erro:</strong> <?php echo htmlspecialchars($log['error_message']); ?></p><?php endif; ?>
                                 <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-right">Última Atualização: <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['last_processed_at']))); ?></p>
                             </div>
                         <?php endforeach; ?>
                     </div>
                 <?php endif; ?>
             </div>

             <!-- Aba Histórico -->
             <div id="tab-historico" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-4">📜 Histórico Completo (Últimos <?php echo $logLimit; ?> Logs)</h2>
                 <?php if (empty($logsParaHistorico)): ?>
                     <p class="text-center text-gray-500 dark:text-gray-400 py-10 text-sm">Nenhum histórico encontrado para este usuário.</p>
                 <?php else: ?>
                      <div class="log-container custom-scrollbar border border-gray-200 dark:border-gray-700 rounded-lg max-h-[70vh] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
                         <?php foreach ($logsParaHistorico as $log): ?>
                             <div class="log-entry px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                 <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-1"> <span class="text-sm font-medium text-gray-800 dark:text-gray-200">P: <?php echo htmlspecialchars($log['ml_question_id']); ?></span> <span class="text-sm text-gray-600 dark:text-gray-400">Item: <?php echo htmlspecialchars($log['item_id']); ?></span> <span class="<?php echo getStatusTagClasses($log['status']); ?>" title="Status"><?php echo htmlspecialchars(str_replace('_', ' ', $log['status'])); ?></span> </div>
                                 <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1"> <?php if (!empty($log['sent_to_whatsapp_at'])): ?> <span title="Notif Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['sent_to_whatsapp_at']))); ?>">🔔 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['sent_to_whatsapp_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['human_answered_at'])): ?> <span title="Resp Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['human_answered_at']))); ?>">✍️ <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['human_answered_at']))); ?></span> <?php endif; ?> <?php if (!empty($log['ai_answered_at'])): ?> <span title="Resp IA: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['ai_answered_at']))); ?>">🤖 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['ai_answered_at']))); ?></span> <?php endif; ?> </div>
                                 <?php if (!empty($log['question_text'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Pergunta <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-300 max-h-40 overflow-y-auto whitespace-pre-wrap break-words"><code><?php echo htmlspecialchars($log['question_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['ia_response_text']) && in_array(strtoupper($log['status']), ['AI_ANSWERED', 'AI_FAILED', 'AI_PROCESSING', 'AI_TRIGGERED_BY_TEXT'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Resposta IA <?php if (strtoupper($log['status']) == 'AI_ANSWERED') echo '(Enviada)'; elseif (strtoupper($log['status']) == 'AI_FAILED') echo '(Inválida/Falhou)'; else echo '(Gerada/Tentada)'; ?> <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 border rounded text-xs max-h-40 overflow-y-auto whitespace-pre-wrap break-words <?php echo strtoupper($log['status']) == 'AI_ANSWERED' ? 'bg-green-50 dark:bg-green-900/50 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300'; ?>"><code><?php echo htmlspecialchars($log['ia_response_text']); ?></code></pre></details><?php endif; ?>
                                 <?php if (!empty($log['error_message'])): ?><p class="text-red-600 dark:text-red-400 text-xs mt-1"><strong>Erro:</strong> <?php echo htmlspecialchars($log['error_message']); ?></p><?php endif; ?>
                                 <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-right">Última Atualização: <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['last_processed_at']))); ?></p>
                             </div>
                         <?php endforeach; ?>
                     </div>
                 <?php endif; ?>
             </div>

             <!-- Aba Perfil -->
            <div id="tab-perfil" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                 <h2 class="text-lg font-semibold mb-6">⚙️ Meu Perfil</h2>
                 <?php if ($saasUserProfile): ?>
                     <form action="update_profile.php" method="POST" class="space-y-6">
                         <div>
                             <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">📧 E-mail</label>
                             <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed"
                                    type="email" id="email" value="<?php echo htmlspecialchars($saasUserProfile['email']); ?>" readonly disabled>
                             <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Seu e-mail de login (não pode ser alterado).</p>
                         </div>
                         <div>
                             <label for="whatsapp_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300">📱 WhatsApp (Notificações)</label>
                             <input class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                    type="tel" id="whatsapp_number" name="whatsapp_number"
                                    value="<?php echo htmlspecialchars($currentDDDNumber); ?>" placeholder="Ex: 11987654321" pattern="\d{10,11}" title="DDD + Número (10 ou 11 dígitos)">
                             <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">DDD + Número (sem espaços/traços). Usado para receber notificações.</p>
                         </div>
                         <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                             <h3 class="text-base font-medium text-gray-900 dark:text-white">🔑 Alterar Senha (Opcional)</h3>
                             <div class="mt-4 space-y-4">
                                 <div><label for="current_password" class="sr-only">Senha Atual</label><input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white" type="password" id="current_password" name="current_password" placeholder="Senha Atual" autocomplete="current-password"></div>
                                 <div><label for="new_password" class="sr-only">Nova Senha</label><input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white" type="password" id="new_password" name="new_password" placeholder="Nova Senha (mín. 8 caracteres)" minlength="8" autocomplete="new-password"></div>
                                 <div><label for="confirm_password" class="sr-only">Confirmar Nova Senha</label><input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white" type="password" id="confirm_password" name="confirm_password" placeholder="Confirmar Nova Senha" autocomplete="new-password"></div>
                             </div>
                             <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Preencha os três campos apenas se desejar alterar sua senha.</p>
                         </div>
                         <div class="flex justify-end pt-4">
                             <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                 💾 Salvar Alterações
                             </button>
                         </div>
                     </form>
                 <?php else: ?>
                     <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert"><p class="font-bold">Erro</p><p>Não foi possível carregar dados do perfil.</p></div>
                 <?php endif; ?>
             </div> <!-- Fim #tab-perfil -->
        </div> <!-- Fim container conteúdo abas -->
    </section>

     <footer class="py-6 text-center">
         <p class="text-sm text-gray-500 dark:text-gray-400">
             <strong>Meli AI</strong> © <?php echo date('Y'); ?>
         </p>
     </footer>

    <!-- Script Javascript para controle das Abas -->
    <script>
         document.addEventListener('DOMContentLoaded', () => {
             const tabs = document.querySelectorAll('#dashboard-tabs a[data-tab]');
             const tabContents = document.querySelectorAll('.tab-content'); // Seleciona todas as divs de conteúdo
             const activeTabClasses = ['text-blue-600', 'dark:text-blue-400', 'border-blue-500'];
             const inactiveTabClasses = ['text-gray-500', 'dark:text-gray-400', 'hover:text-gray-700', 'dark:hover:text-gray-200', 'hover:border-gray-300', 'dark:hover:border-gray-500', 'border-transparent'];

             function switchTab(targetTabId) {
                 // Atualiza estilos das abas e aria-selected
                 tabs.forEach(tab => {
                     const isTarget = tab.getAttribute('data-tab') === targetTabId;
                     tab.classList.toggle(...activeTabClasses, isTarget);
                     tab.classList.toggle(...inactiveTabClasses, !isTarget);
                     tab.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                 });

                 // Mostra/Esconde conteúdo das abas
                 tabContents.forEach(content => {
                     // Verifica se o ID do conteúdo corresponde ao ID da aba clicada
                     if(content.id === `tab-${targetTabId}`) {
                         content.classList.remove('hidden');
                     } else {
                         content.classList.add('hidden');
                     }
                 });

                 // Atualiza URL hash (opcional, mas bom para navegação)
                 // Usando setTimeout 0 para garantir que a atualização da UI ocorra antes do pushState
                 if (history.pushState) {
                     setTimeout(() => history.pushState(null, null, '#' + targetTabId), 0);
                 } else {
                     // Fallback para navegadores mais antigos
                     window.location.hash = '#' + targetTabId;
                 }
             }

             // Adiciona event listener para cada aba
             tabs.forEach(tab => {
                 tab.addEventListener('click', (event) => {
                     event.preventDefault(); // Previne o comportamento padrão do link
                     const tabId = tab.getAttribute('data-tab');
                     if (tabId) {
                         switchTab(tabId);
                     }
                 });
             });

             // Define a aba ativa inicial (baseado no hash da URL ou padrão 'conexao')
             let activeTabId = 'conexao'; // Aba padrão
             if (window.location.hash) {
                 const hash = window.location.hash.substring(1); // Remove o '#'
                 // Verifica se existe uma aba correspondente ao hash
                 const requestedTab = document.querySelector(`#dashboard-tabs a[data-tab="${hash}"]`);
                 if (requestedTab) {
                     activeTabId = hash;
                 }
             }
             // Ativa a aba inicial
             switchTab(activeTabId);
         });
    </script>
</body>
</html>