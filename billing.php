<?php
/**
 * Arquivo: billing.php
 * Versão: v1.2 - Verifica DB se status sessão não ACTIVE, redireciona se DB ACTIVE.
 * Descrição: Exibe o status da assinatura (PENDING, OVERDUE, CANCELED) ou redireciona
 *            para o dashboard se a verificação no banco de dados confirmar que está ativa.
 *            Fornece botões para iniciar pagamento (novo) ou tentar pagar pendência.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Para constantes ASAAS e de sessão (inicia sessão)
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()
require_once __DIR__ . '/includes/helpers.php'; // Para getSubscriptionStatusClass()

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];
$saasUserEmail = $_SESSION['saas_user_email'] ?? 'Usuário';

// --- Verifica Status Assinatura (Sessão e DB) ---
$subscriptionStatus = $_SESSION['subscription_status'] ?? null; // Pega status da sessão
$asaasCustomerId = $_SESSION['asaas_customer_id'] ?? null;     // Pega ID cliente da sessão
$userName = 'Usuário'; // Valor padrão
$billingMessage = null; // Para mensagens de erro/status nesta página
$billingMessageClass = '';

// Busca nome do usuário para personalização (melhoria visual)
try {
    $pdo = getDbConnection();
    $stmtName = $pdo->prepare("SELECT name FROM saas_users WHERE id = :id");
    $stmtName->execute([':id' => $saasUserId]);
    $nameData = $stmtName->fetch();
    if ($nameData && !empty($nameData['name'])) {
        $userName = $nameData['name'];
    }
} catch (\Exception $e) {
    logMessage("Erro buscar nome billing v1.2 (SaaS ID: $saasUserId): " . $e->getMessage());
    // Continua mesmo sem o nome
}

// ** VERIFICAÇÃO PRINCIPAL DE STATUS E REDIRECIONAMENTO **
// Se o status na SESSÃO não for ATIVO, verifica no BANCO DE DADOS
if ($subscriptionStatus !== 'ACTIVE') {
    $logMsg = "Billing v1.2: Sessão não ativa ($subscriptionStatus) para SaaS ID $saasUserId. Verificando DB...";
    function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
    try {
        // Reconsulta o DB para o status mais recente e ID do cliente Asaas
        $pdoCheck = getDbConnection(); // Reusa ou cria conexão
        $stmtCheck = $pdoCheck->prepare("SELECT subscription_status, asaas_customer_id FROM saas_users WHERE id = :id");
        $stmtCheck->execute([':id' => $saasUserId]);
        $dbData = $stmtCheck->fetch();
        $dbStatus = $dbData['subscription_status'] ?? 'INACTIVE'; // Assume INACTIVE se não encontrar usuário/status
        $dbAsaasCustomerId = $dbData['asaas_customer_id'] ?? null; // Pega ID Asaas do DB tbm

        // Atualiza as variáveis locais com os dados mais recentes do DB
        $subscriptionStatus = $dbStatus;
        if (!empty($dbAsaasCustomerId)) {
            $asaasCustomerId = $dbAsaasCustomerId;
        }
        // Atualiza a SESSÃO com os dados do DB para consistência futura
        $_SESSION['subscription_status'] = $dbStatus;
        if (!empty($dbAsaasCustomerId)) {
            $_SESSION['asaas_customer_id'] = $dbAsaasCustomerId;
        }

        // Se o status NO BANCO DE DADOS for ATIVO, redireciona imediatamente
        if ($dbStatus === 'ACTIVE') {
            $logMsg = "Billing v1.2: DB está ATIVO para SaaS ID $saasUserId. Sessão atualizada. Redirecionando para dashboard...";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
            header('Location: dashboard.php'); // Redireciona para o painel principal
            exit;
        } else {
            // DB também não está ativo. Permite que a página de billing seja exibida com o status correto.
            $logMsg = "Billing v1.2: DB também NÃO está ATIVO ($dbStatus) para SaaS ID $saasUserId. Exibindo página de billing.";
            function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
        }
    } catch (\Exception $e) {
         // Erro ao consultar DB, não consegue verificar status real.
         // Exibe a página com uma mensagem de erro para o usuário.
         $logMsg = "Billing v1.2: Erro ao verificar DB status para $saasUserId: " . $e->getMessage();
         function_exists('logMessage') ? logMessage($logMsg) : error_log($logMsg);
         $billingMessage = ['type' => 'error', 'text' => 'Erro ao verificar o status atual da sua assinatura. Tente atualizar a página ou contate o suporte.'];
         // Permite que a página carregue para mostrar a mensagem de erro.
    }
} else {
     // Status da sessão já era ACTIVE, redireciona por segurança (não deveria chegar aqui normalmente)
     logMessage("Billing v1.2: Sessão já estava ATIVA para SaaS ID $saasUserId. Redirecionando para dashboard.");
     header('Location: dashboard.php');
     exit;
}
// --- Fim Verificação Status ---

// --- Processamento de Mensagens da URL ---
// Mapeamento de tipos de mensagem para classes Tailwind
$message_classes = [
    'error' => 'bg-red-100 border border-red-400 text-red-700 dark:bg-red-900 dark:border-red-700 dark:text-red-300',
    'warning' => 'bg-yellow-100 border border-yellow-400 text-yellow-700 dark:bg-yellow-900 dark:border-yellow-700 dark:text-yellow-300',
    'success' => 'bg-green-100 border border-green-400 text-green-700 dark:bg-green-900 dark:border-green-700 dark:text-green-300',
    'info' => 'bg-blue-100 border border-blue-400 text-blue-700 dark:bg-blue-900 dark:border-blue-700 dark:text-blue-300',
];

// Se não houve erro na verificação do DB, processa mensagens da URL
if (!$billingMessage) {
    if (isset($_GET['billing_status'])) {
        $status = $_GET['billing_status'];
        $reason = $_GET['reason'] ?? null;

        if ($status === 'link_error') {
             $msg = 'Não foi possível gerar ou obter o link de pagamento.';
             if ($reason === 'existing_not_found') $msg .= ' A fatura pendente/vencida não foi encontrada no Asaas.';
             elseif ($reason === 'new_sub_no_link') $msg .= ' A assinatura foi criada, mas o link inicial não foi obtido.';
              $billingMessage = ['type' => 'error', 'text' => $msg . ' Tente novamente ou contate o suporte.'];
        } elseif ($status === 'asaas_error') {
            $msg = 'Ocorreu um erro na comunicação com o sistema de pagamento.';
            if ($_GET['code'] === 'no_customer_id') $msg = 'Erro interno: ID de cliente Asaas não encontrado.';
            elseif ($_GET['code'] === 'sub_create_failed') $msg = 'Falha ao criar a assinatura no sistema de pagamento.';
             $billingMessage = ['type' => 'error', 'text' => $msg . ' Tente novamente mais tarde ou contate o suporte.'];
        } elseif ($status === 'db_error') {
             $billingMessage = ['type' => 'error', 'text' => 'Ocorreu um erro interno ao buscar seus dados.'];
        } elseif ($status === 'internal_error') {
             $billingMessage = ['type' => 'error', 'text' => 'Ocorreu um erro inesperado no servidor.'];
        } elseif ($status === 'inactive') { // Mensagem vinda de outras páginas como oauth_start
            $billingMessage = ['type' => 'warning', 'text' => 'Sua assinatura precisa estar ativa para realizar esta ação.'];
        }
    } elseif (isset($_GET['status']) && $_GET['status'] === 'registered') { // Mensagem vinda do register.php
        $billingMessage = ['type' => 'success', 'text' => '✅ Cadastro realizado! Faça o pagamento abaixo para ativar sua conta.'];
    }
}

// Define a classe CSS para a mensagem, se houver
if ($billingMessage && isset($message_classes[$billingMessage['type']])) {
    $billingMessageClass = $message_classes[$billingMessage['type']];
}

// Limpa os parâmetros GET da URL após lê-los
if (isset($_GET['billing_status']) || isset($_GET['status']) || isset($_GET['error'])) {
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname); }, 1); } </script>";
}

?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinatura - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style> /* Estilos específicos, se houver */ </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col transition-colors duration-300">

    <section class="main-content container mx-auto px-4 py-8 max-w-2xl">
        <!-- Cabeçalho -->
        <header class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-6">
             <div class="flex justify-between items-center">
                <h1 class="text-xl font-semibold flex items-center gap-2">
                     <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-blue-500"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 21.75Z" /></svg>
                     <span>Assinatura Meli AI</span>
                </h1>
                <a href="logout.php" class="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">Sair</a>
            </div>
        </header>

        <!-- Mensagem de Erro/Status da Página -->
        <?php if ($billingMessage && $billingMessageClass): ?>
            <div class="<?php echo htmlspecialchars($billingMessageClass); ?> px-4 py-3 rounded-md text-sm mb-6 flex justify-between items-center" role="alert">
                <span><?php echo htmlspecialchars($billingMessage['text']); ?></span>
                <button onclick="this.parentElement.style.display='none';" class="ml-4 -mr-1 p-1 rounded-md focus:outline-none focus:ring-2 focus:ring-current hover:bg-opacity-20 hover:bg-current" aria-label="Fechar">
                   <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        <?php endif; ?>

        <!-- Conteúdo Principal da Página -->
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 space-y-6">
            <h2 class="text-lg font-semibold">Olá, <?php echo htmlspecialchars($userName); ?>!</h2>

            <?php // --- Cenário 1: Status PENDENTE ---
                  // O usuário acabou de se cadastrar ou a assinatura foi criada mas não paga.
            ?>
            <?php if ($subscriptionStatus === 'PENDING' && $asaasCustomerId): ?>
                <p class="text-gray-700 dark:text-gray-300">
                    Seu cadastro está quase completo! Para ativar todas as funcionalidades do Meli AI,
                    por favor, finalize o pagamento da sua assinatura trimestral.
                </p>
                <div class="border dark:border-gray-700 rounded p-4 space-y-2 bg-gray-50 dark:bg-gray-700">
                    <p><strong>Plano Selecionado:</strong> Trimestral</p>
                    <p><strong>Valor:</strong> R$ <?php echo number_format(ASAAS_PLAN_VALUE, 2, ',', '.'); ?> (a cada 3 meses)</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Acesso a todas as funcionalidades, incluindo respostas ilimitadas via IA e notificações WhatsApp.</p>
                </div>

                 <div class="text-center mt-6">
                    <p class="mb-4 text-gray-700 dark:text-gray-300">Clique abaixo para ir ao ambiente seguro de pagamento e ativar sua assinatura:</p>
                    <!-- Este link vai para go_to_asaas_payment.php -->
                    <!-- Se for a primeira vez (sem sub_id), ele cria a assinatura e redireciona. -->
                    <!-- Se a sub_id já existe, ele tenta buscar o link da fatura PENDENTE. -->
                    <a href="go_to_asaas_payment.php" target="_blank"
                       class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 dark:focus:ring-offset-gray-800">
                       Pagar Assinatura (R$ <?php echo number_format(ASAAS_PLAN_VALUE, 2, ',', '.'); ?>)
                    </a>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Você será redirecionado para o Asaas para concluir o pagamento com segurança.</p>
                     <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">Após o pagamento ser confirmado, seu acesso será liberado automaticamente (pode levar alguns minutos). Você pode precisar atualizar esta página ou fazer login novamente.</p>
                 </div>

            <?php // --- Cenário 2: Status OVERDUE ---
                  // A fatura da assinatura está vencida.
            ?>
            <?php elseif ($subscriptionStatus === 'OVERDUE'): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 dark:bg-yellow-900/30 dark:border-yellow-600 dark:text-yellow-300 p-4 rounded-r-md" role="alert">
                    <p class="font-bold">Pagamento Pendente!</p>
                    <p>Identificamos um pagamento vencido para sua assinatura.</p>
                </div>
                <p class="text-gray-700 dark:text-gray-300 mt-4">
                    Para continuar utilizando o Meli AI sem interrupções, por favor, regularize sua situação.
                </p>
                 <div class="text-center mt-6">
                     <!-- Este link vai para go_to_asaas_payment.php, que tentará buscar o link da fatura OVERDUE -->
                     <a href="go_to_asaas_payment.php?action=retry" target="_blank"
                        class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                         Regularizar Pagamento
                    </a>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Você será redirecionado para o Asaas para visualizar e pagar a pendência.</p>
                 </div>

             <?php // --- Cenário 3: Status CANCELED ou INACTIVE ---
                   // A assinatura foi cancelada pelo usuário, pelo admin, ou expirou.
             ?>
             <?php elseif ($subscriptionStatus === 'CANCELED' || $subscriptionStatus === 'INACTIVE'): ?>
                 <div class="bg-red-100 border-l-4 border-red-500 text-red-700 dark:bg-red-900/30 dark:border-red-600 dark:text-red-300 p-4 rounded-r-md" role="alert">
                    <p class="font-bold">Assinatura Inativa</p>
                    <p>Sua assinatura do Meli AI não está ativa no momento (Status: <?php echo htmlspecialchars($subscriptionStatus);?>).</p>
                </div>
                 <p class="text-gray-700 dark:text-gray-300 mt-4">
                     Para reativar seu acesso e voltar a usar todas as funcionalidades, por favor, inicie uma nova assinatura.
                 </p>
                 <div class="text-center mt-6">
                     <!-- Este link vai para go_to_asaas_payment.php, que criará uma NOVA assinatura -->
                     <a href="go_to_asaas_payment.php" target="_blank"
                        class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 dark:focus:ring-offset-gray-800">
                         Iniciar Nova Assinatura Trimestral
                    </a>
                     <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Você será redirecionado para o Asaas.</p>
                 </div>

            <?php // --- Cenário 4: Erro ou status inesperado / Falta ID Cliente ---
                  // Se o status não for nenhum dos acima, ou se faltar o ID do cliente Asaas.
            ?>
            <?php else: ?>
                 <div class="bg-orange-100 border-l-4 border-orange-500 text-orange-700 dark:bg-orange-900/30 dark:border-orange-600 dark:text-orange-300 p-4 rounded-r-md" role="alert">
                     <p class="font-bold">Status Inesperado ou Incompleto</p>
                     <p>Não foi possível determinar o estado correto da sua assinatura ou o vínculo com nosso sistema de pagamentos.</p>
                      <p class="mt-2 text-sm">Status atual registrado: <?php echo htmlspecialchars($subscriptionStatus ?: 'N/D'); ?></p>
                       <?php if(!$asaasCustomerId): ?>
                        <p class="text-sm font-semibold mt-1">Importante: ID de cliente do sistema de pagamento não encontrado.</p>
                       <?php endif; ?>
                 </div>
                 <p class="text-gray-600 dark:text-gray-300 mt-4">
                     Se você acredita que isso é um erro, ou se acabou de se cadastrar e o erro persiste,
                     por favor, <a href="logout.php" class="text-blue-600 hover:underline dark:text-blue-400">saia da sua conta</a>
                     e tente fazer login novamente em alguns minutos. Se o problema continuar, entre em contato com o suporte.
                 </p>
            <?php endif; ?>

            <!-- Link para tentar acessar o Dashboard (sempre visível) -->
             <div class="text-center mt-8 border-t border-gray-200 dark:border-gray-700 pt-4">
                <a href="dashboard.php" class="text-sm text-blue-600 hover:underline dark:text-blue-400">Tentar Acessar o Dashboard</a>
             </div>

        </div> <!-- Fim do card principal -->

         <footer class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
             <p>© <?php echo date('Y'); ?> Meli AI</p>
         </footer>
    </section>

</body>
</html>