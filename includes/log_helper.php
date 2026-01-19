<?php
/**
 * Arquivo: includes/log_helper.php
 * Versão: v1.0
 * Descrição: Helper para logging centralizado.
 */

// Inclui config.php para a constante LOG_FILE, se necessário,
// mas é mais seguro que LOG_FILE seja definida antes de chamar logMessage.
// require_once __DIR__ . '/../config.php'; // Cuidado com caminhos relativos

/**
 * Registra uma mensagem no arquivo de log especificado em `config.php`.
 * Inclui timestamp e PID (Process ID) para facilitar o rastreamento.
 * Tenta criar o diretório de log se não existir e verifica permissões de escrita.
 * Usa `LOCK_EX` para tentar evitar condições de corrida em escritas concorrentes.
 *
 * @param string $message A mensagem a ser registrada no log.
 * @return void
 */
function logMessage(string $message): void
{
    try {
        // Garante que a constante LOG_FILE está definida
        if (!defined('LOG_FILE')) {
             // Se não definida, tenta usar o log de erro padrão do PHP
             error_log("FATAL: Constante LOG_FILE não definida! Mensagem original: " . $message);
             return;
        }
        $logFilePath = LOG_FILE;
        $logDir = dirname($logFilePath);

        // Tenta criar o diretório de log recursivamente se não existir
        if ($logDir && !is_dir($logDir)) {
            // @ suprime erros caso a criação falhe (ex: permissão), a verificação de escrita tratará disso
            @mkdir($logDir, 0775, true);
        }

        // Formata a mensagem de log com timestamp e PID
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid() ?: 'N/A'; // Obtém o PID do processo atual
        $logLine = "[$timestamp PID:$pid] $message\n";

        // Verifica se o diretório é gravável e se o arquivo é gravável (ou não existe)
        $canWrite = $logDir && is_writable($logDir) && (!file_exists($logFilePath) || is_writable($logFilePath));

        if ($canWrite) {
             // Tenta escrever no arquivo de log com bloqueio exclusivo
             $logSuccess = @file_put_contents(
                 $logFilePath,
                 $logLine,
                 FILE_APPEND | LOCK_EX // Adiciona ao final do arquivo e tenta bloquear
             );
             // Se a escrita falhar mesmo com permissões (raro, mas possível)
             if ($logSuccess === false) {
                  error_log("Falha ao escrever no log '$logFilePath' (file_put_contents retornou false) - Mensagem: $logLine");
             }
        } else {
             // Loga um aviso crítico se não houver permissão de escrita
             $permErrorMsg = "AVISO CRÍTICO de Log: Não é possível escrever no arquivo de log '$logFilePath'. Verifique permissões do diretório '$logDir' e do arquivo (se existir).";
             error_log($permErrorMsg);
             // Loga a mensagem original no log de erros do PHP como fallback
             error_log("Mensagem original (falha log): $logLine");
        }

    } catch (\Exception $e) {
        // Captura exceções inesperadas dentro da própria função de log
        $logPathMsg = defined('LOG_FILE') ? LOG_FILE : 'N/A';
        error_log("Exceção CRÍTICA na função logMessage() para '$logPathMsg': " . $e->getMessage() . " | Mensagem original: " . $message);
    }
}
?>