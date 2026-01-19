<?php
/**
 * Arquivo: update_profile.php
 * Versão: v1.1 - Atualiza includes após refatoração
 * Descrição: Processa a atualização do número de WhatsApp do usuário logado.
 *            (Não implementa alteração de senha no momento).
 */

// Includes Essenciais
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';                 // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Proteção: Exige Login ---
if (!isset($_SESSION['saas_user_id'])) {
    header('Location: login.php?error=unauthorized');
    exit;
}
$saasUserId = $_SESSION['saas_user_id'];

// --- Processar apenas se for método POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Processamento do Número WhatsApp ---
    $whatsapp_number_raw = $_POST['whatsapp_number'] ?? '';
    $whatsapp_jid_to_save = null;
    $error_message = null;
    $redirect_code = 'generic'; // Código de erro para URL

    logMessage("[Profile Update] SaaS User $saasUserId - Iniciando atualização. WhatsApp Raw: '$whatsapp_number_raw'");

    // Validar e Formatar o Número se não estiver vazio
    if (!empty(trim($whatsapp_number_raw))) {
        $jid_cleaned = preg_replace('/[^\d]/', '', $whatsapp_number_raw); // Remove não-dígitos

        // Validação de tamanho (10 ou 11 dígitos para DDD + Número no Brasil)
        if (preg_match('/^\d{10,11}$/', $jid_cleaned)) {
            $whatsapp_jid_to_save = "55" . $jid_cleaned . "@s.whatsapp.net"; // Formato JID Brasil
            logMessage("[Profile Update] SaaS User $saasUserId - Número '$jid_cleaned' validado. JID formatado: '$whatsapp_jid_to_save'");
        } else {
            $error_message = "Formato do Número WhatsApp inválido. Use apenas DDD + Número (10 ou 11 dígitos).";
            $redirect_code = 'validation';
            logMessage("[Profile Update] SaaS User $saasUserId - Número inválido (formato/tamanho): '$whatsapp_number_raw'");
        }
    } else {
        // Permite limpar o número (define JID como NULL no banco)
        $whatsapp_jid_to_save = null;
        logMessage("[Profile Update] SaaS User $saasUserId - Número WhatsApp removido (campo vazio).");
    }

    // --- TODO: Implementar Lógica de Alteração de Senha ---
    // $current_password = $_POST['current_password'] ?? '';
    // $new_password = $_POST['new_password'] ?? '';
    // $confirm_password = $_POST['confirm_password'] ?? '';
    // if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
    //     // Validar senha atual
    //     // Validar nova senha (mín 8 chars, etc.)
    //     // Validar se nova senha e confirmação batem
    //     // Se tudo OK, buscar hash atual, verificar senha atual com password_verify()
    //     // Se senha atual OK, gerar novo hash com password_hash()
    //     // Atualizar o hash no banco de dados
    //     // Adicionar mensagens de erro ou sucesso específicas para senha
    //     logMessage("[Profile Update] SaaS User $saasUserId - Tentativa de alteração de senha (ainda não implementada).");
    // }
    // --- Fim TODO Senha ---


    // --- Atualizar no Banco de Dados (se não houve erro de validação do WhatsApp) ---
    if ($error_message === null) {
        try {
            $pdo = getDbConnection();
            // Query para atualizar APENAS o JID por enquanto
            $sql = "UPDATE saas_users SET whatsapp_jid = :jid, updated_at = NOW() WHERE id = :saas_user_id";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                ':jid' => $whatsapp_jid_to_save, // Salva o JID formatado ou NULL
                ':saas_user_id' => $saasUserId
            ]);

            if ($success) {
                logMessage("[Profile Update] SaaS User $saasUserId - JID atualizado no DB com sucesso.");
                // TODO: Adicionar mensagem de sucesso para senha se implementado
                header('Location: dashboard.php?profile_status=updated#perfil');
                exit;
            } else {
                $error_message = "Erro interno ao salvar as alterações no banco de dados.";
                $redirect_code = 'db';
                logMessage("[Profile Update] SaaS User $saasUserId - Falha SQL ao atualizar JID (execute retornou false).");
            }

        } catch (\PDOException $e) {
            logMessage("[Profile Update DB Error] SaaS User $saasUserId: " . $e->getMessage());
            $error_message = "Erro técnico ao salvar as alterações (DB)."; // Mensagem genérica para usuário
             $redirect_code = 'db';
        } catch (\Exception $e) { // Captura outros erros (ex: falha na criptografia de senha se implementado)
             logMessage("[Profile Update General Error] SaaS User $saasUserId: " . $e->getMessage());
             $error_message = "Erro inesperado ao processar a solicitação.";
              $redirect_code = 'internal';
        }
    }

    // Se chegou aqui, houve um erro (validação ou DB/Geral)
    // Redireciona de volta com mensagem de erro genérica e código
    logMessage("[Profile Update] SaaS User $saasUserId - Redirecionando com erro: Code='$redirect_code', Msg='$error_message'");
    // Usar a sessão para passar a mensagem de erro pode ser mais robusto que URL
    // $_SESSION['profile_error_msg'] = $error_message;
    header('Location: dashboard.php?profile_status=error&code=' . $redirect_code . '#perfil');
    exit;

} else {
    // Se não for POST, redireciona para o dashboard (aba padrão)
    logMessage("[Profile Update] Acesso não POST ignorado para SaaS User $saasUserId.");
    header('Location: dashboard.php');
    exit;
}
?>