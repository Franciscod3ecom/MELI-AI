<?php
/**
 * Arquivo: index.php
 * Versão: v4.2 - Confirma includes após refatoração
 * Descrição: Página inicial (landing page) do Meli AI com Tailwind.
 *            Redireciona para o dashboard se o usuário já estiver logado.
 */

// Inclui config para iniciar a sessão e verificar login
require_once __DIR__ . '/config.php';

// Verifica se o usuário já está logado na sessão
if (isset($_SESSION['saas_user_id'])) {
    header('Location: dashboard.php'); // Redireciona para o painel
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br" class=""> <!-- Add class="" for potential future JS theme toggle -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo ao Meli AI - Respostas Inteligentes para Mercado Livre</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css"> <!-- Link para o CSS centralizado -->
    <style>
        /* Minimal base styles if needed - prefer Tailwind utilities */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 transition-colors duration-300 flex flex-col min-h-screen">

    <!-- Seção Hero -->
    <section class="main-content flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-2xl w-full space-y-8 text-center">
             <!-- Placeholder para Logo 
             <div class="mx-auto h-20 w-20 text-blue-500 dark:text-blue-400">
                Substitua pelo seu SVG ou IMG 
                 <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-full h-full">
                   <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                   <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-2.25V18m-7.5-2.25H4.5v.75a.75.75 0 0 0 1.5 0v-.75h.75a.75.75 0 0 0 0-1.5h-.75V15a.75.75 0 0 0-1.5 0v.75H3a.75.75 0 0 0-.75.75Zm15 .75a.75.75 0 0 0 .75-.75v-.75a.75.75 0 0 0-1.5 0V15h-.75a.75.75 0 0 0 0 1.5h.75v.75a.75.75 0 0 0 .75.75Z" />
                 </svg>
             </div> -->
            <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-5xl">
                🤖 Meli AI
            </h1>
            <p class="mt-4 text-xl text-gray-500 dark:text-gray-400">
                Responda perguntas do Mercado Livre 10x mais rápido com Inteligência Artificial e notificações WhatsApp.
            </p>
            <!-- Botões de Ação -->
            <div class="mt-10 flex flex-col sm:flex-row sm:justify-center space-y-4 sm:space-y-0 sm:space-x-4">
                <a href="login.php" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                    🔑 Acessar Painel
                </a>
                <a href="register.php" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-lg text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:bg-green-600 dark:text-white dark:hover:bg-green-700 dark:focus:ring-offset-gray-800">
                    🚀 Criar Conta
                </a>
            </div>
        </div>
    </section>

     <!-- Rodapé Principal -->
     <footer class="py-6 text-center">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            <strong>Meli AI</strong> © <?php echo date('Y'); ?> Todos os direitos reservados.
        </p>
    </footer>

</body>
</html>