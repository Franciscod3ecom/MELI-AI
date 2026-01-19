<?php
/**
 * Arquivo: register.php
 * Versão: v5.4 - Adiciona Auto-Login e redireciona para billing.php
 * Descrição: Página de cadastro. Cria usuário local, cliente Asaas,
 *            inicia a sessão do usuário e redireciona para billing.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php'; // Inicia sessão implicitamente
require_once __DIR__ . '/db.php';     // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()
require_once __DIR__ . '/includes/asaas_api.php'; // Para createAsaasCustomer()

// --- Inicialização ---
$errors = [];
// Preenche $formData com valores POST ou vazios para repopular o formulário
$formData = [
    'email' => $_POST['email'] ?? '',
    'whatsapp_number' => $_POST['whatsapp_number'] ?? '',
    'name' => $_POST['name'] ?? '',
    'cpf_cnpj' => $_POST['cpf_cnpj'] ?? ''
];
$error_class = 'bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300'; // Classe Tailwind para erros

// --- Redirecionamento se já logado ---
// Se o usuário já tem uma sessão ativa, redireciona para evitar recadastro
if (isset($_SESSION['saas_user_id'])) {
    // Verifica o status da assinatura na sessão para decidir o destino
    if (isset($_SESSION['subscription_status']) && $_SESSION['subscription_status'] === 'ACTIVE') {
        header('Location: dashboard.php'); // Se ativo, vai pro dashboard
    } else {
        header('Location: billing.php'); // Se não ativo (ou status desconhecido), vai pra billing
    }
    exit;
}

// --- Processamento do Formulário de Cadastro ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Sanitização e validação dos inputs
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $whatsapp_number_raw = $_POST['whatsapp_number'] ?? '';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $cpf_cnpj_raw = $_POST['cpf_cnpj'] ?? '';

    // Limpa CPF/CNPJ para validação e armazenamento
    $cpf_cnpj_cleaned = preg_replace('/[^0-9]/', '', $cpf_cnpj_raw);

    // Validações dos campos
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "📧 Formato de e-mail inválido.";
    }
    if (empty($name)) {
        $errors[] = "👤 Nome é obrigatório.";
    }
    if (empty($cpf_cnpj_cleaned)) {
        $errors[] = "📄 CPF/CNPJ é obrigatório.";
    } elseif (strlen($cpf_cnpj_cleaned) != 11 && strlen($cpf_cnpj_cleaned) != 14) {
        $errors[] = "📄 CPF/CNPJ inválido (deve conter 11 ou 14 dígitos).";
        // TODO: Implementar validação de dígito verificador para CPF/CNPJ aqui para maior robustez.
    }
    if (empty($password)) {
        $errors[] = "🔒 Senha é obrigatória.";
    } elseif (strlen($password) < 8) {
        $errors[] = "📏 Senha deve ter no mínimo 8 caracteres.";
    } elseif ($password !== $password_confirm) {
        $errors[] = "👯 As senhas não coincidem.";
    }

    // Validação e formatação do WhatsApp
    $whatsapp_jid_to_save = null;
    $jid_cleaned = preg_replace('/[^\d]/', '', $whatsapp_number_raw); // Remove não-dígitos
    if (empty($jid_cleaned)) {
        $errors[] = "📱 Número WhatsApp é obrigatório.";
    } elseif (preg_match('/^\d{10,11}$/', $jid_cleaned)) { // Valida DDD + Número (10 ou 11 dígitos)
        $whatsapp_jid_to_save = "55" . $jid_cleaned . "@s.whatsapp.net"; // Formato JID Brasil
    } else {
        $errors[] = "📱 Formato do WhatsApp inválido (DDD + Número, 10 ou 11 dígitos).";
    }

    // Se não houver erros de validação, tenta processar o cadastro
    if (empty($errors)) {
        $pdo = null;
        try {
            $pdo = getDbConnection();
            $pdo->beginTransaction(); // Inicia transação DB

            // 1. Verifica se o email já existe no sistema local
            $stmtCheck = $pdo->prepare("SELECT id FROM saas_users WHERE email = :email LIMIT 1");
            $stmtCheck->execute([':email' => $email]);
            if ($stmtCheck->fetch()) {
                $errors[] = "📬 Este e-mail já está cadastrado em nosso sistema. Tente fazer login.";
                $pdo->rollBack(); // Cancela transação se email já existe
            } else {
                // 2. Cria ou busca o cliente correspondente no Asaas
                 logMessage("[Register v5.4] Verificando/Criando cliente Asaas para: $email / $cpf_cnpj_cleaned");
                 $asaasCustomer = createAsaasCustomer($name, $email, $cpf_cnpj_cleaned, $jid_cleaned); // Passa o número limpo

                 // Verifica se a criação/busca no Asaas foi bem-sucedida
                 if (!$asaasCustomer || !isset($asaasCustomer['id'])) {
                     // Lança uma exceção para ser capturada pelo catch geral
                     throw new Exception("Falha ao criar ou buscar cliente na plataforma de pagamento Asaas. Verifique os logs da API Asaas.");
                 }
                 $asaasCustomerId = $asaasCustomer['id'];
                 logMessage("[Register v5.4] Cliente Asaas OK (ID: $asaasCustomerId). Criando usuário local...");

                // 3. Cria o hash da senha
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                if (!$password_hash) {
                    // Falha crítica ao gerar hash
                    throw new Exception("Erro crítico ao gerar hash da senha.");
                }

                // 4. Insere o usuário no banco de dados local
                $sqlInsert = "INSERT INTO saas_users (email, password_hash, whatsapp_jid, name, cpf_cnpj, asaas_customer_id, subscription_status, is_saas_active, created_at, updated_at)
                              VALUES (:email, :pwd, :jid, :name, :cpf_cnpj, :asaas_id, 'PENDING', FALSE, NOW(), NOW())";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $successLocal = $stmtInsert->execute([
                    ':email' => $email,
                    ':pwd' => $password_hash,
                    ':jid' => $whatsapp_jid_to_save,
                    ':name' => $name,
                    ':cpf_cnpj' => $cpf_cnpj_cleaned, // Salva só os números
                    ':asaas_id' => $asaasCustomerId  // Vincula ao ID do cliente Asaas
                ]);
                $localUserId = $pdo->lastInsertId(); // Pega o ID do usuário recém-criado

                // Verifica se a inserção local foi bem-sucedida
                if ($successLocal && $localUserId) {

                    // Opcional: Atualizar a referência externa no cliente Asaas com o ID local
                    // Isso pode ser útil para futuras consultas. Exigiria uma função updateAsaasCustomer.
                    // updateAsaasCustomer($asaasCustomerId, ['externalReference' => $localUserId]);
                    // logMessage("[Register v5.4] Referência externa atualizada no Asaas para $asaasCustomerId com local ID $localUserId.");

                    $pdo->commit(); // Confirma a transação no banco de dados ANTES de iniciar a sessão
                    logMessage("[Register v5.4] Usuário $email (ID: $localUserId) criado com sucesso no DB local.");

                    // **** NOVO: INICIAR SESSÃO AUTOMATICAMENTE ****
                    session_regenerate_id(true); // Regenera o ID da sessão por segurança
                    $_SESSION['saas_user_id'] = $localUserId;
                    $_SESSION['saas_user_email'] = $email;
                    $_SESSION['subscription_status'] = 'PENDING'; // Define o status inicial na sessão
                    $_SESSION['asaas_customer_id'] = $asaasCustomerId; // Guarda o ID Asaas na sessão
                    logMessage("[Register v5.4] Sessão iniciada para usuário $localUserId.");

                    // **** NOVO: REDIRECIONAR PARA BILLING APÓS CADASTRO ****
                    // Envia para a página de billing com uma mensagem de sucesso
                    header('Location: billing.php?status=registered');
                    exit; // Finaliza o script após o redirecionamento
                    // **** FIM DAS MUDANÇAS ****

                } else {
                    // Falha ao inserir o usuário localmente, mesmo após sucesso/busca no Asaas
                    throw new Exception("Falha ao salvar usuário local no banco de dados após criar/buscar cliente Asaas.");
                }
            } // Fim else (email não existe localmente)
        } catch (\PDOException | \Exception $e) {
            // Rollback em caso de erro durante a transação
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logMessage("[Register v5.4] Erro CRÍTICO cadastro $email: " . $e->getMessage());
            // Define mensagem de erro genérica para o usuário
             if (strpos($e->getMessage(), 'cliente na plataforma de pagamento') !== false) {
                 $errors[] = "⚠️ Erro ao comunicar com o sistema de pagamento. Verifique os dados ou tente mais tarde.";
             } else {
                 $errors[] = "⚙️ Erro inesperado durante o cadastro. Por favor, tente novamente ou contate o suporte.";
             }
             // Não redireciona, permite que a página seja recarregada com os erros
        }
    } // Fim if empty($errors)
}
?>
<!DOCTYPE html>
<html lang="pt-br" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Meli AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style> body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; } </style>
    <!-- Incluir JS para máscara de CPF/CNPJ aqui se desejar -->
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 transition-colors duration-300">
    <section class="flex flex-col items-center justify-center min-h-screen py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full bg-white dark:bg-gray-800 shadow-md rounded-lg p-8 space-y-6">
            <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-white">🚀 Criar Conta Meli AI</h1>

            <!-- Exibição de Erros -->
            <?php if (!empty($errors)): ?>
                <div class="<?php echo $error_class; ?> px-4 py-3 rounded-md text-sm mb-4" role="alert">
                    <p class="font-bold mb-2">🔔 Corrija os seguintes erros:</p>
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Formulário de Cadastro -->
            <form action="register.php" method="POST" novalidate class="space-y-4">
                 <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">👤 Nome Completo</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="text" id="name" name="name" placeholder="Seu nome completo" required
                           value="<?php echo htmlspecialchars($formData['name']); ?>" autocomplete="name">
                </div>
                 <div>
                    <label for="cpf_cnpj" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">📄 CPF ou CNPJ</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="text" id="cpf_cnpj" name="cpf_cnpj" placeholder="Apenas números" required
                           value="<?php echo htmlspecialchars($formData['cpf_cnpj']); ?>" >
                     <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Necessário para pagamento e nota fiscal.</p>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">📧 E-mail</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="email" id="email" name="email" placeholder="Seu melhor e-mail" required
                           value="<?php echo htmlspecialchars($formData['email']); ?>" autocomplete="email">
                </div>
                <div>
                    <label for="whatsapp_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        📱 Seu WhatsApp <span class="text-gray-500 dark:text-gray-400 font-normal">(Obrigatório)</span>
                    </label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="tel" id="whatsapp_number" name="whatsapp_number"
                           value="<?php echo htmlspecialchars($formData['whatsapp_number']); ?>" placeholder="Ex: 11987654321" pattern="\d{10,11}" title="Informe DDD + Número (10 ou 11 dígitos)" required autocomplete="tel">
                     <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Para receber notificações.</p>
                </div>
                 <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">🔒 Crie uma Senha</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="password" id="password" name="password" placeholder="Mínimo 8 caracteres" required minlength="8" autocomplete="new-password">
                </div>
                 <div>
                    <label for="password_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">🔑 Confirme a Senha</label>
                    <input class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                           type="password" id="password_confirm" name="password_confirm" placeholder="Repita a senha criada" required autocomplete="new-password">
                </div>
                <div>
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                        Criar Conta e Ir para Pagamento
                    </button>
                </div>
            </form>

             <p class="text-sm text-center text-gray-500 dark:text-gray-400">
                 Já tem uma conta? <a href="login.php" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Faça Login</a>.
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