<?php
/**
 * Arquivo: config.php
 * Versão: v2.1 - Robusto: Carrega segredos de arquivo externo, verifica erros, inclui autoloader.
 * Descrição: Ponto central de configuração e inicialização para Meli AI.
 */
 
 // Timezone global do app
date_default_timezone_set('America/Sao_Paulo');
ini_set('date.timezone', 'America/Sao_Paulo');

// (opcional) log de conferência por alguns minutos
// error_log('TZ=' . date_default_timezone_get() . ' now=' . date('c'));


// --- Definição do Caminho Base da Aplicação ---
// __DIR__ é o diretório onde este arquivo (config.php) está localizado.
// Ex: /home/u267339178/domains/d3ecom.com.br/public_html/meliai
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// --- Configuração de Erros para PRODUÇÃO ---
// Essencial para segurança: Não exibir erros, apenas logá-los.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
// Reportar todos os erros exceto E_DEPRECATED e E_NOTICE (ajuste se precisar deles no log)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('log_errors', '1');

// Define o caminho do arquivo de log de erros do PHP.
// Tenta criar uma pasta 'php_logs' UM NÍVEL ACIMA da pasta da aplicação (melhor para segurança).
// Ex: /home/u267339178/domains/d3ecom.com.br/php_logs/
$phpLogDir = dirname(BASE_PATH) . '/php_logs'; // Nome da pasta de logs um nível acima
if (!is_dir($phpLogDir)) {
    // Tenta criar o diretório recursivamente com permissões adequadas.
    // O @ suprime erros caso o diretório já exista ou não tenha permissão para criar.
    @mkdir($phpLogDir, 0775, true);
}
// Verifica se o diretório foi criado ou já existia e é gravável.
if (is_dir($phpLogDir) && is_writable($phpLogDir)) {
    ini_set('error_log', $phpLogDir . '/php_errors.log');
} else {
    // Fallback: Logar dentro da pasta da aplicação (menos seguro).
    // Garanta que esta pasta/arquivo seja protegido por .htaccess!
    $fallbackLogPath = BASE_PATH . '/php_errors.log';
    ini_set('error_log', $fallbackLogPath);
    // Loga um aviso sobre o fallback apenas uma vez para não poluir o log principal
    static $fallbackLoggedCfg = false;
    if (!$fallbackLoggedCfg) {
        error_log("AVISO CRÍTICO (config.php): Diretório de log preferencial ('$phpLogDir') não é gravável ou não existe. Usando fallback: '$fallbackLogPath'. PROTEJA ESTE ARQUIVO COM .HTACCESS!");
        $fallbackLoggedCfg = true;
    }
}
/* Exemplo .htaccess na pasta 'meliai' para proteger logs:
<FilesMatch "\.(log)$">
  Require all denied
</FilesMatch>
*/


// --- Carregar Segredos do Arquivo Externo ---
// Define o caminho para o arquivo de segredos.
// Sobe DOIS níveis a partir de BASE_PATH (meliai) para chegar em /home/u.../domains/d3ecom.com.br/
// e então entra na pasta 'meliai_secure'. **Confirme se 'meliai_secure' é o nome correto.**
$secretsFilePath = dirname(dirname(BASE_PATH)) . '/meliai_secure/secrets.php';

// Verifica se o arquivo de segredos existe e é legível.
if (!file_exists($secretsFilePath)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Arquivo de segredos NÃO ENCONTRADO em '$secretsFilePath'. Verifique o caminho e o nome da pasta/arquivo.";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC01). Por favor, contate o suporte.");
}
if (!is_readable($secretsFilePath)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Arquivo de segredos encontrado em '$secretsFilePath' mas NÃO PODE SER LIDO. Verifique as permissões do arquivo (ex: 644 ou 640) e da pasta pai ('meliai_secure', ex: 755 ou 750).";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC02). Por favor, contate o suporte.");
}

// Tenta carregar o array de segredos. Erros de sintaxe no secrets.php causarão erro fatal aqui.
try {
    $secrets = require $secretsFilePath;
} catch (\Throwable $e) { // Captura ParseError ou outros erros ao incluir
    $errorMessage = "ERRO CRÍTICO (config.php): Falha ao incluir/parsear o arquivo de segredos '$secretsFilePath'. Verifique a sintaxe PHP dentro dele. Erro: " . $e->getMessage();
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC03). Por favor, contate o suporte.");
}

// Verifica se o arquivo retornou um array válido
if (!is_array($secrets)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Arquivo de segredos ('$secretsFilePath') não retornou um array PHP válido.";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: SEC04). Por favor, contate o suporte.");
}


// --- Composer Autoloader ---
// Caminho para o autoloader gerado pelo Composer.
$autoloaderPath = BASE_PATH . '/vendor/autoload.php';

// Verifica se o autoloader existe.
if (!file_exists($autoloaderPath)) {
    $errorMessage = "ERRO CRÍTICO (config.php): Autoloader do Composer não encontrado em '$autoloaderPath'. Verifique se a pasta 'vendor' foi enviada corretamente para '" . BASE_PATH . "'.";
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de inicialização do sistema (Code: AUT01). Dependências não encontradas.");
}
if (!is_readable($autoloaderPath)) {
     $errorMessage = "ERRO CRÍTICO (config.php): Autoloader do Composer encontrado em '$autoloaderPath' mas NÃO PODE SER LIDO. Verifique as permissões do arquivo (644) e da pasta 'vendor' (755).";
     error_log($errorMessage);
     http_response_code(500);
     die("Erro crítico de inicialização do sistema (Code: AUT03). Falha de permissão nas dependências.");
}

// Inclui o autoloader. Se houver erro aqui, é provável que o arquivo esteja corrompido.
try {
    require_once $autoloaderPath;
} catch (\Throwable $e) {
     $errorMessage = "ERRO CRÍTICO (config.php): Falha ao executar o autoloader '$autoloaderPath'. Pode estar corrompido. Erro: " . $e->getMessage();
     error_log($errorMessage);
     http_response_code(500);
     die("Erro crítico de inicialização do sistema (Code: AUT02). Falha ao carregar dependências.");
}


// --- Sessão ---
date_default_timezone_set('America/Sao_Paulo');
if (session_status() == PHP_SESSION_NONE) {
    // TODO: Configurar segurança da sessão (via php.ini, user.ini ou ini_set aqui)
    // Exemplo:
    // ini_set('session.cookie_httponly', 1); // Impede acesso via JS
    // ini_set('session.use_strict_mode', 1); // Usa apenas IDs de sessão válidos
    // if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    //     ini_set('session.cookie_secure', 1); // Envia cookie apenas sobre HTTPS
    // }
    session_start();
}


// --- Definição de Constantes Globais ---
// Usa o array $secrets carregado para definir os valores.
// O operador ?? garante um valor padrão caso a chave não exista no $secrets.

// Sistema
define('LOG_FILE', BASE_PATH . '/poll.log'); // Log da aplicação

// Banco de Dados
define('DB_HOST', $secrets['DB_HOST'] ?? 'localhost');
define('DB_NAME', $secrets['DB_NAME'] ?? '');
define('DB_USER', $secrets['DB_USER'] ?? '');
define('DB_PASS', $secrets['DB_PASS'] ?? ''); // Essencial que não seja vazio

// IA (Google Gemini)
define('GOOGLE_API_KEY', $secrets['GOOGLE_API_KEY'] ?? '');
define('GEMINI_API_ENDPOINT', $secrets['GEMINI_API_ENDPOINT'] ?? 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash-lite:generateContent');
define('AI_FALLBACK_TIMEOUT_MINUTES', 3); // Não secreto

// Notificação (Evolution API)
define('EVOLUTION_API_URL', $secrets['EVOLUTION_API_URL'] ?? '');
define('EVOLUTION_INSTANCE_NAME', $secrets['EVOLUTION_INSTANCE_NAME'] ?? '');
define('EVOLUTION_API_KEY', $secrets['EVOLUTION_API_KEY'] ?? '');
define('EVOLUTION_WEBHOOK_TOKEN', $secrets['EVOLUTION_WEBHOOK_TOKEN'] ?? ''); // Se usar

// Aplicação Mercado Livre
define('ML_APP_ID', $secrets['ML_APP_ID'] ?? '');
define('ML_SECRET_KEY', $secrets['ML_SECRET_KEY'] ?? '');
define('ML_REDIRECT_URI', $secrets['ML_REDIRECT_URI'] ?? '');
define('ML_WEBHOOK_SECRET', $secrets['ML_WEBHOOK_SECRET'] ?? ''); // Se usar

// APIs Mercado Livre (Públicas)
define('ML_AUTH_URL', 'https://auth.mercadolivre.com.br/authorization');
define('ML_TOKEN_URL', 'https://api.mercadolibre.com/oauth/token');
define('ML_API_BASE_URL', 'https://api.mercadolibre.com');

// Pagamentos (Asaas)
define('ASAAS_API_URL', $secrets['ASAAS_API_URL'] ?? 'https://api.asaas.com/v3/');
define('ASAAS_API_KEY', $secrets['ASAAS_API_KEY'] ?? '');
define('ASAAS_PLAN_VALUE', 149.90); // Não secreto
define('ASAAS_PLAN_CYCLE', 'QUARTERLY'); // Não secreto
define('ASAAS_PLAN_DESCRIPTION', 'Meli AI - Assinatura Trimestral'); // Não secreto
define('ASAAS_WEBHOOK_URL', $secrets['ASAAS_WEBHOOK_URL'] ?? ''); // Não secreto
define('ASAAS_WEBHOOK_SECRET', $secrets['ASAAS_WEBHOOK_SECRET'] ?? ''); // Essencial
define('ASAAS_USER_AGENT', 'MeliAI/1.0 (PHP; producao)');


// --- Segurança (CRIPTOGRAFIA) ---
// A chave 'APP_ENCRYPTION_KEY' está no array $secrets e será usada diretamente
// pela função loadEncryptionKey() em db.php. Nenhuma constante definida aqui.


// --- Verificação Final de Configurações Críticas ---
// Garante que as constantes/segredos mais importantes não estão vazios.
$criticalConfigs = [
    'DB_PASS', 'GOOGLE_API_KEY', 'ML_SECRET_KEY', 'ASAAS_API_KEY',
    'ASAAS_WEBHOOK_SECRET', 'EVOLUTION_API_KEY', 'APP_ENCRYPTION_KEY'
];
$missingConfig = [];
foreach ($criticalConfigs as $key) {
    // Verifica se a chave existe no array $secrets e não é vazia
    if (empty($secrets[$key])) {
        $missingConfig[] = $key . ' (em secrets.php)';
    }
}

if (!empty($missingConfig)) {
    $errorMessage = "ERRO CRÍTICO Config: Segredos essenciais não definidos ou vazios no arquivo '$secretsFilePath': " . implode(', ', $missingConfig);
    error_log($errorMessage);
    http_response_code(500);
    die("Erro crítico de configuração do servidor (Code: CFG05). Chaves essenciais ausentes. Contate o administrador.");
}

// --- Inclusão de Helpers Essenciais ---
// Inclui helpers DEPOIS que toda a configuração e o autoloader estão prontos.
// Garante que as funções de log e curl estejam disponíveis globalmente se necessário.
require_once BASE_PATH . '/includes/log_helper.php';
require_once BASE_PATH . '/includes/curl_helper.php';
// Inclua outros helpers globais aqui, se houver (ex: helpers.php)
require_once BASE_PATH . '/includes/helpers.php';


// --- Log de Sucesso (Opcional para Debug) ---
// static $configLoaded = false;
// if (!$configLoaded) {
//    if (function_exists('logMessage')) { logMessage("Configuração Meli AI (v2.1) carregada com sucesso."); }
//    else { error_log("Configuração Meli AI (v2.1) carregada com sucesso."); }
//    $configLoaded = true;
// }

