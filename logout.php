<?php
/**
 * Arquivo: logout.php
 * Versão: v1.1 - Confirma includes após refatoração
 * Descrição: Destrói a sessão do usuário SaaS e redireciona para o login.
 */

// Inclui config para garantir que a sessão está iniciada antes de manipulá-la
require_once __DIR__ . '/config.php';

// 1. Unset todas as variáveis de sessão.
$_SESSION = [];

// 2. Se usar cookies de sessão, deleta o cookie.
// Nota: Isso destruirá a sessão, e não apenas os dados da sessão!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, // Tempo no passado para expirar
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finalmente, destrói a sessão no servidor.
session_destroy();

// 4. Redireciona para a página de login com uma mensagem indicando o logout.
header("Location: login.php?status=loggedout");
exit; // Garante que o script termine após o redirecionamento.
?>