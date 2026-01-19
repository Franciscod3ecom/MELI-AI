# 🔐 Análise de Segurança - Meli AI

> **Versão:** 1.0  
> **Data da Análise:** 19 de Janeiro de 2026  
> **Classificação:** CONFIDENCIAL - Uso Interno  
> **Analista:** Equipe de Segurança

---

## 📋 Índice

1. [Resumo Executivo](#-resumo-executivo)
2. [Metodologia de Análise](#-metodologia-de-análise)
3. [Classificação de Severidade](#-classificação-de-severidade)
4. [Vulnerabilidades Críticas](#-vulnerabilidades-críticas)
5. [Vulnerabilidades Altas](#-vulnerabilidades-altas)
6. [Vulnerabilidades Médias](#-vulnerabilidades-médias)
7. [Vulnerabilidades Baixas](#-vulnerabilidades-baixas)
8. [Boas Práticas Implementadas](#-boas-práticas-implementadas)
9. [Recomendações Gerais](#-recomendações-gerais)
10. [Checklist de Correções](#-checklist-de-correções)
11. [Referências OWASP](#-referências-owasp)

---

## 📊 Resumo Executivo

### Estatísticas da Análise

| Severidade     | Quantidade | Status                 |
| -------------- | ---------- | ---------------------- |
| 🔴 **Crítica** | 3          | Requer ação imediata   |
| 🟠 **Alta**    | 5          | Requer ação em 7 dias  |
| 🟡 **Média**   | 8          | Requer ação em 30 dias |
| 🟢 **Baixa**   | 6          | Melhorias recomendadas |

### Pontuação Geral de Segurança

```
╔════════════════════════════════════════════╗
║  PONTUAÇÃO: 65/100 - PRECISA MELHORAR      ║
╚════════════════════════════════════════════╝
```

### Áreas de Maior Preocupação

1. **Webhooks sem autenticação adequada** (ML e Evolution)
2. **Exposição de chaves de API em logs**
3. **Falta de Rate Limiting**
4. **CSRF incompleto em algumas rotas**

---

## 🔬 Metodologia de Análise

A análise foi conduzida utilizando:

- **Revisão de Código Estático** - Análise manual do código-fonte
- **OWASP Top 10 2021** - Verificação contra as 10 principais vulnerabilidades
- **SANS Top 25** - Verificação de erros de programação mais perigosos
- **Melhores Práticas PHP** - Conformidade com padrões de segurança PHP

### Arquivos Analisados

| Arquivo                          | Linhas | Criticidade |
| -------------------------------- | ------ | ----------- |
| `config.php`                     | 233    | Alta        |
| `login.php`                      | 219    | Alta        |
| `register.php`                   | 266    | Alta        |
| `dashboard.php`                  | 423    | Média       |
| `ml_webhook_receiver.php`        | 253    | Crítica     |
| `evolution_webhook_receiver.php` | 354    | Crítica     |
| `asaas_webhook_receiver.php`     | 250    | Alta        |
| `super_admin.php`                | 395    | Alta        |
| `super_admin_actions.php`        | 186    | Alta        |
| `oauth_callback.php`             | 168    | Alta        |
| `includes/*.php`                 | ~1500  | Variável    |

---

## 🏷 Classificação de Severidade

| Nível          | Descrição                                                      | Impacto               | Prazo Correção |
| -------------- | -------------------------------------------------------------- | --------------------- | -------------- |
| 🔴 **CRÍTICA** | Exploração imediata possível, acesso não autorizado ao sistema | Comprometimento total | **Imediato**   |
| 🟠 **ALTA**    | Pode levar a acesso não autorizado ou vazamento de dados       | Significativo         | **7 dias**     |
| 🟡 **MÉDIA**   | Pode ser explorada em condições específicas                    | Moderado              | **30 dias**    |
| 🟢 **BAIXA**   | Difícil exploração, impacto limitado                           | Baixo                 | **90 dias**    |

---

## 🔴 Vulnerabilidades Críticas

### CRIT-001: Webhook ML sem Autenticação

**Arquivo:** `ml_webhook_receiver.php`  
**Linha:** 1-253  
**CVSS Score:** 9.1 (Crítico)  
**CWE:** CWE-306 (Missing Authentication for Critical Function)

#### Descrição

O endpoint de webhook do Mercado Livre não possui **nenhum mecanismo de autenticação** para verificar se a requisição realmente vem do Mercado Livre. Qualquer atacante pode enviar requisições POST falsas para este endpoint.

#### Código Vulnerável

```php
// ml_webhook_receiver.php - Linha 22-28
// --- Validação Inicial ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("[ML Webhook Receiver] ERRO: Método HTTP inválido.");
    http_response_code(405); exit;
}
// NENHUMA VALIDAÇÃO DE ORIGEM DA REQUISIÇÃO
```

#### Impacto

- ⚠️ Atacante pode simular perguntas falsas
- ⚠️ Pode causar spam de notificações WhatsApp para usuários
- ⚠️ Pode manipular o log de perguntas
- ⚠️ Negação de serviço (DoS) por excesso de requisições

#### Prova de Conceito (PoC)

```bash
curl -X POST https://seusite.com/ml_webhook_receiver.php \
  -H "Content-Type: application/json" \
  -d '{"topic":"questions","resource":"/questions/999999","user_id":"12345"}'
```

#### Correção Recomendada

```php
// Opção 1: Validar IP do Mercado Livre (menos seguro, IPs podem mudar)
$ml_allowed_ips = ['18.231.x.x', '54.232.x.x']; // Verificar IPs atuais
if (!in_array($_SERVER['REMOTE_ADDR'], $ml_allowed_ips)) {
    http_response_code(403);
    exit;
}

// Opção 2: Implementar assinatura HMAC (se ML suportar)
// Verificar documentação atual do ML para webhooks autenticados

// Opção 3: Token secreto na URL (menos seguro mas funcional)
$expected_token = WEBHOOK_SECRET_TOKEN;
$received_token = $_GET['token'] ?? '';
if (!hash_equals($expected_token, $received_token)) {
    http_response_code(403);
    exit;
}
```

---

### CRIT-002: Webhook Evolution sem Autenticação

**Arquivo:** `evolution_webhook_receiver.php`  
**Linha:** 1-354  
**CVSS Score:** 9.1 (Crítico)  
**CWE:** CWE-306 (Missing Authentication for Critical Function)

#### Descrição

O endpoint de webhook da Evolution API também não possui autenticação adequada. O próprio código contém um alerta sobre isso:

```php
// Linha 15-18
/**
 * !! ALERTA DE SEGURANÇA: Validar a origem do webhook (ex: por IP ou token,
 *    se a Evolution API permitir) é altamente recomendado em produção para
 *    evitar processamento de requisições maliciosas. !!
 */
```

#### Impacto

- ⚠️ Atacante pode simular respostas de vendedores
- ⚠️ Pode aprovar/rejeitar perguntas sem autorização
- ⚠️ Pode publicar respostas falsas no Mercado Livre
- ⚠️ Comprometimento da integridade dos dados

#### Correção Recomendada

```php
// Adicionar no início do arquivo, após os includes:

// Validar token da Evolution API (se configurado no Evolution)
$evolutionWebhookToken = EVOLUTION_WEBHOOK_TOKEN ?? null;
if ($evolutionWebhookToken) {
    $receivedToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ??
                     $_SERVER['HTTP_AUTHORIZATION'] ??
                     $_GET['token'] ?? '';

    if (!hash_equals($evolutionWebhookToken, $receivedToken)) {
        logMessage("[Evolution Webhook] ERRO: Token de autenticação inválido");
        http_response_code(403);
        exit;
    }
}

// Validar IP da Evolution API (se fixo)
$evolutionAllowedIps = explode(',', EVOLUTION_ALLOWED_IPS ?? '');
if (!empty($evolutionAllowedIps[0]) &&
    !in_array($_SERVER['REMOTE_ADDR'], $evolutionAllowedIps)) {
    logMessage("[Evolution Webhook] ERRO: IP não autorizado: " . $_SERVER['REMOTE_ADDR']);
    http_response_code(403);
    exit;
}
```

---

### CRIT-003: Exposição de API Keys em Logs

**Arquivos:** Múltiplos (`gemini_api.php`, `asaas_api.php`, `ml_api.php`)  
**CVSS Score:** 8.5 (Alto/Crítico)  
**CWE:** CWE-532 (Insertion of Sensitive Information into Log File)

#### Descrição

Várias partes do código logam informações sensíveis como tokens de acesso, chaves de API e respostas completas de APIs que podem conter dados confidenciais.

#### Código Vulnerável

```php
// gemini_api.php - Linha 85
$url = $endpoint . '?key=' . urlencode(GOOGLE_API_KEY);
// Se URL for logada, a chave será exposta

// oauth_callback.php - Linha 83-89
$logTokenPreview = json_encode([
    'user_id' => $tokenData['user_id'] ?? 'N/A',
    'access_token_start' => isset($tokenData['access_token']) ? substr($tokenData['access_token'], 0, 8).'...' : 'N/A',
    // Ainda expõe início do token
]);

// asaas_api.php - Headers com API Key
$headers = [
    'access_token: ' . ASAAS_API_KEY  // Se logado, expõe chave
];
```

#### Impacto

- ⚠️ Chaves de API expostas em arquivos de log
- ⚠️ Logs podem ser acessados por atacantes
- ⚠️ Comprometimento de contas em serviços externos

#### Correção Recomendada

```php
// Criar função de sanitização de logs
function sanitizeForLog($data) {
    $sensitiveKeys = ['access_token', 'refresh_token', 'api_key', 'password', 'secret'];

    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = sanitizeForLog($value);
            }
        }
    }
    return $data;
}

// Uso:
logMessage("API Response: " . json_encode(sanitizeForLog($response)));
```

---

## 🟠 Vulnerabilidades Altas

### HIGH-001: Falta de Rate Limiting

**Arquivos:** `login.php`, `register.php`, todos os webhooks  
**CVSS Score:** 7.5  
**CWE:** CWE-307 (Improper Restriction of Excessive Authentication Attempts)

#### Descrição

Não há limitação de tentativas de login, registro ou requisições aos webhooks. Isso permite ataques de força bruta e DoS.

#### Impacto

- ⚠️ Ataques de força bruta em senhas
- ⚠️ Criação massiva de contas fake
- ⚠️ Negação de serviço por sobrecarga

#### Correção Recomendada

```php
// Implementar rate limiting com Redis ou arquivo
function checkRateLimit($identifier, $action, $maxAttempts = 5, $windowSeconds = 300) {
    $cacheFile = sys_get_temp_dir() . '/rate_limit_' . md5($identifier . $action);
    $data = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];

    $now = time();
    $data = array_filter($data, fn($t) => $t > $now - $windowSeconds);

    if (count($data) >= $maxAttempts) {
        return false; // Rate limit exceeded
    }

    $data[] = $now;
    file_put_contents($cacheFile, json_encode($data));
    return true;
}

// No login.php:
$clientIp = $_SERVER['REMOTE_ADDR'];
if (!checkRateLimit($clientIp, 'login', 5, 300)) {
    $errors[] = "Muitas tentativas de login. Aguarde 5 minutos.";
    // Não processar login
}
```

---

### HIGH-002: Super Admin via Flag no Banco

**Arquivo:** `super_admin.php`, `super_admin_actions.php`  
**CVSS Score:** 7.2  
**CWE:** CWE-269 (Improper Privilege Management)

#### Descrição

O controle de acesso de Super Admin é baseado apenas em uma flag `is_super_admin` no banco de dados. Se um atacante conseguir SQL Injection em qualquer parte do sistema, pode se promover a admin.

#### Código Atual

```php
// super_admin.php - Linha 24-34
$stmtAdmin = $pdo->prepare("SELECT is_super_admin, email FROM saas_users WHERE id = :id LIMIT 1");
$stmtAdmin->execute([':id' => $loggedInSaasUserId]);
$adminData = $stmtAdmin->fetch();

if (!$adminData || !$adminData['is_super_admin']) {
    header('Location: dashboard.php');
    exit;
}
```

#### Correção Recomendada

```php
// 1. Usar tabela separada para admins
// CREATE TABLE admin_users (
//     saas_user_id INT PRIMARY KEY,
//     role ENUM('admin', 'super_admin'),
//     created_by INT,
//     created_at TIMESTAMP
// );

// 2. Adicionar verificação de IP para acesso admin
$allowedAdminIps = ['192.168.1.x', '10.0.0.x']; // IPs internos
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedAdminIps)) {
    // Exigir 2FA ou código adicional
}

// 3. Implementar 2FA para admins
// Usar biblioteca como PHPGangsta/GoogleAuthenticator
```

---

### HIGH-003: CSRF Incompleto em Ações Admin

**Arquivo:** `super_admin_actions.php`  
**CVSS Score:** 7.1  
**CWE:** CWE-352 (Cross-Site Request Forgery)

#### Descrição

As ações administrativas (ativar, desativar, excluir usuários) são executadas via GET sem token CSRF.

#### Código Vulnerável

```php
// super_admin_actions.php - Linha 52-53
$action = $_GET['action'] ?? null;
$targetUserId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
```

#### Impacto

- ⚠️ Atacante pode induzir admin a clicar em link malicioso
- ⚠️ Ações administrativas executadas sem consentimento

#### Correção Recomendada

```php
// 1. Mudar para POST
// 2. Adicionar token CSRF

// Em super_admin.php (ao renderizar links):
$csrfToken = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

// Link com form:
<form method="POST" action="super_admin_actions.php">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="deactivate">
    <input type="hidden" name="user_id" value="<?= $userId ?>">
    <button type="submit">Desativar</button>
</form>

// Em super_admin_actions.php:
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$receivedToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if (!hash_equals($expectedToken, $receivedToken)) {
    logMessage("CSRF token inválido em super_admin_actions");
    header('Location: super_admin.php?action_status=error&action_msg=Token+inválido');
    exit;
}
```

---

### HIGH-004: Validação de CPF/CNPJ Incompleta

**Arquivo:** `register.php`  
**Linha:** 56-59  
**CVSS Score:** 6.5  
**CWE:** CWE-20 (Improper Input Validation)

#### Descrição

A validação de CPF/CNPJ verifica apenas o tamanho (11 ou 14 dígitos), não valida os dígitos verificadores. O próprio código tem um TODO alertando para isso.

#### Código Vulnerável

```php
// register.php - Linha 56-59
} elseif (strlen($cpf_cnpj_cleaned) != 11 && strlen($cpf_cnpj_cleaned) != 14) {
    $errors[] = "📄 CPF/CNPJ inválido (deve conter 11 ou 14 dígitos).";
    // TODO: Implementar validação de dígito verificador para CPF/CNPJ aqui
}
```

#### Impacto

- ⚠️ Usuários podem cadastrar CPF/CNPJ inválidos
- ⚠️ Problemas com integração Asaas (que pode validar)
- ⚠️ Dados inconsistentes no sistema

#### Correção Recomendada

```php
function validarCPF($cpf) {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) != 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;

    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

function validarCNPJ($cnpj) {
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if (strlen($cnpj) != 14) return false;

    $soma = 0;
    $multiplicadores1 = [5,4,3,2,9,8,7,6,5,4,3,2];
    $multiplicadores2 = [6,5,4,3,2,9,8,7,6,5,4,3,2];

    for ($i = 0; $i < 12; $i++) $soma += $cnpj[$i] * $multiplicadores1[$i];
    $resto = $soma % 11;
    $digito1 = ($resto < 2) ? 0 : 11 - $resto;

    if ($cnpj[12] != $digito1) return false;

    $soma = 0;
    for ($i = 0; $i < 13; $i++) $soma += $cnpj[$i] * $multiplicadores2[$i];
    $resto = $soma % 11;
    $digito2 = ($resto < 2) ? 0 : 11 - $resto;

    return $cnpj[13] == $digito2;
}

// Uso:
if (strlen($cpf_cnpj_cleaned) == 11 && !validarCPF($cpf_cnpj_cleaned)) {
    $errors[] = "📄 CPF inválido.";
} elseif (strlen($cpf_cnpj_cleaned) == 14 && !validarCNPJ($cpf_cnpj_cleaned)) {
    $errors[] = "📄 CNPJ inválido.";
}
```

---

### HIGH-005: Informações de Debug em Produção

**Arquivo:** `config.php`  
**Linha:** 12-13  
**CVSS Score:** 5.3  
**CWE:** CWE-209 (Information Exposure Through an Error Message)

#### Descrição

Código comentado sugere que logs de debug podem ser habilitados em produção, e mensagens de erro podem expor informações sensíveis.

#### Código Potencialmente Problemático

```php
// config.php - Linha 12-13
// (opcional) log de conferência por alguns minutos
// error_log('TZ=' . date_default_timezone_get() . ' now=' . date('c'));
```

#### Correção Recomendada

```php
// Remover completamente códigos de debug
// Adicionar verificação de ambiente
define('APP_ENV', getenv('APP_ENV') ?: 'production');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0); // Não reportar nenhum erro visível
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
```

---

## 🟡 Vulnerabilidades Médias

### MED-001: Senha Mínima Muito Curta

**Arquivo:** `register.php`  
**Linha:** 61-63  
**CWE:** CWE-521 (Weak Password Requirements)

#### Descrição

A senha mínima exigida é de apenas 8 caracteres, sem requisitos de complexidade.

```php
} elseif (strlen($password) < 8) {
    $errors[] = "📏 Senha deve ter no mínimo 8 caracteres.";
}
```

#### Correção Recomendada

```php
function validarSenhaForte($senha) {
    if (strlen($senha) < 12) return "Mínimo 12 caracteres.";
    if (!preg_match('/[A-Z]/', $senha)) return "Inclua uma letra maiúscula.";
    if (!preg_match('/[a-z]/', $senha)) return "Inclua uma letra minúscula.";
    if (!preg_match('/[0-9]/', $senha)) return "Inclua um número.";
    if (!preg_match('/[^A-Za-z0-9]/', $senha)) return "Inclua um caractere especial.";
    return null;
}
```

---

### MED-002: Session Fixation Parcial

**Arquivo:** `login.php`  
**Linha:** 104  
**CWE:** CWE-384 (Session Fixation)

#### Descrição

O `session_regenerate_id(true)` é chamado no login, mas não em todas as mudanças de privilégio.

#### Correção Recomendada

```php
// Chamar session_regenerate_id(true) também em:
// - register.php (após criar sessão)
// - Qualquer mudança de privilégio
// - Após confirmação de pagamento
```

---

### MED-003: Falta de Content Security Policy (CSP)

**Arquivos:** Todos os arquivos HTML/PHP  
**CWE:** CWE-1021 (Improper Restriction of Rendered UI Layers)

#### Descrição

Não há headers de Content Security Policy, permitindo ataques XSS mais facilmente.

#### Correção Recomendada

```php
// No início do config.php ou em cada página:
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; img-src 'self' data:; font-src 'self';");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
```

---

### MED-004: Tokens ML sem Rotação Forçada

**Arquivo:** `ml_webhook_receiver.php`, `includes/ml_api.php`  
**CWE:** CWE-613 (Insufficient Session Expiration)

#### Descrição

Os tokens do Mercado Livre são renovados automaticamente, mas não há mecanismo para forçar rotação em caso de comprometimento.

#### Correção Recomendada

```php
// Adicionar coluna last_token_rotation na tabela mercadolibre_users
// Forçar reconexão se token não foi rotacionado há mais de 30 dias
if ($lastRotation && strtotime($lastRotation) < strtotime('-30 days')) {
    // Invalidar conexão e exigir reconexão
}
```

---

### MED-005: Logs Sem Rotação Automática

**Arquivo:** `config.php`, `includes/log_helper.php`  
**CWE:** CWE-779 (Logging of Excessive Data)

#### Descrição

O arquivo de log pode crescer indefinidamente (`poll.log` chegou a 444MB).

#### Correção Recomendada

```php
// Implementar rotação de logs
function logMessage($message, $maxSize = 10485760) { // 10MB
    $logFile = LOG_FILE_PATH;

    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        $backupFile = $logFile . '.' . date('Y-m-d-His') . '.bak';
        rename($logFile, $backupFile);
        // Opcional: compactar ou deletar backups antigos
    }

    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, $logFile);
}
```

---

### MED-006: Falta de Prepared Statement em Query Dinâmica

**Arquivo:** `ml_webhook_receiver.php`  
**Linha:** 131  
**CWE:** CWE-89 (SQL Injection)

#### Descrição

Uso de interpolação de variável em query SQL (mesmo sendo um ID inteiro).

```php
@$pdo->exec("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id=".$connectionIdInDb);
```

#### Correção Recomendada

```php
// Usar prepared statement mesmo para IDs
$stmt = $pdo->prepare("UPDATE mercadolibre_users SET is_active=FALSE, updated_at = NOW() WHERE id = :id");
$stmt->execute([':id' => $connectionIdInDb]);
```

---

### MED-007: Ausência de Verificação SSL em cURL

**Arquivo:** `includes/curl_helper.php`  
**CWE:** CWE-295 (Improper Certificate Validation)

#### Descrição

O código cURL não configura explicitamente a verificação SSL.

#### Correção Recomendada

```php
$opts = [
    // Adicionar:
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CAINFO => '/path/to/cacert.pem', // Se necessário
];
```

---

### MED-008: Exposição de Estrutura de Diretórios

**Arquivo:** `config.php`  
**Linha:** 65  
**CWE:** CWE-200 (Exposure of Sensitive Information)

#### Descrição

Mensagens de erro expõem caminhos completos do servidor.

```php
$errorMessage = "ERRO CRÍTICO (config.php): Arquivo de segredos NÃO ENCONTRADO em '$secretsFilePath'.";
```

#### Correção Recomendada

```php
// Não expor caminhos em mensagens de erro
error_log("Arquivo de segredos não encontrado: $secretsFilePath");
die("Erro crítico de configuração (SEC01). Contate o suporte.");
```

---

## 🟢 Vulnerabilidades Baixas

### LOW-001: Cookies sem Flags de Segurança

**Impacto:** Baixo  
**Correção:**

```php
// Em config.php, antes de session_start():
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Apenas HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
```

---

### LOW-002: Falta de Limitação de Upload (se aplicável)

Atualmente não há funcionalidade de upload, mas se for implementada futuramente:

```php
// Limitar tamanho e tipos de arquivo
$maxSize = 2 * 1024 * 1024; // 2MB
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
```

---

### LOW-003: Versão do PHP não Verificada

```php
// Adicionar no início do config.php:
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die('PHP 8.0+ é obrigatório.');
}
```

---

### LOW-004: Falta de Sanitização em Exibição de Erros

Alguns erros são exibidos diretamente sem `htmlspecialchars()`:

```php
// Sempre usar:
echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
```

---

### LOW-005: Timeout de Sessão Não Configurado

```php
// Em config.php:
ini_set('session.gc_maxlifetime', 1800); // 30 minutos
ini_set('session.cookie_lifetime', 0); // Até fechar navegador
```

---

### LOW-006: Ausência de Nonce em Scripts Inline

Para CSP mais restritivo:

```php
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: script-src 'nonce-$nonce'");
// Em scripts inline:
<script nonce="<?= $nonce ?>">...</script>
```

---

## ✅ Boas Práticas Implementadas

O projeto já implementa algumas boas práticas de segurança:

| Prática                           | Arquivo                                 | Status       |
| --------------------------------- | --------------------------------------- | ------------ |
| ✅ Password Hash (bcrypt)         | `register.php`, `login.php`             | Implementado |
| ✅ Prepared Statements (maioria)  | Diversos                                | Implementado |
| ✅ Criptografia de Tokens         | `db.php` (Defuse)                       | Implementado |
| ✅ Session Regeneration no Login  | `login.php`                             | Implementado |
| ✅ CSRF no OAuth                  | `oauth_start.php`, `oauth_callback.php` | Implementado |
| ✅ Validação HMAC (Asaas)         | `asaas_webhook_receiver.php`            | Implementado |
| ✅ Secrets em Arquivo Externo     | `config.php`                            | Implementado |
| ✅ Erros não Exibidos em Produção | `config.php`                            | Implementado |
| ✅ htmlspecialchars() em Outputs  | Diversos                                | Parcial      |

---

## 📋 Recomendações Gerais

### Prioridade Imediata (Esta Semana)

1. **Implementar autenticação nos webhooks ML e Evolution**
2. **Remover logs de informações sensíveis**
3. **Adicionar Rate Limiting em login e registro**

### Prioridade Alta (Próximas 2 Semanas)

4. **Adicionar CSRF em todas as ações administrativas**
5. **Implementar validação completa de CPF/CNPJ**
6. **Adicionar headers de segurança (CSP, X-Frame-Options, etc.)**

### Prioridade Média (Próximo Mês)

7. **Implementar 2FA para administradores**
8. **Configurar rotação automática de logs**
9. **Revisar todas as queries SQL**
10. **Adicionar monitoramento de segurança**

### Prioridade Baixa (Próximos 3 Meses)

11. **Implementar WAF (Web Application Firewall)**
12. **Configurar HSTS (HTTP Strict Transport Security)**
13. **Realizar teste de penetração profissional**
14. **Implementar auditoria de ações administrativas**

---

## ✓ Checklist de Correções

### Webhooks

- [ ] Implementar autenticação no `ml_webhook_receiver.php`
- [ ] Implementar autenticação no `evolution_webhook_receiver.php`
- [ ] Adicionar rate limiting nos webhooks

### Autenticação

- [ ] Aumentar requisitos de senha (12+ chars, complexidade)
- [ ] Implementar rate limiting em `login.php`
- [ ] Implementar rate limiting em `register.php`
- [ ] Adicionar 2FA para admins

### Validação

- [ ] Implementar validação de dígitos CPF/CNPJ
- [ ] Revisar todas as queries SQL para injection
- [ ] Adicionar CSRF em `super_admin_actions.php`

### Headers & Configuração

- [ ] Adicionar Content-Security-Policy
- [ ] Adicionar X-Frame-Options
- [ ] Configurar cookies seguros
- [ ] Configurar timeout de sessão

### Logs

- [ ] Remover dados sensíveis dos logs
- [ ] Implementar rotação de logs
- [ ] Proteger arquivos de log com .htaccess

---

## 📚 Referências OWASP

Este documento foi baseado nas seguintes referências:

1. **OWASP Top 10 2021**

   - https://owasp.org/Top10/

2. **OWASP Cheat Sheet Series**

   - https://cheatsheetseries.owasp.org/

3. **CWE (Common Weakness Enumeration)**

   - https://cwe.mitre.org/

4. **PHP Security Best Practices**

   - https://www.php.net/manual/en/security.php

5. **OWASP PHP Security Cheat Sheet**
   - https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html

---

## 📝 Histórico de Revisões

| Versão | Data       | Autor               | Alterações               |
| ------ | ---------- | ------------------- | ------------------------ |
| 1.0    | 19/01/2026 | Equipe de Segurança | Análise inicial completa |

---

> **⚠️ AVISO LEGAL:** Este documento contém informações sensíveis sobre vulnerabilidades de segurança. Deve ser tratado como confidencial e compartilhado apenas com pessoas autorizadas responsáveis pela correção dos problemas identificados.

---

**Próxima Revisão Programada:** Após implementação das correções críticas
