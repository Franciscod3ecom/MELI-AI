<?php
/**
 * Arquivo: super_admin.php
 * Versão: v1.4 - Confirma ID da div do histórico
 * Descrição: Painel de Super Administrador com gerenciamento de usuários,
 *            visualização de conexões ML, logs globais e informações de assinatura Asaas.
 */

// --- Includes Essenciais ---
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()
require_once __DIR__ . '/includes/helpers.php'; // Para getSubscriptionStatusClass() e getStatusTagClasses()

// --- Proteção: Exige Login e Privilégio de Super Admin ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$loggedInSaasUserId = $_SESSION['saas_user_id'];
$isSuperAdmin = false;
$pdo = null;
$loggedInSaasUserEmail = 'Admin'; // Valor padrão

try {
    $pdo = getDbConnection();
    // Verifica se o usuário logado tem a flag is_super_admin
    $stmtAdmin = $pdo->prepare("SELECT is_super_admin, email FROM saas_users WHERE id = :id LIMIT 1");
    $stmtAdmin->execute([':id' => $loggedInSaasUserId]);
    $adminData = $stmtAdmin->fetch();

    // Se não encontrou o usuário ou ele não é super admin, redireciona
    if (!$adminData || !$adminData['is_super_admin']) {
        logMessage("ALERTA: Tentativa acesso super_admin.php por NÃO Super Admin ID: $loggedInSaasUserId");
        header('Location: dashboard.php'); // Redireciona para o dashboard normal
        exit;
    }
    $isSuperAdmin = true;
    $loggedInSaasUserEmail = $adminData['email'] ?? $loggedInSaasUserEmail; // Pega o email do admin
    logMessage("Acesso Super Admin concedido para SaaS User ID: $loggedInSaasUserId ($loggedInSaasUserEmail)");

} catch (\PDOException | \Exception $e) {
    logMessage("Erro crítico ao verificar privilégios Super Admin para ID $loggedInSaasUserId: " . $e->getMessage());
    header('Location: login.php?error=internal_error'); // Falha crítica, volta pro login
    exit;
}

// --- Inicialização e Feedback de Ações ---
$allSaaSUsers = [];         // Array para guardar usuários SaaS
$allMLConnections = [];     // Array para guardar conexões ML
$allQuestionLogs = [];      // Array para guardar logs globais
$feedbackMessage = null;    // Mensagem de feedback (ex: usuário ativado)
$feedbackMessageClass = ''; // Classe CSS para a mensagem

// Mapeamento de status de ação para classes Tailwind
$message_classes = [
    'success' => 'bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300',
    'error'   => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
    'warning' => 'bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300',
    // Adicionado para compatibilidade com mensagens de erro genéricas
    'is-danger is-light' => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
];

// Processa mensagens de feedback vindas de super_admin_actions.php
if (isset($_GET['action_status']) && isset($_GET['action_msg'])) {
    $statusType = $_GET['action_status'];
    $messageText = urldecode($_GET['action_msg']);
    // Define a mensagem e a classe se o tipo for válido
    if (isset($message_classes[$statusType])) {
        $feedbackMessage = ['type' => $statusType, 'text' => $messageText];
        $feedbackMessageClass = $message_classes[$statusType];
    }
    // Limpa os parâmetros da URL via JS
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname + window.location.hash); }, 1); } </script>";
}

// --- Busca de Dados Globais ---
try {
    // 1. Buscar Todos os Usuários SaaS com dados Asaas
    $stmtUsers = $pdo->query(
        "SELECT id, email, name, cpf_cnpj, is_saas_active, created_at,
                asaas_customer_id, asaas_subscription_id, subscription_status, subscription_expires_at
         FROM saas_users ORDER BY created_at DESC"
    );
    $allSaaSUsers = $stmtUsers->fetchAll();

    // 2. Buscar Todas as Conexões ML Ativas e Inativas, com email do usuário SaaS
    $stmtML = $pdo->query(
        "SELECT m.id, m.ml_user_id, m.is_active, m.token_expires_at, m.updated_at, s.email as saas_email, s.id as saas_user_id
         FROM mercadolibre_users m
         JOIN saas_users s ON m.saas_user_id = s.id
         ORDER BY m.updated_at DESC"
    );
    $allMLConnections = $stmtML->fetchAll();

    // 3. Buscar os Últimos Logs Globais de Processamento de Perguntas
    $logLimit = 500; // Limite de logs a exibir
    $stmtLogs = $pdo->prepare(
        "SELECT q.*, s.email as saas_email
         FROM question_processing_log q
         LEFT JOIN saas_users s ON q.saas_user_id = s.id -- LEFT JOIN para mostrar logs mesmo se usuário for deletado
         ORDER BY q.last_processed_at DESC
         LIMIT :limit"
    );
    $stmtLogs->bindParam(':limit', $logLimit, PDO::PARAM_INT);
    $stmtLogs->execute();
    $allQuestionLogs = $stmtLogs->fetchAll();

} catch (\PDOException | \Exception $e) {
    logMessage("Erro DB/Geral Super Admin Dashboard: " . $e->getMessage());
    // Define mensagem de erro se ainda não houver uma vinda de _GET
    if (!$feedbackMessage) {
        $feedbackMessage = ['type' => 'is-danger is-light', 'text' => '⚠️ Erro ao carregar dados globais para o painel.'];
        $feedbackMessageClass = $message_classes['is-danger is-light'];
    }
}

// (As funções helper getStatusTagClasses e getSubscriptionStatusClass estão em includes/helpers.php)

?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Estilos para quebra de texto em células de tabela */
        .break-all { word-break: break-all; }
        .truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* Ajustes finos de padding/margin se necessário */
        /* th, td { padding: 0.5rem 0.75rem; } */
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col transition-colors duration-300">
    <section class="main-content container mx-auto px-2 sm:px-4 py-8">
        <!-- Cabeçalho -->
        <header class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-6">
            <div class="flex justify-between items-center flex-wrap gap-y-2">
                <h1 class="text-xl font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-purple-500"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    <span>Meli AI - Super Admin</span>
                </h1>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600 dark:text-gray-400 hidden sm:inline" title="Admin Logado">
                        Admin: <?php echo htmlspecialchars($loggedInSaasUserEmail); ?>
                    </span>
                    <a href="dashboard.php" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300" title="Ir para Dashboard Normal">
                        Dashboard
                    </a>
                    <a href="logout.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 dark:focus:ring-offset-gray-800" title="Sair da Conta">
                        🚪 Sair
                    </a>
                </div>
            </div>
        </header>

        <!-- Mensagem de Feedback de Ações -->
        <?php if ($feedbackMessage && $feedbackMessageClass): ?>
            <div id="feedback-message" class="<?php echo htmlspecialchars($feedbackMessageClass); ?> px-4 py-3 rounded-md text-sm mb-6 flex justify-between items-center" role="alert">
                <span><?php echo htmlspecialchars($feedbackMessage['text']); ?></span>
                <button onclick="document.getElementById('feedback-message').style.display='none';" class="ml-4 -mr-1 p-1 rounded-md focus:outline-none focus:ring-2 focus:ring-current hover:bg-opacity-20 hover:bg-current" aria-label="Fechar">
                   <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        <?php endif; ?>

        <!-- Abas de Navegação -->
         <div class="mb-6">
             <div class="border-b border-gray-200 dark:border-gray-700">
                 <nav id="superadmin-tabs" class="-mb-px flex space-x-4 sm:space-x-6 overflow-x-auto" aria-label="Tabs">
                     <a href="#tab-users" data-tab="users" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372m-1.062-3.538a9.38 9.38 0 0 1-.372 2.625M15 19.128v-1.5a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 9 12.75v-1.5a3.375 3.375 0 0 0-3.375-3.375H4.5a1.125 1.125 0 0 1-1.125-1.125v-1.5A3.375 3.375 0 0 0 6.75 3h10.5A3.375 3.375 0 0 0 21 6.75v1.5a1.125 1.125 0 0 1-1.125 1.125h-1.5a3.375 3.375 0 0 0-3.375 3.375v1.5a1.125 1.125 0 0 1-1.125 1.125h-1.5Zm-6 0a9.375 9.375 0 1 1 18 0 9.375 9.375 0 0 1-18 0Z" /></svg>
                         <span>Usuários SaaS</span>
                     </a>
                     <a href="#tab-ml-connections" data-tab="ml-connections" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                         <span>Conexões ML</span>
                     </a>
                     <a href="#tab-all-logs" data-tab="all-logs" class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-1.5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border-transparent">
                         <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" /></svg>
                         <span>Todos os Logs</span>
                     </a>
                 </nav>
             </div>
         </div>

        <!-- Container Conteúdo das Abas -->
        <div class="space-y-6">

            <!-- Aba Usuários SaaS -->
            <div id="tab-users" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6">
                 <h2 class="text-lg font-semibold mb-4">👥 Usuários SaaS Registrados</h2>
                 <?php if (empty($allSaaSUsers)): ?>
                     <p class="text-gray-500 dark:text-gray-400">Nenhum usuário SaaS encontrado.</p>
                 <?php else: ?>
                     <div class="overflow-x-auto">
                         <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                             <thead class="bg-gray-50 dark:bg-gray-700/50">
                                 <tr>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email / Nome</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status Conta</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status Assinatura</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Expira em</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Asaas Cust ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Asaas Sub ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registro</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ações</th>
                                 </tr>
                             </thead>
                             <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                 <?php foreach ($allSaaSUsers as $user): ?>
                                     <tr>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo $user['id']; ?></td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                             <div class="truncate max-w-xs" title="<?php echo htmlspecialchars($user['email']); ?>"><?php echo htmlspecialchars($user['email']); ?></div>
                                             <?php if(!empty($user['name'])): ?>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-xs" title="<?php echo htmlspecialchars($user['name']); ?>"><?php echo htmlspecialchars($user['name']); ?></div>
                                             <?php endif; ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm">
                                             <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $user['is_saas_active'] ? 'bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100'; ?>">
                                                 <?php echo $user['is_saas_active'] ? 'Ativo' : 'Inativo'; ?>
                                             </span>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm">
                                             <span class="<?php echo getSubscriptionStatusClass($user['subscription_status']); ?>">
                                                 <?php echo htmlspecialchars(ucfirst(strtolower($user['subscription_status'] ?? 'N/A'))); ?>
                                             </span>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                             <?php echo $user['subscription_expires_at'] ? htmlspecialchars(date('d/m/Y', strtotime($user['subscription_expires_at']))) : '-'; ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400 break-all" title="<?php echo htmlspecialchars($user['asaas_customer_id'] ?? '-'); ?>">
                                             <?php echo htmlspecialchars($user['asaas_customer_id'] ?? '-'); ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400 break-all" title="<?php echo htmlspecialchars($user['asaas_subscription_id'] ?? '-'); ?>">
                                             <?php echo htmlspecialchars($user['asaas_subscription_id'] ?? '-'); ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                             <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($user['created_at']))); ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm font-medium">
                                             <?php if ($user['id'] != $loggedInSaasUserId): // Não permite ações no próprio admin ?>
                                                 <?php if ($user['is_saas_active']): ?>
                                                     <a href="super_admin_actions.php?action=deactivate&user_id=<?php echo $user['id']; ?>" class="text-yellow-600 hover:text-yellow-900 dark:text-yellow-400 dark:hover:text-yellow-300 mr-3" title="Desativar Conta SaaS">Desativar</a>
                                                 <?php else: ?>
                                                     <a href="super_admin_actions.php?action=activate&user_id=<?php echo $user['id']; ?>" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3" title="Ativar Conta SaaS">Ativar</a>
                                                 <?php endif; ?>
                                                 <a href="super_admin_actions.php?action=delete&user_id=<?php echo $user['id']; ?>" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300" title="Excluir Conta SaaS Permanentemente" onclick="return confirm('EXCLUIR USUÁRIO <?php echo htmlspecialchars(addslashes($user['email'])); ?>?\n\nATENÇÃO: Esta ação não pode ser desfeita e removerá o acesso do usuário permanentemente!');">
                                                     Excluir
                                                 </a>
                                             <?php else: ?>
                                                 <span class="text-xs italic text-gray-500 dark:text-gray-400">(Você)</span>
                                             <?php endif; ?>
                                         </td>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>
                     </div>
                 <?php endif; ?>
            </div> <!-- Fim #tab-users -->

            <!-- Aba Conexões ML -->
            <div id="tab-ml-connections" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6 overflow-x-auto">
                 <h2 class="text-lg font-semibold mb-4">🔗 Conexões Mercado Livre Ativas/Inativas</h2>
                  <?php if (empty($allMLConnections)): ?>
                     <p class="text-gray-500 dark:text-gray-400">Nenhuma conexão Mercado Livre encontrada no sistema.</p>
                 <?php else: ?>
                     <div class="overflow-x-auto">
                         <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                             <thead class="bg-gray-50 dark:bg-gray-700/50">
                                 <tr>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">ID Conexão</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Usuário SaaS</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">ML User ID</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status Conexão</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Token Expira</th>
                                     <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Última Atualização</th>
                                     <!-- Adicionar Coluna Ações se necessário (ex: desativar conexão ML) -->
                                 </tr>
                             </thead>
                             <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                 <?php foreach ($allMLConnections as $conn): ?>
                                     <tr>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo $conn['id']; ?></td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300 truncate max-w-xs" title="<?php echo htmlspecialchars($conn['saas_email']); ?>">
                                             <?php echo htmlspecialchars($conn['saas_email']); ?> (ID: <?php echo $conn['saas_user_id']; ?>)
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($conn['ml_user_id']); ?></td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm">
                                             <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $conn['is_active'] ? 'bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100'; ?>">
                                                 <?php echo $conn['is_active'] ? 'Ativa' : 'Inativa'; ?>
                                             </span>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                              <?php echo $conn['token_expires_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($conn['token_expires_at']))) : 'N/A'; ?>
                                         </td>
                                         <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                             <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($conn['updated_at']))); ?>
                                         </td>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>
                     </div>
                 <?php endif; ?>
            </div> <!-- Fim #tab-ml-connections -->

            <!-- Aba Todos os Logs -->
            <div id="tab-all-logs" class="tab-content hidden bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6">
                 <h2 class="text-lg font-semibold mb-4">📜 Todos os Logs Recentes (Últimos <?php echo $logLimit; ?>)</h2>
                  <?php if (empty($allQuestionLogs)): ?>
                      <p class="text-center text-gray-500 dark:text-gray-400 py-10 text-sm">Nenhum log de processamento encontrado no sistema.</p>
                  <?php else: ?>
                      <div class="log-container custom-scrollbar border border-gray-200 dark:border-gray-700 rounded-lg max-h-[70vh] overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
                          <?php foreach ($allQuestionLogs as $log): ?>
                              <div class="log-entry px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                  <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-1">
                                      <span class="text-sm font-medium text-gray-800 dark:text-gray-200">P: <?php echo htmlspecialchars($log['ml_question_id']); ?></span>
                                      <span class="text-xs text-gray-500 dark:text-gray-400" title="Usuário SaaS"><?php echo htmlspecialchars($log['saas_email'] ?? 'ID: ' . ($log['saas_user_id'] ?? 'N/A')); ?></span>
                                      <span class="text-sm text-gray-600 dark:text-gray-400">ML UID: <?php echo htmlspecialchars($log['ml_user_id']); ?></span>
                                      <span class="text-sm text-gray-600 dark:text-gray-400">Item: <?php echo htmlspecialchars($log['item_id']); ?></span>
                                      <span class="<?php echo getStatusTagClasses($log['status']); ?>" title="Status"><?php echo htmlspecialchars(str_replace('_', ' ', $log['status'])); ?></span>
                                  </div>
                                  <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1">
                                      <?php if (!empty($log['sent_to_whatsapp_at'])): ?> <span title="Notif Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['sent_to_whatsapp_at']))); ?>">🔔 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['sent_to_whatsapp_at']))); ?></span> <?php endif; ?>
                                      <?php if (!empty($log['human_answered_at'])): ?> <span title="Resp Wpp: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['human_answered_at']))); ?>">✍️ <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['human_answered_at']))); ?></span> <?php endif; ?>
                                      <?php if (!empty($log['ai_answered_at'])): ?> <span title="Resp IA: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['ai_answered_at']))); ?>">🤖 <?php echo htmlspecialchars(date('d/m H:i', strtotime($log['ai_answered_at']))); ?></span> <?php endif; ?>
                                  </div>
                                  <?php if (!empty($log['question_text'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Pergunta <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded text-xs text-gray-700 dark:text-gray-300 max-h-40 overflow-y-auto whitespace-pre-wrap break-words"><code><?php echo htmlspecialchars($log['question_text']); ?></code></pre></details><?php endif; ?>
                                  <?php if (!empty($log['ia_response_text']) && in_array(strtoupper($log['status']), ['AI_ANSWERED', 'AI_FAILED', 'AI_PROCESSING', 'AI_TRIGGERED_BY_TEXT'])): ?> <details class="mt-2"><summary class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center group"> Ver Resposta IA <?php if (strtoupper($log['status']) == 'AI_ANSWERED') echo '(Enviada)'; elseif (strtoupper($log['status']) == 'AI_FAILED') echo '(Inválida/Falhou)'; else echo '(Gerada/Tentada)'; ?> <svg class="arrow-down h-4 w-4 ml-1 transition-transform duration-200 group-focus:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg> </summary><pre class="mt-1 p-2 border rounded text-xs max-h-40 overflow-y-auto whitespace-pre-wrap break-words <?php echo strtoupper($log['status']) == 'AI_ANSWERED' ? 'bg-green-50 dark:bg-green-900/50 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300'; ?>"><code><?php echo htmlspecialchars($log['ia_response_text']); ?></code></pre></details><?php endif; ?>
                                  <?php if (!empty($log['error_message'])): ?><p class="text-red-600 dark:text-red-400 text-xs mt-1"><strong>Erro:</strong> <?php echo htmlspecialchars($log['error_message']); ?></p><?php endif; ?>
                                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-right">Última Atualização: <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['last_processed_at']))); ?></p>
                              </div>
                          <?php endforeach; ?>
                      </div>
                  <?php endif; // Fim else $allQuestionLogs ?>
             </div> <!-- Fim #tab-all-logs -->
        </div> <!-- Fim container conteúdo abas -->
    </section> <!-- Fim .main-content -->

     <!-- Rodapé -->
     <footer class="py-6 text-center">
         <p class="text-sm text-gray-500 dark:text-gray-400">
             <strong>Meli AI - Super Admin</strong> © <?php echo date('Y'); ?>
         </p>
     </footer>

    <!-- Script JS Abas -->
    <script>
         document.addEventListener('DOMContentLoaded', () => {
             const tabs = document.querySelectorAll('#superadmin-tabs a[data-tab]');
             const tabContents = document.querySelectorAll('.tab-content'); // Seleciona todas as divs de conteúdo
             const activeTabClasses = ['text-blue-600', 'dark:text-blue-400', 'border-blue-500'];
             const inactiveTabClasses = ['text-gray-500', 'dark:text-gray-400', 'hover:text-gray-700', 'dark:hover:text-gray-200', 'hover:border-gray-300', 'dark:hover:border-gray-500', 'border-transparent'];

             function switchTab(targetTabId) {
                 tabs.forEach(tab => {
                     const isTarget = tab.getAttribute('data-tab') === targetTabId;
                     tab.classList.toggle(...activeTabClasses, isTarget);
                     tab.classList.toggle(...inactiveTabClasses, !isTarget);
                     tab.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                 });
                 tabContents.forEach(content => {
                     if(content.id === `tab-${targetTabId}`) { content.classList.remove('hidden'); }
                     else { content.classList.add('hidden'); }
                 });
                 if (history.pushState) { setTimeout(() => history.pushState(null, null, '#tab-' + targetTabId), 0); }
                 else { window.location.hash = '#tab-' + targetTabId; }
             }

             tabs.forEach(tab => {
                 tab.addEventListener('click', (event) => {
                     event.preventDefault();
                     const tabId = tab.getAttribute('data-tab');
                     if (tabId) { switchTab(tabId); }
                 });
             });

             let activeTabId = 'users'; // Aba padrão
             if (window.location.hash && window.location.hash.startsWith('#tab-')) {
                 const hash = window.location.hash.substring(5); // Remove '#tab-'
                 const requestedTab = document.querySelector(`#superadmin-tabs a[data-tab="${hash}"]`);
                 if (requestedTab) { activeTabId = hash; }
             }
             switchTab(activeTabId); // Ativa a aba inicial
         });
    </script>
</body>
</html>