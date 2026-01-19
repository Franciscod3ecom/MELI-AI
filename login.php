<?php
/**
 * Arquivo: login.php
 * Versão: v5.3 - Carrega status da assinatura na sessão e redireciona (Confirmado)
 * Descrição: Página de login. Verifica credenciais, carrega status da assinatura
 *            e ID do cliente Asaas para a sessão. Redireciona para dashboard
 *            (se ativo) ou billing (se não ativo).
 */

// Includes essenciais
require_once __DIR__ . '/config.php';             // Constantes e Session (inicia sessão)
require_once __DIR__ . '/db.php';                 // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Inicialização ---
$errors = [];
$message = null;
$email_value = ''; // Para repopular campo email em caso de erro

// --- Redirecionamento se já logado ---
// Se já existe uma sessão ativa, verifica o status e redireciona
if (isset($_SESSION['saas_user_id'])) {
    if (isset($_SESSION['subscription_status']) && $_SESSION['subscription_status'] === 'ACTIVE') {
        // Se sessão indica assinatura ativa, vai pro dashboard
        header('Location: dashboard.php');
    } else {
        // Se status não for ACTIVE ou indefinido na sessão, manda pra billing
        header('Location: billing.php');
    }
    exit; // Importante finalizar após redirecionamento
}

// --- Tratamento de Mensagens da URL (Feedback de outras páginas) ---
$message_classes = [ // Mapeamento para classes Tailwind CSS
    'is-info is-light' => 'bg-blue-100 dark:bg-blue-900 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300',
    'is-success' => 'bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300',
    'is-warning is-light' => 'bg-yellow-100 dark:bg-yellow-900 border border-yellow-400 dark:border-yellow-700 text-yellow-800 dark:text-yellow-300',
    'is-danger is-light' => 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300',
];
$message_class = '';
$error_class = $message_classes['is-danger is-light']; // Classe padrão para erros

// Verifica parâmetros GET para exibir mensagens de feedback
if (isset($_GET['status'])) {
    $status = $_GET['status'];
    if ($status === 'loggedout') {
        $message = ['type' => 'is-info is-light', 'text' => '👋 Você saiu com sucesso. Até logo!'];
    } elseif ($status === 'registered') { // Mensagem vinda do register.php (v5.4)
        $message = ['type' => 'is-success', 'text' => '✅ Cadastro realizado! Você já está logado. Siga para o pagamento.'];
        // Nota: Na v5.4 o usuário já é redirecionado para billing.php, esta mensagem pode não ser vista.
        // Mantida por compatibilidade ou caso o fluxo mude.
    } elseif ($status === 'registered_pending_payment') { // Mensagem da versão anterior do register.php
        $message = ['type' => 'is-success', 'text' => '✅ Cadastro realizado! Faça login para ir para a página de pagamento e ativar sua assinatura.'];
    }
} elseif (isset($_GET['error'])) {
    $error = $_GET['error'];
    if ($error === 'unauthorized') {
        $message = ['type' => 'is-warning is-light', 'text' => '✋ Você precisa fazer login para acessar essa página.'];
    } elseif ($error === 'session_expired') {
        $message = ['type' => 'is-warning is-light', 'text' => '⏱️ Sua sessão expirou. Faça login novamente.'];
    } elseif ($error === 'internal_error') {
        $message = ['type' => 'is-danger is-light', 'text' => '⚙️ Ocorreu um erro interno. Tente novamente mais tarde.'];
    } elseif ($error === 'inactive_subscription') { // Pode vir de outras verificações
         $message = ['type' => 'is-warning is-light', 'text' => '⚠️ Sua assinatura não está ativa. Faça login para verificar ou regularizar.'];
    }
}

// Define a classe CSS para a mensagem, se houver
if ($message && isset($message_classes[$message['type']])) {
    $message_class = $message_classes[$message['type']];
}

// Limpa os parâmetros GET da URL após lê-los para não persistirem no refresh
// Executado via Javascript no lado do cliente
if (isset($_GET['status']) || isset($_GET['error'])) {
    echo "<script> if (history.replaceState) { setTimeout(function() { history.replaceState(null, null, window.location.pathname); }, 1); } </script>";
}

// --- Processamento do Formulário de Login ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $email_value = $_POST['email'] ?? ''; // Guarda para repopular o campo

    // Validações básicas
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "📧 Formato de e-mail inválido.";
    }
    if (empty($password)) {
        $errors[] = "🔒 Senha é obrigatória.";
    }

    // Se não houver erros de validação inicial
    if (empty($errors)) {
        try {
            $pdo = getDbConnection();
            // Busca usuário pelo email e pega dados relevantes (incluindo status e ID Asaas)
            $stmt = $pdo->prepare("SELECT id, email, password_hash, is_saas_active, subscription_status, asaas_customer_id FROM saas_users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            // Verifica se o usuário existe e se a senha está correta
            if ($user && password_verify($password, $user['password_hash'])) {

                // Verifica se a conta está ativa administrativamente
                // Permite login se status for PENDING (para permitir que o usuário pague)
                if (!$user['is_saas_active'] && $user['subscription_status'] !== 'PENDING') {
                    $errors[] = "🚫 Sua conta está desativada administrativamente. Contate o suporte.";
                    logMessage("Login falhou (conta inativa admin): " . $email);
                } else {
                    // Sucesso no Login!
                    logMessage("Login SUCESSO: " . $email . " (SaaS ID: " . $user['id'] . ", Sub Status: " . $user['subscription_status'] . ")");

                    // Regenera o ID da sessão para segurança
                    session_regenerate_id(true);

                    // Armazena dados importantes na sessão
                    $_SESSION['saas_user_id'] = $user['id'];
                    $_SESSION['saas_user_email'] = $user['email'];
                    $_SESSION['subscription_status'] = $user['subscription_status']; // Guarda status da assinatura
                    $_SESSION['asaas_customer_id'] = $user['asaas_customer_id'];   // Guarda ID cliente Asaas

                    // Redirecionamento Condicional Baseado no Status da Assinatura
                    if ($user['subscription_status'] === 'ACTIVE') {
                         logMessage("Login OK, assinatura ATIVA. Redirecionando para dashboard.");
                         header('Location: dashboard.php');
                    } else {
                        // Se status for PENDING, OVERDUE, INACTIVE, CANCELED, etc., vai para billing.
                        logMessage("Login OK, mas assinatura NÃO está ativa ($user[subscription_status]). Redirecionando para billing.");
                        header('Location: billing.php');
                    }
                    exit; // Finaliza o script após o redirecionamento
                }
            } else {
                // Usuário não encontrado ou senha incorreta
                $errors[] = "❌ E-mail ou senha incorretos.";
                logMessage("Login falhou (credenciais inválidas): " . $email);
            }
        } catch (\PDOException $e) {
            logMessage("Erro DB login $email: " . $e->getMessage());
            $errors[] = "🛠️ Erro interno ao acessar dados. Tente novamente."; // Mensagem genérica
        } catch (\Exception $e) {
            logMessage("Erro geral login $email: " . $e->getMessage());
            $errors[] = "⚙️ Erro inesperado no servidor. Tente novamente."; // Mensagem genérica
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style> body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; } </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 transition-colors duration-300">
    <section class="flex flex-col items-center justify-center min-h-screen py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full bg-white dark:bg-gray-800 shadow-md rounded-lg p-8 space-y-6">
            <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-white">🔑 Login Meli AI</h1>

            <!-- Mensagens de status/erro -->
            <?php if ($message && $message_class): ?>
                <div class="<?php echo $message_class; ?> px-4 py-3 rounded-md text-sm mb-4" role="alert">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="<?php echo $error_class; ?> px-4 py-3 rounded-md text-sm mb-4" role="alert">
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Formulário de Login -->
            <form action="login.php" method="POST" novalidate class="space-y-6">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">📧 E-mail</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="email" id="email" name="email" placeholder="seuemail@exemplo.com" required
                           value="<?php echo htmlspecialchars($email_value); ?>" autocomplete="email">
                </div>

                <div>
                    <div class="flex justify-between items-baseline">
                         <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">🔒 Senha</label>
                         <!-- Link para recuperação de senha (se implementado) -->
                         <!-- <a href="forgot_password.php" class="text-xs text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Esqueceu a senha?</a> -->
                    </div>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="password" id="password" name="password" placeholder="Sua senha" required autocomplete="current-password">
                </div>

                <div>
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                        Entrar
                    </button>
                </div>
            </form>

             <p class="text-sm text-center text-gray-500 dark:text-gray-400">
                 Não tem uma conta? <a href="register.php" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Cadastre-se aqui</a>.
             </p>
             <p class="text-center mt-2 text-xs text-gray-500 dark:text-gray-400">
                 <a href="index.php" class="hover:underline">← Voltar</a>
             </p>
        </div>

         <footer class="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
             <p>© <?php echo date('Y'); ?> Meli AI</p>
         </footer>
    </section>
</body>
</html>