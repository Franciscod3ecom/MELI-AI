<?php
/**
 * Arquivo: oauth_callback.php
 * Versão: v1.1 - Atualiza includes após refatoração
 * Descrição: Recebe o retorno do Mercado Livre após autorização do usuário,
 *            troca o código de autorização por tokens e salva no banco de dados.
 */

// Includes Essenciais Refatorados
require_once __DIR__ . '/config.php'; // Constantes ML, DB, Session
require_once __DIR__ . '/db.php';     // Conexão DB e Funções de Criptografia (encryptData - !!PLACEHOLDER!!)
require_once __DIR__ . '/includes/log_helper.php'; // logMessage
require_once __DIR__ . '/includes/curl_helper.php';// makeCurlRequest

logMessage("[OAuth Callback v1.1] Recebido.");

// --- 1. Verificar se o usuário SaaS ainda está logado ---
if (!isset($_SESSION['saas_user_id'])) {
     logMessage("Erro Callback: Usuário SaaS não logado na sessão. Redirecionando para login.");
     header('Location: login.php?error=session_expired');
     exit;
}
$saasUserIdFromSession = $_SESSION['saas_user_id'];
logMessage("Callback: Sessão SaaS ativa para User ID: $saasUserIdFromSession");

// --- 2. Segurança: Validar o parâmetro 'state' (CSRF) ---
$receivedState = $_GET['state'] ?? null;
$expectedState = $_SESSION['oauth_state_expected'] ?? null;

// Limpa o state esperado da sessão imediatamente após lê-lo, independentemente do resultado
unset($_SESSION['oauth_state_expected']);

if (empty($receivedState) || empty($expectedState) || !hash_equals($expectedState, $receivedState)) {
    logMessage("Erro Callback CSRF: Estado OAuth inválido para SaaS User ID $saasUserIdFromSession. Recebido: '$receivedState' Esperado: '$expectedState'");
    header('Location: dashboard.php?status=ml_error&code=csrf_token_mismatch#conexao');
    exit;
}
logMessage("Callback: State CSRF validado OK para SaaS User ID: $saasUserIdFromSession.");

// Decodificar o state para verificar o UID interno (verificação adicional opcional mas recomendada)
$stateDecoded = json_decode(base64_decode($receivedState), true);
if (!$stateDecoded || !isset($stateDecoded['uid']) || $stateDecoded['uid'] != $saasUserIdFromSession) {
     logMessage("Erro Callback State Payload: UID no state não corresponde ao UID da sessão ($saasUserIdFromSession). State: '$receivedState'");
     header('Location: dashboard.php?status=ml_error&code=state_payload_mismatch#conexao');
     exit;
}
logMessage("Callback: State Payload UID verificado OK.");

// --- 3. Verificar se o código de autorização foi recebido ---
$code = $_GET['code'] ?? null;
if (empty($code)) {
    $error = $_GET['error'] ?? 'no_code';
    $errorDesc = $_GET['error_description'] ?? 'Código de autorização não recebido.';
    logMessage("Erro Callback: Código não recebido do ML para SaaS User ID $saasUserIdFromSession. Erro ML: $error - $errorDesc");
    header('Location: dashboard.php?status=ml_error&code=' . urlencode($error) . '#conexao');
    exit;
}
logMessage("Callback: Código de autorização recebido OK para SaaS User ID $saasUserIdFromSession.");

// --- 4. Trocar o código por tokens (Access Token e Refresh Token) ---
if (!defined('ML_TOKEN_URL') || !defined('ML_APP_ID') || !defined('ML_SECRET_KEY') || !defined('ML_REDIRECT_URI')) {
    logMessage("Erro Callback: Constantes de configuração ML ausentes.");
    header('Location: dashboard.php?status=ml_error&code=config_error#conexao');
    exit;
}
$tokenUrl = ML_TOKEN_URL;
$postData = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => ML_APP_ID,
    'client_secret' => ML_SECRET_KEY,
    'redirect_uri'  => ML_REDIRECT_URI // Essencial que seja EXATAMENTE a mesma
];
$headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];

logMessage("Callback: Trocando código por tokens na URL: $tokenUrl para SaaS User ID $saasUserIdFromSession");
$result = makeCurlRequest($tokenUrl, 'POST', $headers, $postData, false); // false = form-urlencoded

// --- 5. Validar Resposta da Troca de Tokens ---
if ($result['httpCode'] != 200 || !$result['is_json']) {
    logMessage("Erro Callback: Falha ao obter tokens do ML para SaaS User ID $saasUserIdFromSession. HTTP Code: {$result['httpCode']}. Response: " . json_encode($result['response']));
    $errorCode = 'token_fetch_failed_' . $result['httpCode'];
    header('Location: dashboard.php?status=ml_error&code=' . urlencode($errorCode) . '#conexao');
    exit;
}

$tokenData = $result['response'];
// Log apenas parcial dos tokens por segurança
$logTokenPreview = json_encode([
    'user_id' => $tokenData['user_id'] ?? 'N/A',
    'access_token_start' => isset($tokenData['access_token']) ? substr($tokenData['access_token'], 0, 8).'...' : 'N/A',
    'refresh_token_start' => isset($tokenData['refresh_token']) ? substr($tokenData['refresh_token'], 0, 8).'...' : 'N/A',
    'expires_in' => $tokenData['expires_in'] ?? 'N/A'
]);
logMessage("Callback: Resposta de Tokens recebida para SaaS User ID $saasUserIdFromSession: " . $logTokenPreview);

// Verificar campos essenciais na resposta
if (!isset($tokenData['access_token']) || !isset($tokenData['refresh_token']) || !isset($tokenData['user_id'])) {
    logMessage("Erro Callback: Resposta de token inválida do ML (campos faltando) para SaaS User ID $saasUserIdFromSession. Resp: " . json_encode($tokenData));
    header('Location: dashboard.php?status=ml_error&code=invalid_token_response#conexao');
    exit;
}

// --- 6. Extrair Dados e Salvar/Atualizar no Banco de Dados ---
$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'];
$mlUserId = $tokenData['user_id'];
$expiresIn = $tokenData['expires_in'] ?? 21600; // Padrão 6 horas se não vier
$tokenExpiresAt = (new DateTimeImmutable())->modify("+" . (int)$expiresIn . " seconds")->format('Y-m-d H:i:s');

try {
    // !! INSECURE PLACEHOLDER ENCRYPTION - SUBSTITUIR !!
    logMessage("Callback: Criptografando tokens (placeholder) para ML ID: $mlUserId / SaaS ID: $saasUserIdFromSession");
    $encryptedAccessToken = encryptData($accessToken);
    $encryptedRefreshToken = encryptData($refreshToken);
    // !! --------------------------------------------- !!

    $pdo = getDbConnection();

    // Usar INSERT ... ON DUPLICATE KEY UPDATE para tratar novos usuários e atualizações
    // A chave única deve ser em (saas_user_id, ml_user_id) ou apenas ml_user_id se um usuário ML só pode conectar a um SaaS user.
    // Assumindo UNIQUE KEY `idx_ml_user_id` (`ml_user_id`) na tabela `mercadolibre_users`
    $sql = "INSERT INTO mercadolibre_users (saas_user_id, ml_user_id, access_token, refresh_token, token_expires_at, is_active, created_at, updated_at)
            VALUES (:saas_user_id, :ml_user_id, :access_token, :refresh_token, :token_expires_at, TRUE, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                saas_user_id = VALUES(saas_user_id), -- Garante que se o ML user já existia mas com outro SaaS ID, ele seja atualizado para o SaaS ID atual
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                token_expires_at = VALUES(token_expires_at),
                is_active = TRUE, -- Reativa a conexão se estava inativa
                updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        ':saas_user_id' => $saasUserIdFromSession,
        ':ml_user_id' => $mlUserId,
        ':access_token' => $encryptedAccessToken,  // Criptografado
        ':refresh_token' => $encryptedRefreshToken, // Criptografado
        ':token_expires_at' => $tokenExpiresAt
    ]);

    if ($success) {
        $action = ($stmt->rowCount() > 1) ? 'atualizados' : 'salvos'; // INSERT retorna 1, UPDATE retorna 1 ou 2 (MySQL)
        logMessage("Callback: Tokens $action com sucesso (usando cripto placeholder) para ML ID: $mlUserId (SaaS ID: $saasUserIdFromSession)");
        // Redireciona para o dashboard com sucesso, focando na aba de conexão
        header('Location: dashboard.php?status=ml_connected#conexao');
        exit;
    } else {
        logMessage("Erro Callback SQL: Falha ao executar save/update de tokens para SaaS ID $saasUserIdFromSession / ML ID $mlUserId.");
        header('Location: dashboard.php?status=ml_error&code=db_save_failed#conexao');
        exit;
    }

} catch (\PDOException $e) {
    logMessage("Erro Callback DB Exception para SaaS ID $saasUserIdFromSession: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    $errorCode = 'db_error';
    if ($e->getCode() == 23000) { // Código SQLSTATE para violação de chave única/primária
        logMessage("Erro Callback: Tentativa de inserir entrada duplicada para ML User ID $mlUserId ou SaaS User ID $saasUserIdFromSession, mas ON DUPLICATE KEY falhou?");
        $errorCode = 'db_duplicate_error'; // Pode indicar problema na definição da chave UNIQUE
    }
    header('Location: dashboard.php?status=ml_error&code=' . $errorCode . '#conexao');
    exit;
} catch (\Exception $e) { // Captura erros de criptografia também
    logMessage("Erro Callback Geral/Cripto Exception para SaaS ID $saasUserIdFromSession: " . $e->getMessage());
    header('Location: dashboard.php?status=ml_error&code=internal_error#conexao');
    exit;
}
?>