<?php
/**
 * Arquivo: db.php (Localizado na raiz /meliai/)
 * Versão: v1.4 - Usa Defuse e carrega chave de $secrets (via global).
 * Descrição: Funções para conexão com o banco de dados e criptografia segura.
 */

// Inclui o config.php que está NO MESMO DIRETÓRIO que este arquivo (db.php).
// Isso garante que $secrets e as constantes DB_* estejam disponíveis.
require_once __DIR__ . '/config.php';

// Usa as classes da biblioteca Defuse (o autoload já foi feito pelo config.php)
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Defuse\Crypto\Exception as DefuseException; // Alias para exceções Defuse

/**
 * Obtém uma conexão PDO com o banco de dados MySQL/MariaDB.
 * Reutiliza a conexão existente na mesma requisição para eficiência.
 * Usa as constantes DB_* definidas em config.php.
 *
 * @return PDO Objeto da conexão PDO configurado.
 * @throws PDOException Se a conexão com o banco de dados falhar.
 */
function getDbConnection(): PDO
{
    // Variável estática para manter a conexão PDO durante a execução do script
    static $pdo = null;

    // Se a conexão ainda não foi estabelecida nesta requisição
    if ($pdo === null) {
        // Verifica se as constantes do DB foram definidas e se a senha não está vazia
        // (Já verificado em config.php, mas uma checagem extra aqui é segura)
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS') || DB_PASS === '') {
            $errorMessage = "FATAL (db.php): Constantes DB_* não definidas ou DB_PASS está vazia. Verifique config.php e secrets.php.";
            error_log($errorMessage);
            // Lança exceção para interromper o fluxo se a configuração estiver incorreta
            throw new \PDOException("Configuração de banco de dados crítica incompleta ou inválida (DB01).");
        }

        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em erros SQL
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna arrays associativos por padrão
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Usa prepared statements nativos
        ];

        try {
            // Tenta criar a instância da conexão PDO
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            // Loga o erro sem expor detalhes sensíveis como a senha (se houver no DSN)
            $logMessage = "FATAL DB Connection Error: " . $e->getCode() . " - Falha ao conectar como usuário '" . DB_USER . "' ao host '" . DB_HOST . "'. Verifique credenciais, host, nome do banco e permissões.";
            error_log($logMessage . " | PDO Message: " . $e->getMessage()); // Loga a msg PDO tbm
            // Tenta usar logMessage se disponível
            if (function_exists('logMessage')) logMessage($logMessage);
            // Lança uma exceção genérica para o código chamador, escondendo detalhes internos
            throw new \PDOException("Falha crítica na conexão com o banco de dados (DB02).", (int)$e->getCode());
        }
    }
    // Retorna a conexão PDO (nova ou existente)
    return $pdo;
}


/**
 * Carrega a chave de criptografia de forma segura a partir do array global $secrets.
 * Este array deve ter sido carregado pelo config.php antes desta função ser chamada.
 * A chave carregada é armazenada estaticamente para eficiência.
 *
 * @global array $secrets Array associativo contendo os segredos, incluindo 'APP_ENCRYPTION_KEY'.
 * @return Key Objeto Key da biblioteca Defuse.
 * @throws Exception Se a chave não estiver definida, for inválida ou ocorrer erro ao carregar.
 */
function loadEncryptionKey(): Key
{
    // Acessa a variável global $secrets carregada pelo config.php
    // Cuidado: Usar 'global' não é a prática mais limpa, mas é a mais direta aqui.
    // Uma alternativa seria passar $secrets['APP_ENCRYPTION_KEY'] como argumento.
    global $secrets;

    // Cacheia a chave carregada para evitar recarregá-la múltiplas vezes na mesma requisição
    static $loadedKey = null;

    if ($loadedKey === null) {
        // Lê a chave ASCII do array $secrets
        $keyAscii = $secrets['APP_ENCRYPTION_KEY'] ?? null;

        if (empty($keyAscii)) {
            $errorMessage = "ERRO CRÍTICO DE SEGURANÇA (db.php): Chave 'APP_ENCRYPTION_KEY' não definida ou vazia no arquivo de segredos!";
            error_log($errorMessage); // Loga sempre
            if (function_exists('logMessage')) logMessage($errorMessage);
            throw new Exception('Chave de criptografia essencial não configurada (SEC10).');
        }

        try {
            // Tenta carregar a chave a partir da string ASCII segura
            $loadedKey = Key::loadFromAsciiSafeString($keyAscii);
        } catch (DefuseException\BadFormatException $e) {
            $errorMessage = "ERRO CRÍTICO DE SEGURANÇA (db.php): Formato inválido da APP_ENCRYPTION_KEY: " . $e->getMessage();
            error_log($errorMessage);
            if (function_exists('logMessage')) logMessage($errorMessage);
            throw new Exception('Chave de criptografia com formato inválido (SEC11).');
        } catch (\Throwable $e) { // Captura outros erros Defuse ou gerais
            $errorMessage = "ERRO CRÍTICO DE SEGURANÇA (db.php): Erro ao carregar APP_ENCRYPTION_KEY: " . $e->getMessage();
            error_log($errorMessage);
            if (function_exists('logMessage')) logMessage($errorMessage);
            throw new Exception('Erro geral ao carregar chave de criptografia (SEC12).');
        }
    }
    return $loadedKey;
}

/**
 * Criptografa dados de forma segura usando defuse/php-encryption.
 *
 * @param string $data O dado em texto plano a ser criptografado.
 * @return string A string criptografada (segura para armazenamento).
 * @throws Exception Se a criptografia falhar (ambiente inseguro, erro ao carregar chave).
 */
function encryptData(string $data): string
{
    try {
        $key = loadEncryptionKey(); // Carrega a chave segura
        return Crypto::encrypt($data, $key);
    } catch (DefuseException\EnvironmentIsBrokenException $e) {
        $errorMessage = "ERRO CRÍTICO Criptografia (db.php): Ambiente PHP inseguro detectado. " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Encryption failed due to insecure environment (SEC20).");
    } catch (\Throwable $e) { // Captura erros ao carregar chave também
        $errorMessage = "ERRO Criptografia (db.php): " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        // Lança exceção para indicar a falha
        throw new Exception("Encryption failed (SEC21): " . $e->getMessage());
    }
}

/**
 * Descriptografa dados usando defuse/php-encryption.
 *
 * @param string $encryptedData A string criptografada a ser descriptografada.
 * @return string O dado original em texto plano.
 * @throws Exception Se a descriptografia falhar (chave errada, dado corrompido, ambiente inseguro, erro ao carregar chave).
 */
function decryptData(string $encryptedData): string
{
    try {
        $key = loadEncryptionKey(); // Carrega a chave segura
        return Crypto::decrypt($encryptedData, $key);
    } catch (DefuseException\WrongKeyOrModifiedCiphertextException $e) {
        // Erro comum: Chave errada ou dado corrompido/modificado. Não logue $encryptedData completo.
        $errorMessage = "ERRO Descriptografia (db.php): Chave incorreta ou dado modificado. Input prefix: " . substr($encryptedData, 0, 20) . "...";
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Decryption failed: Invalid key or data integrity compromised (SEC30).");
    } catch (DefuseException\EnvironmentIsBrokenException $e) {
        $errorMessage = "ERRO CRÍTICO Descriptografia (db.php): Ambiente PHP inseguro detectado. " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Decryption failed due to insecure environment (SEC31).");
    } catch (\Throwable $e) { // Captura erros ao carregar chave e outros erros Defuse
        $errorMessage = "ERRO Descriptografia (db.php): " . $e->getMessage();
        error_log($errorMessage);
        if (function_exists('logMessage')) logMessage($errorMessage);
        throw new Exception("Decryption failed (SEC32): " . $e->getMessage());
    }
}

