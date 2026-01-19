<?php
/**
 * Arquivo: includes/asaas_api.php
 * Versão: v1.4 - Adiciona getAsaasPendingPaymentLink
 * Descrição: Funções para interagir com a API REST do Asaas v3.
 *            Inclui criação/busca de cliente, criação de assinatura e busca de link pendente/vencido.
 *            !! VERIFIQUE a documentação Asaas V3 para campos mínimos obrigatórios em /customers !!
 */

require_once __DIR__ . '/../config.php'; // Para constantes ASAAS_API_URL, ASAAS_API_KEY
require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/curl_helper.php'; // Usando v1.3 (URL primeiro, httpCode)

/**
 * Cria um novo cliente na plataforma Asaas ou busca um existente.
 * Tenta criar via POST, se falhar por duplicidade (400), tenta buscar via GET.
 *
 * @param string      $name         Nome completo do cliente.
 * @param string      $email        E-mail do cliente.
 * @param string      $cpfCnpj      CPF ou CNPJ (apenas dígitos, será normalizado).
 * @param string|null $externalRef  Referência externa opcional (ex: ID do usuário no seu sistema).
 * @return array|null               Retorna array de dados do cliente Asaas ou null em caso de falha.
 */
function createAsaasCustomer($name, $email, $cpfCnpj, $externalRef = null)
{
    $cpfCnpjCleaned = preg_replace('/\D/', '', $cpfCnpj ?? '');
    logMessage("[Asaas API v1.4 createCustomer] Tentando criar/buscar cliente: Email=$email, CPF/CNPJ=$cpfCnpjCleaned");

    if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY')) {
        logMessage("[Asaas API v1.4 createCustomer] ERRO: Constantes ASAAS não definidas.");
        return null;
    }

    $url = rtrim(ASAAS_API_URL, '/') . '/customers'; // Endpoint de criação
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: ' . ASAAS_USER_AGENT,
        'access_token: ' . ASAAS_API_KEY
    ];

    // Payload (simplificado - adicione 'mobilePhone' se necessário)
    $postData = [
        'name'              => $name,
        'email'             => $email,
        'cpfCnpj'           => $cpfCnpjCleaned,
        'externalReference' => $externalRef
    ];

    logMessage("[Asaas API v1.4 createCustomer] Payload POST /customers: " . json_encode($postData));

    // ORDEM CORRETA: URL primeiro, depois método
    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);

    if ($result['httpCode'] >= 200 && $result['httpCode'] < 300 && $result['is_json'] && isset($result['response']['id'])) {
        // Sucesso na criação
        logMessage("[Asaas API v1.4 createCustomer] Cliente criado com sucesso. ID= " . $result['response']['id']);
        return $result['response'];
    }

    // Se deu erro 400, pode ser porque o cliente já existe (mesmo CPF/CNPJ ou e-mail).
    if ($result['httpCode'] == 400) {
        $errorMsg = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
        logMessage("[Asaas API v1.4 createCustomer] POST /customers retornou 400. Possível cliente já existente. Resp: $errorMsg");
        // Tenta buscar por e-mail ou cpf/cnpj:
        return findAsaasCustomerByEmailOrCpf($email, $cpfCnpjCleaned);
    }

    // Outros erros
    $errorResp = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
    logMessage("[Asaas API v1.4 createCustomer] ERRO no POST /customers. HTTP: {$result['httpCode']}. cURL Error: {$result['error']}. API Resp: $errorResp");

    return null;
}

/**
 * Busca cliente Asaas por e-mail ou CPF/CNPJ.
 * É chamada em caso de erro 400 na criação via POST (para tentar recuperar um cliente existente).
 *
 * @param string|null $email
 * @param string|null $cpfCnpj
 * @return array|null Retorna array com dados do cliente, ou null se não encontrado/erro.
 */
function findAsaasCustomerByEmailOrCpf($email = null, $cpfCnpj = null)
{
    if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY')) {
        logMessage("[Asaas API v1.4 findCustomer] ERRO: Constantes ASAAS não definidas.");
        return null;
    }

    $queryParams = [];
    if (!empty($email)) {
        $queryParams['email'] = $email;
    }
    if (!empty($cpfCnpj)) {
        $queryParams['cpfCnpj'] = preg_replace('/\D/', '', $cpfCnpj);
    }

    if (empty($queryParams)) {
        logMessage("[Asaas API v1.4 findCustomer] ERRO: Nenhum parâmetro (email/cpfCnpj) fornecido.");
        return null;
    }

    $url = rtrim(ASAAS_API_URL, '/') . '/customers?' . http_build_query($queryParams);
    $headers = [
        'Accept: application/json',
        'User-Agent: ' . ASAAS_USER_AGENT,
        'access_token: ' . ASAAS_API_KEY
    ];

    logMessage("[Asaas API v1.4 findCustomer] GET /customers com query: " . http_build_query($queryParams));

    $result = makeCurlRequest($url, 'GET', $headers);

    if ($result['httpCode'] >= 200 && $result['httpCode'] < 300 && $result['is_json']) {
        // A API do Asaas retorna:
        // {
        //   "hasMore": false,
        //   "totalCount": 1,
        //   "limit": 10,
        //   "offset": 0,
        //   "data": [ { cliente1 }, { cliente2 }, ... ]
        // }
        if (!empty($result['response']['data']) && is_array($result['response']['data'])) {
            $firstCustomer = $result['response']['data'][0];
            logMessage("[Asaas API v1.4 findCustomer] Cliente encontrado. ID= " . ($firstCustomer['id'] ?? 'N/A'));
            return $firstCustomer;
        } else {
            logMessage("[Asaas API v1.4 findCustomer] Nenhum cliente encontrado com os parâmetros informados.");
            return null;
        }
    }

    $errorResp = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
    logMessage("[Asaas API v1.4 findCustomer] ERRO no GET /customers. HTTP: {$result['httpCode']}. cURL Error: {$result['error']}. API Resp: $errorResp");
    return null;
}

/**
 * Cria uma assinatura (subscription) no Asaas e retorna o objeto criado.
 *
 * @param string      $customerId   ID do cliente no Asaas.
 * @param float       $value        Valor da assinatura.
 * @param string      $billingType  Tipo de cobrança (ex: 'BOLETO', 'CREDIT_CARD', 'PIX').
 * @param string      $cycle        'MONTHLY', 'YEARLY', etc.
 * @param string      $description  Descrição da assinatura.
 * @param string|null $externalRef  Referência externa (id assinatura no seu sistema).
 * @return array|null               Dados da assinatura criada ou null em caso de erro.
 */
function createAsaasSubscription($customerId, $value, $billingType, $cycle, $description, $externalRef = null)
{
    if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY')) {
        logMessage("[Asaas API v1.4 createSub] ERRO: Constantes ASAAS não definidas.");
        return null;
    }

    $url = rtrim(ASAAS_API_URL, '/') . '/subscriptions';
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: ' . ASAAS_USER_AGENT,
        'access_token: ' . ASAAS_API_KEY
    ];

    $postData = [
        'customer'          => $customerId,
        'billingType'       => $billingType,
        'value'             => $value,
        'cycle'             => $cycle,
        'description'       => $description,
        'externalReference' => $externalRef,
        // Se quiser, você pode adicionar "nextDueDate", "endDate", etc., dependendo do seu modelo de recorrência.
    ];

    logMessage("[Asaas API v1.4 createSub] Payload POST /subscriptions: " . json_encode($postData));

    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);

    if ($result['httpCode'] >= 200 && $result['httpCode'] < 300 && $result['is_json'] && isset($result['response']['id'])) {
        logMessage("[Asaas API v1.4 createSub] Assinatura criada com sucesso. ID= " . $result['response']['id']);
        return $result['response'];
    }

    $errorResp = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
    logMessage("[Asaas API v1.4 createSub] ERRO no POST /subscriptions. HTTP: {$result['httpCode']}. cURL Error: {$result['error']}. API Resp: $errorResp");
    return null;
}

/**
 * Cria assinatura e retorna data da assinatura + eventual link de pagamento da primeira cobrança.
 *
 * @param string      $customerId        ID do cliente no Asaas.
 * @param string|null $externalReference Referência externa para a assinatura (ex: ID SaaS user).
 *
 * @return array|null Retorna array com dados da assinatura Asaas
 *                    (incluindo, se possível, 'paymentLink' e 'paymentId'), ou null em caso de falha.
 */
function createAsaasSubscriptionRedirect(string $customerId, ?string $externalReference = null): ?array
{
    logMessage("[Asaas API v1.4 createSub] Tentando criar assinatura (Redirect) para Customer ID: $customerId");

    // Garante que todas as constantes necessárias existem
    if (
        !defined('ASAAS_API_URL') ||
        !defined('ASAAS_API_KEY') ||
        !defined('ASAAS_PLAN_VALUE') ||
        !defined('ASAAS_PLAN_CYCLE') ||
        !defined('ASAAS_PLAN_DESCRIPTION')
    ) {
        logMessage("[Asaas API v1.4 createSub] ERRO: Constantes ASAAS assinatura não definidas.");
        return null;
    }

    $url = rtrim(ASAAS_API_URL, '/') . '/subscriptions';

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: ' . ASAAS_USER_AGENT,
        'access_token: ' . ASAAS_API_KEY,
    ];

    // De acordo com a doc: customer, billingType, nextDueDate, value, cycle, description
    $nextDueDate = date('Y-m-d', strtotime('+3 days'));

    $postData = [
        'customer'          => $customerId,
        // Se você quer que o cliente escolha forma de pagamento na fatura, pode usar UNDEFINED
        // ou usar BOLETO / PIX / CREDIT_CARD direto, conforme o modelo
        'billingType'       => 'UNDEFINED',
        'value'             => ASAAS_PLAN_VALUE,
        'nextDueDate'       => $nextDueDate,
        'cycle'             => ASAAS_PLAN_CYCLE,
        'description'       => ASAAS_PLAN_DESCRIPTION,
        'externalReference' => $externalReference,
    ];

    logMessage("[Asaas API v1.4 createSub] Payload POST /subscriptions: " . json_encode($postData));

    $result = makeCurlRequest($url, 'POST', $headers, $postData, true);

    if (
        ($result['httpCode'] == 200 || $result['httpCode'] == 201) &&
        $result['is_json'] &&
        isset($result['response']['id'])
    ) {
        $subscriptionData = $result['response'];
        logMessage("[Asaas API v1.4 createSub] Assinatura criada. ID Asaas: " . $subscriptionData['id']);

        // Para pegar cobranças da assinatura: GET /v3/subscriptions/{id}/payments
        $paymentsUrl = rtrim(ASAAS_API_URL, '/') . '/subscriptions/' . $subscriptionData['id'] . '/payments';
        $paymentsHeaders = [
            'Accept: application/json',
            'User-Agent: ' . ASAAS_USER_AGENT,
            'access_token: ' . ASAAS_API_KEY,
        ];

        $paymentsResult = makeCurlRequest($paymentsUrl, 'GET', $paymentsHeaders);

        $paymentLink = null;
        if (
            $paymentsResult['httpCode'] == 200 &&
            $paymentsResult['is_json'] &&
            !empty($paymentsResult['response']['data'][0])
        ) {
            $firstPayment = $paymentsResult['response']['data'][0];

            $paymentLink =
                $firstPayment['invoiceUrl']
                ?? $firstPayment['bankSlipUrl']
                ?? $firstPayment['transactionReceiptUrl']
                ?? null;

            $subscriptionData['paymentId'] = $firstPayment['id'] ?? null;
        } else {
            logMessage("[Asaas API v1.4 createSub] Não foi possível obter a 1ª cobrança da assinatura via /subscriptions/{id}/payments.");
        }

        $subscriptionData['paymentLink'] = $paymentLink;
        return $subscriptionData;
    }

    // Erro na criação
    $errorDetails = $result['is_json']
        ? json_encode($result['response'])
        : ($result['response'] ?? 'N/A');

    logMessage("[Asaas API v1.4 createSub] ERRO criar assinatura para Customer ID $customerId. HTTP: {$result['httpCode']}. API Resp: " . $errorDetails);

    if ($result['httpCode'] == 404) {
        logMessage("[Asaas API v1.4 createSub] !!! ALERTA 404 !!! Verifique URL base, endpoint ou API Key.");
    }

    return null;
}

/**
 * Retorna link de pagamento PENDENTE ou OVERDUE para uma determinada assinatura.
 *
 * @param string      $subscriptionId ID da assinatura no Asaas.
 * @param string|null $billingType    Filtro opcional de tipo de cobrança (BOLETO, PIX, etc).
 * @return string|null                URL do link de pagamento ou null se não encontrado.
 */
function getAsaasPendingPaymentLink($subscriptionId, $billingType = null)
{
    if (!defined('ASAAS_API_URL') || !defined('ASAAS_API_KEY')) {
        logMessage("[Asaas API v1.4 getLink] ERRO: Constantes ASAAS não definidas.");
        return null;
    }

    $baseUrl = rtrim(ASAAS_API_URL, '/') . '/payments';

    // Vamos tentar primeiro status=PENDING, depois status=OVERDUE
    $statusList = ['PENDING', 'OVERDUE'];

    $paymentUrlFound = null;

    foreach ($statusList as $status) {
        $queryParams = [
            'subscription' => $subscriptionId,
            'status'       => $status,
        ];
        if (!empty($billingType)) {
            $queryParams['billingType'] = $billingType;
        }

        $url = $baseUrl . '?' . http_build_query($queryParams);
        $headers = [
            'Accept: application/json',
            'User-Agent: ' . ASAAS_USER_AGENT,
            'access_token: ' . ASAAS_API_KEY
        ];

        logMessage("[Asaas API v1.4 getLink] GET /payments com query: " . http_build_query($queryParams));

        $result = makeCurlRequest($url, 'GET', $headers);

        if ($result['httpCode'] >= 200 && $result['httpCode'] < 300 && $result['is_json']) {
            if (!empty($result['response']['data']) && is_array($result['response']['data'])) {
                foreach ($result['response']['data'] as $payment) {
                    // Procurar campos típicos de URL:
                    if (!empty($payment['invoiceUrl'])) {
                        $paymentUrlFound = $payment['invoiceUrl'];
                        break;
                    }
                    if (!empty($payment['bankSlipUrl'])) {
                        $paymentUrlFound = $payment['bankSlipUrl'];
                        break;
                    }
                    if (!empty($payment['transactionReceiptUrl'])) {
                        $paymentUrlFound = $payment['transactionReceiptUrl'];
                        break;
                    }
                }
                if ($paymentUrlFound) {
                    logMessage("[Asaas API v1.4 getLink] Link de pagamento encontrado para status $status: $paymentUrlFound");
                    break; // Sai do foreach statusList
                } else {
                    logMessage("[Asaas API v1.4 getLink] Nenhum campo de URL (invoiceUrl/bankSlipUrl/transactionReceiptUrl) encontrado na resposta para status $status.");
                }
            } else {
                logMessage("[Asaas API v1.4 getLink] Nenhum payment encontrado para subscription $subscriptionId com status $status.");
            }
        } else {
            $errorResp = $result['is_json'] ? json_encode($result['response']) : ($result['response'] ?? 'N/A');
            logMessage("[Asaas API v1.4 getLink] ERRO no GET /payments para status $status. HTTP: {$result['httpCode']}. cURL Error: {$result['error']}. API Resp: " . $errorResp);
            // Continua para o próximo status se houver
        }
    } // Fim foreach status

    if ($paymentUrlFound) {
        logMessage("[Asaas API v1.4 getLink] Link de pagamento final encontrado para Sub ID $subscriptionId: $paymentUrlFound");
    } else {
        logMessage("[Asaas API v1.4 getLink] Nenhum link de pagamento PENDENTE ou OVERDUE encontrado para Sub ID $subscriptionId.");
    }

    return $paymentUrlFound;
}

?>
