<?php
/**
 * Arquivo: includes/helpers.php
 * Versão: v1.0
 * Descrição: Funções auxiliares (helpers) reutilizáveis na aplicação.
 */

if (!function_exists('getSubscriptionStatusClass')) {
    /**
     * Retorna as classes CSS do Tailwind para a tag de status da assinatura.
     * Utilizada no dashboard.php e super_admin.php.
     *
     * @param string|null $status O status da assinatura (ex: 'ACTIVE', 'PENDING', 'OVERDUE', 'CANCELED').
     *                            Trata NULL como 'PENDING' para exibição.
     * @return string As classes CSS correspondentes.
     */
    function getSubscriptionStatusClass(?string $status): string {
         // Classe base para todas as tags de status
         $base = "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium";

         // Normaliza o status para maiúsculas e trata null como PENDING por padrão
         $normalizedStatus = strtoupper($status ?? 'PENDING');

         // Mapeia o status normalizado para as classes CSS
         switch ($normalizedStatus) {
             case 'ACTIVE':
                 // Verde para Ativo
                 return "$base bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100";
             case 'PENDING':
                 // Azul para Pendente
                 return "$base bg-blue-100 text-blue-800 dark:bg-blue-700 dark:text-blue-100";
             case 'OVERDUE':
                 // Amarelo para Vencido
                 return "$base bg-yellow-100 text-yellow-800 dark:bg-yellow-700 dark:text-yellow-100";
             case 'INACTIVE': // Status local vindo do admin
             case 'CANCELED': // Status vindo do Asaas ou local
             case 'EXPIRED':  // Status vindo do Asaas
                 // Vermelho para Inativo, Cancelado ou Expirado
                 return "$base bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100";
             default:
                 // Cinza para status desconhecidos ou não mapeados
                 return "$base bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-100";
         }
    }
}


if (!function_exists('getStatusTagClasses')) {
    /**
     * Retorna as classes CSS do Tailwind para a tag de status de processamento de pergunta.
     * Utilizada no dashboard.php e super_admin.php.
     *
     * @param string $status O status do log (ex: 'PENDING_WHATSAPP', 'AI_ANSWERED', 'ERROR').
     * @return string As classes CSS correspondentes.
     */
    function getStatusTagClasses(string $status): string {
        // Classe base para todas as tags
        $base = "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium";

        // Mapeia o status (convertido para maiúsculas para segurança) para classes CSS
        switch (strtoupper($status)) {
            case 'PENDING_WHATSAPP': // Aguardando envio ou falha no envio inicial
                return "$base bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200";
            case 'AWAITING_TEXT_REPLY': // Enviado para WhatsApp, aguardando resposta ou timeout
                return "$base bg-yellow-100 text-yellow-800 dark:bg-yellow-700 dark:text-yellow-100";
            case 'AI_TRIGGERED_BY_TEXT': // Usuário respondeu '2'
            case 'AI_PROCESSING':        // IA está processando (timeout ou comando '2')
                return "$base bg-purple-100 text-purple-800 dark:bg-purple-700 dark:text-purple-100";
            case 'AI_ANSWERED': // IA respondeu com sucesso no ML
                return "$base bg-green-100 text-green-800 dark:bg-green-700 dark:text-green-100";
            case 'HUMAN_ANSWERED_VIA_WHATSAPP': // Humano respondeu via WhatsApp e foi postado no ML
            case 'HUMAN_ANSWERED_ON_ML':        // Pergunta já estava respondida no ML quando verificada
                return "$base bg-blue-100 text-blue-800 dark:bg-blue-700 dark:text-blue-100";
            case 'AI_FAILED': // IA tentou responder mas falhou (erro API, validação, etc.)
            case 'ERROR':     // Erro genérico no processamento (conexão, DB, etc.)
            case 'DELETED':   // Pergunta foi deletada no ML
            case 'CLOSED_UNANSWERED': // Pergunta foi fechada sem resposta no ML
            case 'UNDER_REVIEW':      // Pergunta sob moderação no ML
            case 'BANNED':            // Pergunta banida no ML
                 return "$base bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100"; // Vermelho para erros e status finais negativos
            default:
                // Captura status desconhecidos retornados pela API ML que podem ter prefixo
                 if (strpos($status, 'UNKNOWN_ML_STATUS_') === 0) {
                     return "$base bg-red-100 text-red-800 dark:bg-red-700 dark:text-red-100";
                 }
                 // Status padrão ou não mapeado explicitamente
                 logMessage("WARN: Status de log não mapeado encontrado em getStatusTagClasses: '$status'");
                 return "$base bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-100"; // Cinza como fallback
        }
    }
}

// --- Outras Funções Helper ---
// Adicione outras funções auxiliares globais aqui conforme a necessidade do projeto.
// Exemplo: função para formatar CPF/CNPJ, validar datas, etc.

/*
 Exemplo (não implementado):
 if (!function_exists('formatCpfCnpj')) {
     function formatCpfCnpj(string $value): string {
         // Lógica para formatar CPF/CNPJ
         return $formattedValue;
     }
 }
*/

?>