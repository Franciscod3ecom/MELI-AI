<?php
/**
 * Arquivo: includes/ml_api.php
 * Versão: v1.1 - Adiciona busca de descrição e detalhes do usuário.
 * Descrição: Funções para interagir com a API do Mercado Livre.
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php';
// As constantes (ML_TOKEN_URL, ML_APP_ID, etc.) devem ser definidas em config.php
// e config.php deve ser incluído *antes* de incluir este arquivo nos scripts principais.

/**
 * Renova o Access Token do Mercado Livre usando o Refresh Token.
 * @param string $refreshToken O Refresh Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API de token.
 */
function refreshMercadoLibreToken(string $refreshToken): array
{
    if (!defined('ML_TOKEN_URL') || !defined('ML_APP_ID') || !defined('ML_SECRET_KEY')) {
        logMessage("[refreshMercadoLibreToken] ERRO: Constantes ML_TOKEN_URL, ML_APP_ID ou ML_SECRET_KEY não definidas.");
        return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false];
    }
    $url = ML_TOKEN_URL;
    $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
    $postData = [ 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => ML_APP_ID, 'client_secret' => ML_SECRET_KEY ];
    logMessage("[refreshMercadoLibreToken] Enviando requisição de refresh para API ML...");
    $result = makeCurlRequest($url, 'POST', $headers, $postData, false);
    logMessage("[refreshMercadoLibreToken] Resultado do refresh: HTTP {$result['httpCode']}. Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    return $result;
}

/**
 * Busca perguntas não respondidas para um vendedor específico no Mercado Livre,
 * suportando filtro de data e paginação (limit/offset).
 * @param int|string $sellerId O ID do vendedor no Mercado Livre.
 * @param string $accessToken O Access Token válido (descriptografado).
 * @param string|null $dateFrom Data inicial (formato ISO 8601) para buscar perguntas a partir desta data. Se null, não filtra.
 * @param int $limit O número máximo de perguntas a serem retornadas por chamada (Padrão: 50).
 * @param int $offset O deslocamento (índice inicial) para a paginação (Padrão: 0).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function getMercadoLibreQuestions($sellerId, string $accessToken, ?string $dateFrom = null, int $limit = 50, int $offset = 0): array
{
     if (!defined('ML_API_BASE_URL')) {
        logMessage("[getMercadoLibreQuestions] ERRO: Constante ML_API_BASE_URL não definida.");
        return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false];
    }
    if ($limit <= 0 || $limit > 50) { logMessage("[getMercadoLibreQuestions] Aviso: Limite inválido ($limit) ajustado para 50."); $limit = 50; }
    if ($offset < 0) { logMessage("[getMercadoLibreQuestions] Aviso: Offset negativo ($offset) ajustado para 0."); $offset = 0; }
    $queryParams = [ 'seller_id' => $sellerId, 'status' => 'UNANSWERED', 'sort' => 'date_created_desc', 'limit' => $limit, 'offset' => $offset ];
    $dateFilterLog = 'N/A';
    if ($dateFrom !== null && !empty($dateFrom)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?([+-]\d{2}:\d{2}|Z)$/', $dateFrom)) {
             $queryParams['date_created_from'] = $dateFrom; $dateFilterLog = $dateFrom;
        } else { logMessage("[getMercadoLibreQuestions] AVISO: Formato data inválido date_created_from: '$dateFrom'. Ignorando filtro."); }
    }
    $url = ML_API_BASE_URL . '/questions/search?' . http_build_query($queryParams);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];
    $tokenPreview = 'Bearer ' . substr($accessToken, 0, 8) . '...' . substr($accessToken, -4);
    logMessage("[getMercadoLibreQuestions] Buscando perguntas: ML ID: $sellerId, Limit: $limit, Offset: $offset, DateFrom: $dateFilterLog (Token: $tokenPreview)");
    $result = makeCurlRequest($url, 'GET', $headers);
    $questionCount = ($result['httpCode'] == 200 && isset($result['response']['questions'])) ? count($result['response']['questions']) : 0;
    $totalApi = $result['httpCode'] == 200 && isset($result['response']['total']) ? $result['response']['total'] : 'N/A';
    logMessage("[getMercadoLibreQuestions] Resultado busca para $sellerId (Offset $offset): HTTP {$result['httpCode']}, Perguntas retornadas: {$questionCount} (Total API: {$totalApi}), Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    return $result;
}

/**
 * Obtém detalhes de um item (anúncio) específico no Mercado Livre.
 * @param string $itemId O ID do item (formato MLBxxxxxxxxx).
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function getMercadoLibreItemDetails(string $itemId, string $accessToken): array
{
    if (!defined('ML_API_BASE_URL')) { logMessage("[getMercadoLibreItemDetails] ERRO: Constante ML_API_BASE_URL não definida."); return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false]; }
    $url = ML_API_BASE_URL . '/items/' . $itemId . '?include_attributes=all';
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];
    logMessage("[getMercadoLibreItemDetails] Buscando detalhes do item ML: $itemId");
    $result = makeCurlRequest($url, 'GET', $headers);
    logMessage("[getMercadoLibreItemDetails] Resultado detalhes item $itemId: HTTP {$result['httpCode']}. Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    return $result;
}

/**
 * Obtém a descrição em texto plano de um item (anúncio) específico.
 * @param string $itemId O ID do item (formato MLBxxxxxxxxx).
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return string|null O texto da descrição ou null se não encontrada ou em caso de erro.
 */
function getMercadoLivreItemDescription(string $itemId, string $accessToken): ?string
{
    if (!defined('ML_API_BASE_URL')) {
        logMessage("[getMLItemDescription] ERRO: Constante ML_API_BASE_URL não definida.");
        return null;
    }
    $url = ML_API_BASE_URL . '/items/' . $itemId . '/description';
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];

    logMessage("[getMLItemDescription] Buscando descrição do item ML: $itemId");
    $result = makeCurlRequest($url, 'GET', $headers);

    if ($result['httpCode'] == 200 && $result['is_json'] && isset($result['response']['plain_text'])) {
        logMessage("[getMLItemDescription] Descrição encontrada para o item $itemId.");
        return $result['response']['plain_text'];
    } else {
        logMessage("[getMLItemDescription] AVISO: Descrição não encontrada ou erro na API para item $itemId. HTTP: {$result['httpCode']}.");
        return null;
    }
}

/**
 * Obtém detalhes de um usuário do Mercado Livre, como o nickname.
 * @param int|string $userId O ID do usuário ML.
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed>|null Os dados do usuário ou null em caso de erro.
 */
function getMercadoLivreUserDetails($userId, string $accessToken): ?array
{
    if (!defined('ML_API_BASE_URL')) {
        logMessage("[getMLUserDetails] ERRO: Constante ML_API_BASE_URL não definida.");
        return null;
    }
    $url = ML_API_BASE_URL . '/users/' . $userId;
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];

    logMessage("[getMLUserDetails] Buscando detalhes do usuário ML: $userId");
    $result = makeCurlRequest($url, 'GET', $headers);

    if ($result['httpCode'] == 200 && $result['is_json']) {
        return $result['response'];
    } else {
        logMessage("[getMLUserDetails] AVISO: Não foi possível obter detalhes do usuário $userId. HTTP: {$result['httpCode']}.");
        return null;
    }
}

/**
 * Verifica o status atual de uma pergunta específica no Mercado Livre.
 * @param int $questionId O ID da pergunta.
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function getMercadoLibreQuestionStatus(int $questionId, string $accessToken): array
{
    if (!defined('ML_API_BASE_URL')) { logMessage("[getMercadoLibreQuestionStatus] ERRO: Constante ML_API_BASE_URL não definida."); return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false]; }
    $url = ML_API_BASE_URL . '/questions/' . $questionId;
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];
    logMessage("[getMercadoLibreQuestionStatus] Verificando status ML da QID: $questionId");
    $result = makeCurlRequest($url, 'GET', $headers);
    $status = $result['is_json'] && isset($result['response']['status']) ? $result['response']['status'] : 'ERRO_API/NAO_JSON';
    logMessage("[getMercadoLibreQuestionStatus] Status ML retornado para QID $questionId: '$status' (HTTP: {$result['httpCode']}). Erro cURL: " . ($result['error'] ?? 'Nenhum'));
    if ($result['httpCode'] !== 200) { logMessage("[getMercadoLibreQuestionStatus] AVISO: Falha ao buscar status ML da QID $questionId. Code: {$result['httpCode']}. Response: " . json_encode($result['response'])); }
    return $result;
}

/**
 * Posta uma resposta para uma pergunta no Mercado Livre.
 * @param int $questionId O ID da pergunta a ser respondida.
 * @param string $responseText O texto da resposta.
 * @param string $accessToken O Access Token válido (descriptografado).
 * @return array<string, mixed> O resultado da chamada à API.
 */
function postMercadoLibreAnswer(int $questionId, string $responseText, string $accessToken): array
{
     if (!defined('ML_API_BASE_URL')) { logMessage("[postMercadoLibreAnswer] ERRO: Constante ML_API_BASE_URL não definida."); return ['httpCode' => 0, 'error' => 'Configuração ML incompleta.', 'response' => null, 'is_json' => false]; }
    $url = ML_API_BASE_URL . '/answers';
    $headers = ['Authorization: Bearer ' . $accessToken];
    $postData = ['question_id' => $questionId, 'text' => $responseText];
    $logTextPreview = mb_substr($responseText, 0, 50) . (mb_strlen($responseText) > 50 ? '...' : '');
    logMessage("[postMercadoLibreAnswer] Enviando resposta para QID: $questionId. Texto Preview: '$logTextPreview'");
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);
    logMessage("[postMercadoLibreAnswer] Resultado do POST para QID $questionId: HTTP {$result['httpCode']}. Erro cURL: " . ($result['error'] ?? 'Nenhum') . ". Response: " . json_encode($result['response']));
    return $result;
}
?>