<?php
/**
 * Arquivo: super_admin_actions.php
 * Versão: v1.1 - Confirma includes após refatoração (Confirmado)
 * Descrição: Processa ações de gerenciamento de usuários (ativar, desativar, excluir)
 *            vindas do painel Super Admin (super_admin.php).
 *            Requer que o usuário logado seja Super Admin.
 */

// Includes Essenciais
require_once __DIR__ . '/config.php';             // Inicia sessão, carrega constantes
require_once __DIR__ . '/db.php';                 // Para getDbConnection()
require_once __DIR__ . '/includes/log_helper.php'; // Para logMessage()

// --- Validação de Acesso: Super Admin Logado ---
if (!isset($_SESSION['saas_user_id'])) {
    // Se não há usuário logado na sessão, redireciona para login
    header('Location: login.php?error=unauthorized');
    exit;
}

$loggedInSaasUserId = $_SESSION['saas_user_id']; // ID do admin logado
$isSuperAdmin = false;
$pdo = null;

try {
    $pdo = getDbConnection();
    // Verifica no banco se o usuário logado tem a flag de super admin
    $stmtAdmin = $pdo->prepare("SELECT is_super_admin FROM saas_users WHERE id = :id LIMIT 1");
    $stmtAdmin->execute([':id' => $loggedInSaasUserId]);
    $adminData = $stmtAdmin->fetch();

    // Se não encontrou o usuário ou ele não é super admin, nega acesso
    if (!$adminData || !$adminData['is_super_admin']) {
        logMessage("ALERTA: Tentativa de acesso a super_admin_actions.php por NÃO Super Admin ID: $loggedInSaasUserId");
        // Redireciona para o dashboard normal, pois não tem permissão aqui
        header('Location: dashboard.php');
        exit;
    }
    // Se chegou aqui, o usuário é Super Admin
    $isSuperAdmin = true;

} catch (\Exception $e) {
    // Erro crítico ao verificar permissões, melhor deslogar ou ir para erro
    logMessage("Erro crítico ao verificar privilégios em super_admin_actions.php para ID $loggedInSaasUserId: " . $e->getMessage());
    header('Location: login.php?error=internal_error'); // Volta para login com erro
    exit;
}

// --- Processamento das Ações ---

// Pega a ação e o ID do usuário alvo dos parâmetros GET
$action = $_GET['action'] ?? null;
$targetUserId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT); // Pega e valida se é um inteiro

// Define valores padrão para feedback
$status = 'error'; // Assume erro por padrão
$message = 'Ação inválida ou ID de usuário não fornecido.';
$targetUserEmail = ($targetUserId) ? 'ID ' . $targetUserId : 'N/A'; // Email padrão para logs

// Verifica se a ação e o ID são válidos
if ($action && $targetUserId && $targetUserId > 0) {

    // Impede Super Admin de executar ações em sua própria conta
    if ($targetUserId == $loggedInSaasUserId) {
        $message = 'Você não pode executar esta ação em sua própria conta.';
        $status = 'warning'; // Usa status de aviso
        logMessage("Super Admin $loggedInSaasUserId tentou ação '$action' em si mesmo.");
    } else {
        // Tenta encontrar o usuário alvo no banco antes de agir
        try {
            $stmtTarget = $pdo->prepare("SELECT email FROM saas_users WHERE id = :id");
            $stmtTarget->execute([':id' => $targetUserId]);
            $targetUser = $stmtTarget->fetch();

            // Se o usuário alvo não existe no banco
            if (!$targetUser) {
                $message = "Usuário com ID $targetUserId não encontrado.";
                $status = 'error';
                logMessage("Super Admin $loggedInSaasUserId tentou ação '$action' em usuário inexistente ID: $targetUserId");
            } else {
                // Usuário alvo encontrado, pega o email para logs mais claros
                $targetUserEmail = $targetUser['email'];
                logMessage("Super Admin $loggedInSaasUserId iniciando ação '$action' no usuário '$targetUserEmail' (ID: $targetUserId)");

                $pdo->beginTransaction(); // Inicia transação para garantir atomicidade da operação

                // Executa a ação solicitada
                switch ($action) {
                    case 'activate': // Ativa a conta SaaS do usuário
                        $sql = "UPDATE saas_users SET is_saas_active = TRUE, updated_at = NOW() WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':id' => $targetUserId]);
                        $message = "Usuário '$targetUserEmail' (ID: $targetUserId) ativado com sucesso.";
                        $status = 'success';
                        logMessage("Usuário '$targetUserEmail' (ID: $targetUserId) ATIVADO por Super Admin $loggedInSaasUserId.");
                        break;

                    case 'deactivate': // Desativa a conta SaaS do usuário
                        $sql = "UPDATE saas_users SET is_saas_active = FALSE, updated_at = NOW() WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':id' => $targetUserId]);

                        // Opcional: Desativar também a conexão ML associada?
                        // Poderia ser feito aqui ou deixar que o CRON trate conexões de usuários inativos.
                        // Ex: $pdo->exec("UPDATE mercadolibre_users SET is_active = FALSE WHERE saas_user_id = $targetUserId");
                        // logMessage("Conexão ML para usuário '$targetUserEmail' (ID: $targetUserId) também desativada.");

                        $message = "Usuário '$targetUserEmail' (ID: $targetUserId) desativado com sucesso.";
                        $status = 'success';
                        logMessage("Usuário '$targetUserEmail' (ID: $targetUserId) DESATIVADO por Super Admin $loggedInSaasUserId.");
                        break;

                    case 'delete': // Exclui permanentemente o usuário SaaS
                        // !! ALERTA DE INTEGRIDADE DE DADOS !!
                        // Esta exclusão remove o usuário da tabela `saas_users`.
                        // NÃO remove automaticamente registros relacionados em outras tabelas
                        // (`mercadolibre_users`, `question_processing_log`) que usam `saas_user_id`.
                        // Considere usar chaves estrangeiras com ON DELETE SET NULL ou ON DELETE CASCADE,
                        // ou implementar uma lógica de limpeza aqui ou em um processo separado.
                        logMessage("AVISO: Excluindo usuário SaaS ID $targetUserId ('$targetUserEmail'). Dados relacionados (ML conns, logs) NÃO serão removidos automaticamente por este script.");

                        $sql = "DELETE FROM saas_users WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':id' => $targetUserId]);

                        // Verifica se alguma linha foi realmente deletada
                        if ($stmt->rowCount() > 0) {
                            $message = "Usuário '$targetUserEmail' (ID: $targetUserId) EXCLUÍDO permanentemente do sistema SaaS.";
                            $status = 'success';
                            logMessage("Usuário '$targetUserEmail' (ID: $targetUserId) EXCLUÍDO por Super Admin $loggedInSaasUserId.");
                            // TODO: Implementar limpeza de dados relacionados aqui, se desejado.
                            // Ex: DELETE FROM mercadolibre_users WHERE saas_user_id = $targetUserId;
                            // Ex: UPDATE question_processing_log SET saas_user_id = NULL WHERE saas_user_id = $targetUserId;
                        } else {
                             // Nenhuma linha afetada (usuário já tinha sido removido?)
                             $message = "Não foi possível excluir o usuário ID $targetUserId (talvez já tenha sido removido).";
                             $status = 'warning';
                             logMessage("Tentativa de exclusão do usuário ID $targetUserId por Super Admin $loggedInSaasUserId falhou (rowCount 0).");
                        }
                        break;

                    default: // Ação desconhecida
                        $pdo->rollBack(); // Desfaz transação se ação for inválida
                        $message = "Ação desconhecida: '$action'. Nenhuma alteração realizada.";
                        $status = 'error';
                        logMessage("Super Admin $loggedInSaasUserId tentou ação desconhecida: '$action' no usuário ID: $targetUserId");
                        break;
                }

                // Se a ação foi válida e não houve exceção, commita a transação
                 if ($status !== 'error' && $action !== 'default') {
                    $pdo->commit();
                 } elseif ($action !== 'default') {
                     // Se houve erro SQL ou outro dentro do switch, faz rollback
                     if($pdo->inTransaction()) { $pdo->rollBack(); }
                 }

            } // Fim else (targetUser encontrado)
        } catch (\PDOException $e) {
             // Erro no banco de dados durante a execução da ação
             if($pdo->inTransaction()) { $pdo->rollBack(); } // Garante rollback
             logMessage("Erro DB ao executar ação '$action' no usuário ID $targetUserId ($targetUserEmail): " . $e->getMessage());
             $message = "Erro no banco de dados ao tentar executar a ação '$action'. Consulte os logs.";
             $status = 'error';
        } catch (\Exception $e) {
             // Outro erro inesperado
             if($pdo->inTransaction()) { $pdo->rollBack(); } // Garante rollback
             logMessage("Erro GERAL ao executar ação '$action' no usuário ID $targetUserId ($targetUserEmail): " . $e->getMessage());
             $message = "Erro inesperado ao tentar executar a ação '$action'. Consulte os logs.";
             $status = 'error';
        }
    } // Fim else (não é auto-ação)

} else {
    // Se a ação ou user_id não foram fornecidos ou são inválidos
    logMessage("Tentativa de acesso a super_admin_actions.php com parâmetros inválidos: Action='$action', UserID='$targetUserId'");
    // Mensagem padrão de erro já definida
}

// --- Redirecionamento Final ---
// Redireciona de volta para o painel Super Admin com a mensagem de status/erro
// Adiciona #tab-users para focar na aba de usuários após a ação
header('Location: super_admin.php?action_status='.$status.'&action_msg=' . urlencode($message) . '#tab-users');
exit; // Finaliza o script
?>