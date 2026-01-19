# 📚 Documentação Completa - Meli AI

> **Versão:** 1.0  
> **Última Atualização:** 19 de Janeiro de 2026  
> **Autor:** Documentação Técnica

---

## 📋 Índice

1. [Visão Geral do Projeto](#-visão-geral-do-projeto)
2. [Arquitetura do Sistema](#-arquitetura-do-sistema)
3. [Tecnologias Utilizadas](#-tecnologias-utilizadas)
4. [Estrutura de Arquivos](#-estrutura-de-arquivos)
5. [Configuração e Instalação](#-configuração-e-instalação)
6. [Banco de Dados](#-banco-de-dados)
7. [Fluxos Principais](#-fluxos-principais)
8. [Integrações de API](#-integrações-de-api)
9. [Sistema de Webhooks](#-sistema-de-webhooks)
10. [Segurança](#-segurança)
11. [Arquivos Detalhados](#-arquivos-detalhados)

---

## 🎯 Visão Geral do Projeto

O **Meli AI** é uma aplicação SaaS (Software as a Service) desenvolvida em PHP que automatiza respostas a perguntas de clientes no Mercado Livre utilizando Inteligência Artificial (Google Gemini).

### Principais Funcionalidades

- ✅ **Automação de Respostas**: Responde automaticamente perguntas do Mercado Livre usando IA
- ✅ **Notificações WhatsApp**: Alerta vendedores sobre novas perguntas via Evolution API
- ✅ **Aprovação Manual**: Vendedores podem aprovar/editar/rejeitar respostas pelo WhatsApp
- ✅ **Sistema de Assinaturas**: Cobrança recorrente via Asaas (gateway brasileiro)
- ✅ **Multi-tenant**: Suporta múltiplos vendedores com contas separadas
- ✅ **Arquitetura de 2 Agentes IA**: Sistema inteligente com Analista + Pesquisador

---

## 🏗 Arquitetura do Sistema

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           MELI AI - ARQUITETURA                         │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐            │
│  │   Mercado    │────▶│   Webhook    │────▶│   Core       │            │
│  │   Livre      │     │   Receiver   │     │   Logic      │            │
│  └──────────────┘     └──────────────┘     └──────┬───────┘            │
│                                                   │                     │
│                                            ┌──────▼───────┐            │
│                                            │   Agent 1    │            │
│                                            │  (Analista)  │            │
│                                            └──────┬───────┘            │
│                                                   │                     │
│                                            ┌──────▼───────┐            │
│                                            │   Agent 2    │            │
│                                            │(Pesquisador) │            │
│                                            └──────┬───────┘            │
│                                                   │                     │
│  ┌──────────────┐     ┌──────────────┐     ┌──────▼───────┐            │
│  │   WhatsApp   │◀────│   Evolution  │◀────│   Gemini     │            │
│  │   Vendedor   │     │   API        │     │   API        │            │
│  └──────────────┘     └──────────────┘     └──────────────┘            │
│                                                                         │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐            │
│  │    Asaas     │────▶│   Webhook    │────▶│   Database   │            │
│  │  (Pagamentos)│     │   Receiver   │     │   (MySQL)    │            │
│  └──────────────┘     └──────────────┘     └──────────────┘            │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### Fluxo Resumido

1. **Pergunta no ML** → Webhook notifica o sistema
2. **Core Logic** → Processa e aciona os agentes IA
3. **Agent 1 (Analista)** → Analisa contexto do produto e pergunta
4. **Agent 2 (Pesquisador)** → Gera resposta com Google Search (grounding)
5. **WhatsApp** → Envia notificação para vendedor aprovar
6. **Vendedor Responde** → Sistema publica resposta no ML

---

## 🛠 Tecnologias Utilizadas

| Tecnologia                | Versão                | Uso                           |
| ------------------------- | --------------------- | ----------------------------- |
| **PHP**                   | 8.0+                  | Backend principal             |
| **MySQL**                 | 5.7+                  | Banco de dados                |
| **PDO**                   | -                     | Conexão segura ao DB          |
| **Tailwind CSS**          | 3.x (CDN)             | Estilização frontend          |
| **Google Gemini**         | gemini-2.5-flash-lite | Modelo de IA                  |
| **Mercado Livre API**     | OAuth2                | Integração marketplace        |
| **Evolution API**         | V2                    | WhatsApp messaging            |
| **Asaas API**             | v3                    | Gateway de pagamentos         |
| **Defuse PHP Encryption** | 2.x                   | Criptografia de tokens        |
| **Composer**              | 2.x                   | Gerenciamento de dependências |

---

## 📁 Estrutura de Arquivos

```
d3ecom/
│
├── 📄 Arquivos Raiz (Páginas e Endpoints)
│   ├── index.php                    # Landing page
│   ├── login.php                    # Autenticação de usuários
│   ├── register.php                 # Cadastro de novos usuários
│   ├── logout.php                   # Encerramento de sessão
│   ├── dashboard.php                # Painel principal do vendedor
│   ├── billing.php                  # Gestão de assinatura/pagamentos
│   ├── update_profile.php           # Atualização de perfil
│   ├── oauth_start.php              # Início OAuth2 Mercado Livre
│   ├── oauth_callback.php           # Callback OAuth2 Mercado Livre
│   ├── go_to_asaas_payment.php      # Redirecionamento para pagamento
│   ├── super_admin.php              # Painel administrativo
│   ├── super_admin_actions.php      # Ações do admin
│   ├── poll_questions.php           # CRON job para polling
│   └── test.php                     # Testes de desenvolvimento
│
├── 📄 Webhooks (Endpoints de Notificação)
│   ├── ml_webhook_receiver.php      # Recebe notificações do ML
│   ├── asaas_webhook_receiver.php   # Recebe eventos de pagamento
│   └── evolution_webhook_receiver.php # Recebe respostas WhatsApp
│
├── 📄 Configuração
│   ├── config.php                   # Configurações centrais
│   ├── db.php                       # Conexão com banco de dados
│   ├── composer.json                # Dependências PHP
│   └── style.css                    # Estilos customizados
│
├── 📁 includes/                     # Módulos de lógica
│   ├── core_logic.php               # Orquestrador principal da IA
│   ├── agent1.php                   # Sistema de 2 agentes IA
│   ├── ml_api.php                   # Funções da API Mercado Livre
│   ├── gemini_api.php               # Cliente da API Gemini
│   ├── evolution_api.php            # Funções WhatsApp
│   ├── asaas_api.php                # Funções de pagamento
│   ├── db_interaction.php           # CRUD do log de perguntas
│   ├── curl_helper.php              # Helper para requisições HTTP
│   ├── helpers.php                  # Funções auxiliares CSS
│   └── log_helper.php               # Sistema de logging
│
└── 📁 vendor/                       # Dependências (Composer)
    ├── autoload.php                 # Autoloader PSR-4
    ├── defuse/php-encryption/       # Biblioteca de criptografia
    ├── vlucas/phpdotenv/            # Variáveis de ambiente
    └── symfony/polyfill-*/          # Polyfills PHP
```

---

## ⚙️ Configuração e Instalação

### Pré-requisitos

- PHP 8.0 ou superior
- MySQL 5.7 ou superior
- Composer instalado
- Servidor web (Apache/Nginx)
- Conta no Mercado Livre (desenvolvedor)
- Conta no Asaas (pagamentos)
- Evolution API configurada (WhatsApp)
- Chave API do Google Gemini

### Instalação

1. **Clone o repositório**

```bash
git clone [url-do-repositorio] d3ecom
cd d3ecom
```

2. **Instale as dependências**

```bash
composer install
```

3. **Configure o arquivo de segredos**

Crie o arquivo em `../../meliai_secure/secrets.php` (2 níveis acima):

```php
<?php
// Banco de Dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'nome_do_banco');
define('DB_USER', 'usuario');
define('DB_PASS', 'senha');

// Mercado Livre
define('ML_CLIENT_ID', 'seu_client_id');
define('ML_CLIENT_SECRET', 'seu_client_secret');
define('ML_REDIRECT_URI', 'https://seudominio.com/oauth_callback.php');

// Google Gemini
define('GEMINI_API_KEY', 'sua_chave_api');

// Evolution API (WhatsApp)
define('EVOLUTION_API_URL', 'https://sua-evolution-api.com');
define('EVOLUTION_API_KEY', 'sua_chave');
define('EVOLUTION_INSTANCE_NAME', 'nome_instancia');

// Asaas (Pagamentos)
define('ASAAS_API_KEY', 'sua_chave_api');
define('ASAAS_API_URL', 'https://api.asaas.com/v3');
define('ASAAS_WEBHOOK_SECRET', 'segredo_webhook');

// Criptografia
define('DEFUSE_ENCRYPTION_KEY', 'chave_gerada_pelo_defuse');

// Super Admin
define('SUPER_ADMIN_SECRET', 'senha_super_admin');
```

4. **Configure o banco de dados**

Execute os scripts SQL para criar as tabelas (veja seção Banco de Dados).

5. **Configure os webhooks nas plataformas**

- **Mercado Livre**: Configure o webhook para `https://seudominio.com/ml_webhook_receiver.php`
- **Asaas**: Configure o webhook para `https://seudominio.com/asaas_webhook_receiver.php`
- **Evolution API**: Configure o webhook para `https://seudominio.com/evolution_webhook_receiver.php`

6. **Configure o CRON job**

```bash
*/5 * * * * php /caminho/para/d3ecom/poll_questions.php >> /var/log/meliai_cron.log 2>&1
```

---

## 🗄 Banco de Dados

### Tabelas Principais

#### `saas_users` - Usuários do SaaS

```sql
CREATE TABLE saas_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    phone VARCHAR(20),
    asaas_customer_id VARCHAR(100),
    asaas_subscription_id VARCHAR(100),
    subscription_status ENUM('PENDING', 'ACTIVE', 'OVERDUE', 'CANCELED') DEFAULT 'PENDING',
    subscription_expires_at DATE,
    is_super_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### `mercadolibre_users` - Contas ML Vinculadas

```sql
CREATE TABLE mercadolibre_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    saas_user_id INT NOT NULL,
    ml_user_id BIGINT UNIQUE NOT NULL,
    ml_nickname VARCHAR(255),
    access_token_encrypted TEXT,
    refresh_token_encrypted TEXT,
    token_expires_at DATETIME,
    whatsapp_number VARCHAR(20),
    ai_enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (saas_user_id) REFERENCES saas_users(id) ON DELETE CASCADE
);
```

#### `question_processing_log` - Log de Perguntas

```sql
CREATE TABLE question_processing_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNIQUE NOT NULL,
    ml_user_id BIGINT NOT NULL,
    item_id VARCHAR(50),
    question_text TEXT,
    ai_suggested_answer TEXT,
    final_answer TEXT,
    status ENUM('PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'ANSWERED', 'TIMEOUT', 'ERROR') DEFAULT 'PENDING_APPROVAL',
    whatsapp_message_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    answered_at DATETIME,
    INDEX idx_ml_user (ml_user_id),
    INDEX idx_status (status),
    INDEX idx_question (question_id)
);
```

### Diagrama de Relacionamentos

```
┌──────────────────┐       ┌──────────────────────┐
│    saas_users    │       │  mercadolibre_users  │
├──────────────────┤       ├──────────────────────┤
│ id (PK)          │◄──────│ saas_user_id (FK)    │
│ email            │       │ id (PK)              │
│ password_hash    │       │ ml_user_id           │
│ name             │       │ access_token_enc     │
│ phone            │       │ refresh_token_enc    │
│ asaas_customer_id│       │ whatsapp_number      │
│ subscription_*   │       │ ai_enabled           │
└──────────────────┘       └──────────┬───────────┘
                                      │
                                      │ ml_user_id
                                      ▼
                           ┌──────────────────────┐
                           │ question_processing  │
                           │       _log           │
                           ├──────────────────────┤
                           │ id (PK)              │
                           │ question_id          │
                           │ ml_user_id           │
                           │ question_text        │
                           │ ai_suggested_answer  │
                           │ final_answer         │
                           │ status               │
                           └──────────────────────┘
```

---

## 🔄 Fluxos Principais

### 1. Fluxo de Cadastro e Pagamento

```
┌─────────┐    ┌──────────┐    ┌─────────┐    ┌─────────┐
│ Usuário │───▶│ register │───▶│  Asaas  │───▶│ billing │
│  Novo   │    │   .php   │    │ Customer│    │   .php  │
└─────────┘    └──────────┘    └─────────┘    └────┬────┘
                                                   │
                                                   ▼
┌─────────┐    ┌──────────┐    ┌─────────┐    ┌─────────┐
│Dashboard│◀───│  Webhook │◀───│  Asaas  │◀───│Pagamento│
│  Ativo  │    │ Receiver │    │ Confirm │    │   Link  │
└─────────┘    └──────────┘    └─────────┘    └─────────┘
```

### 2. Fluxo de Conexão Mercado Livre

```
┌──────────┐    ┌───────────┐    ┌───────────┐    ┌──────────┐
│Dashboard │───▶│oauth_start│───▶│   ML      │───▶│oauth_    │
│ "Conectar"│   │   .php    │    │  Login    │    │callback  │
└──────────┘    └───────────┘    └───────────┘    └────┬─────┘
                                                       │
                                         ┌─────────────┘
                                         ▼
                              ┌─────────────────────┐
                              │ Tokens Criptografados│
                              │ Salvos no Banco     │
                              └─────────────────────┘
```

### 3. Fluxo de Resposta a Perguntas (Principal)

```
┌───────────┐    ┌───────────┐    ┌───────────┐
│  Comprador│───▶│  Mercado  │───▶│  Webhook  │
│  Pergunta │    │   Livre   │    │  Receiver │
└───────────┘    └───────────┘    └─────┬─────┘
                                        │
                                        ▼
                              ┌─────────────────┐
                              │   Core Logic    │
                              │ triggerAiFor... │
                              └────────┬────────┘
                                       │
                        ┌──────────────┼──────────────┐
                        ▼              ▼              ▼
                  ┌──────────┐  ┌──────────┐  ┌──────────┐
                  │ Busca    │  │ Agente 1 │  │ Busca    │
                  │ Produto  │  │ Analisa  │  │ Histórico│
                  │ ML API   │  │ Contexto │  │ Perguntas│
                  └────┬─────┘  └────┬─────┘  └────┬─────┘
                       │             │             │
                       └─────────────┼─────────────┘
                                     ▼
                              ┌──────────────┐
                              │   Agente 2   │
                              │  Pesquisador │
                              │ (c/ Grounding)│
                              └──────┬───────┘
                                     │
                                     ▼
                              ┌──────────────┐
                              │  Evolution   │
                              │  API (Zap)   │
                              └──────┬───────┘
                                     │
                                     ▼
                              ┌──────────────┐
                              │   Vendedor   │
                              │  (WhatsApp)  │
                              └──────┬───────┘
                                     │
                    ┌────────────────┼────────────────┐
                    ▼                ▼                ▼
              ┌──────────┐    ┌──────────┐    ┌──────────┐
              │ Aprovar  │    │  Editar  │    │ Rejeitar │
              │   "1"    │    │  "2:..."  │    │   "3"    │
              └────┬─────┘    └────┬─────┘    └────┬─────┘
                   │               │               │
                   └───────────────┼───────────────┘
                                   ▼
                            ┌──────────────┐
                            │ Evolution    │
                            │ Webhook Recv │
                            └──────┬───────┘
                                   │
                                   ▼
                            ┌──────────────┐
                            │  ML API      │
                            │ Publica Resp │
                            └──────────────┘
```

---

## 🔌 Integrações de API

### Mercado Livre API

| Endpoint                        | Método | Uso                     |
| ------------------------------- | ------ | ----------------------- |
| `/oauth/token`                  | POST   | Troca código por tokens |
| `/users/me`                     | GET    | Dados do usuário        |
| `/items/{id}`                   | GET    | Detalhes do produto     |
| `/questions/{id}`               | GET    | Detalhes da pergunta    |
| `/answers`                      | POST   | Publicar resposta       |
| `/my/received_questions/search` | GET    | Listar perguntas        |

### Google Gemini API

| Modelo                  | Uso                  |
| ----------------------- | -------------------- |
| `gemini-2.5-flash-lite` | Geração de respostas |

**Recursos Utilizados:**

- Grounding com Google Search
- System Instructions customizadas
- Controle de temperatura e tokens

### Evolution API V2

| Endpoint                       | Uso                      |
| ------------------------------ | ------------------------ |
| `/message/sendText/{instance}` | Enviar mensagem de texto |

### Asaas API v3

| Endpoint         | Uso                   |
| ---------------- | --------------------- |
| `/customers`     | Criar/buscar clientes |
| `/subscriptions` | Gerenciar assinaturas |
| `/payments`      | Consultar pagamentos  |

---

## 📡 Sistema de Webhooks

### ML Webhook Receiver (`ml_webhook_receiver.php`)

**Eventos Processados:**

- `questions` - Nova pergunta recebida

**Fluxo:**

1. Recebe notificação do ML
2. Busca detalhes da pergunta
3. Busca usuário local pelo `ml_user_id`
4. Verifica se IA está ativada
5. Chama `triggerAiForQuestion()`

### Asaas Webhook Receiver (`asaas_webhook_receiver.php`)

**Eventos Processados:**

- `PAYMENT_RECEIVED` / `PAYMENT_CONFIRMED` → Status `ACTIVE`
- `PAYMENT_OVERDUE` / `PAYMENT_FAILED` → Status `OVERDUE`
- `SUBSCRIPTION_UPDATED` → Atualiza conforme status

**Segurança:**

- Validação HMAC-SHA256 da assinatura
- Header `Asaas-Signature` obrigatório

### Evolution Webhook Receiver (`evolution_webhook_receiver.php`)

**Eventos Processados:**

- Mensagens de texto do vendedor

**Comandos Reconhecidos:**

- `1` ou `SIM` → Aprovar resposta sugerida
- `2:texto` → Editar e enviar texto customizado
- `3` ou `NAO` → Rejeitar resposta

---

## 🔐 Segurança

### Práticas Implementadas

| Aspecto           | Implementação                      |
| ----------------- | ---------------------------------- |
| **Senhas**        | `password_hash()` com bcrypt       |
| **Tokens ML**     | Criptografia Defuse (AES-256)      |
| **Sessões**       | `session_regenerate_id()` no login |
| **SQL Injection** | Prepared Statements (PDO)          |
| **XSS**           | `htmlspecialchars()` em outputs    |
| **CSRF**          | Token `state` no OAuth2            |
| **Webhooks**      | Validação HMAC-SHA256              |
| **Secrets**       | Arquivo externo fora do webroot    |

### Arquivo de Segredos

O arquivo `secrets.php` fica em:

```
/caminho/servidor/
├── meliai_secure/
│   └── secrets.php      ← Configurações sensíveis
└── public_html/
    └── d3ecom/          ← Código da aplicação
```

---

## 📄 Arquivos Detalhados

### Arquivos Raiz

---

#### `config.php`

**Propósito:** Configuração central da aplicação

**Responsabilidades:**

- Carrega o arquivo de segredos externo (`../../meliai_secure/secrets.php`)
- Define timezone (`America/Sao_Paulo`)
- Configura exibição de erros
- Inicia a sessão PHP
- Define constantes globais

**Constantes Definidas:**

```php
// Banco de Dados
DB_HOST, DB_NAME, DB_USER, DB_PASS

// Mercado Livre
ML_CLIENT_ID, ML_CLIENT_SECRET, ML_REDIRECT_URI

// Google Gemini
GEMINI_API_KEY

// Evolution API
EVOLUTION_API_URL, EVOLUTION_API_KEY, EVOLUTION_INSTANCE_NAME

// Asaas
ASAAS_API_KEY, ASAAS_API_URL, ASAAS_WEBHOOK_SECRET

// Segurança
DEFUSE_ENCRYPTION_KEY, SUPER_ADMIN_SECRET
```

---

#### `db.php`

**Propósito:** Gerenciamento de conexão com banco de dados

**Funções Principais:**

```php
getDbConnection(): PDO
```

- Retorna conexão PDO singleton
- Configura `ERRMODE_EXCEPTION`
- Configura `FETCH_ASSOC` como padrão

```php
encryptData(string $data): string
```

- Criptografa dados usando Defuse
- Usa a chave `DEFUSE_ENCRYPTION_KEY`

```php
decryptData(string $encryptedData): string
```

- Descriptografa dados usando Defuse
- Retorna string vazia em caso de erro

---

#### `index.php`

**Propósito:** Landing page / Página inicial

**Comportamento:**

- Se usuário logado → Redireciona para `dashboard.php`
- Se não logado → Exibe página de marketing

**Elementos:**

- Header com logo e botões Login/Cadastro
- Seção hero com CTA
- Seção de benefícios
- Footer

---

#### `login.php`

**Propósito:** Autenticação de usuários

**Fluxo:**

1. Exibe formulário de login
2. Valida email e senha
3. Verifica `password_verify()`
4. Busca status da assinatura
5. Regenera ID de sessão
6. Redireciona baseado no status:
   - `ACTIVE` → `dashboard.php`
   - Outros → `billing.php`

**Sessão Criada:**

```php
$_SESSION['saas_user_id']
$_SESSION['saas_user_email']
$_SESSION['subscription_status']
$_SESSION['asaas_customer_id']
```

---

#### `register.php`

**Propósito:** Cadastro de novos usuários

**Fluxo:**

1. Valida dados do formulário
2. Verifica se email já existe
3. Cria hash da senha
4. Cria cliente no Asaas
5. Insere usuário no banco
6. Cria sessão automaticamente
7. Redireciona para `billing.php`

**Campos:**

- Nome completo
- Email
- Telefone (formato brasileiro)
- Senha (mínimo 6 caracteres)

---

#### `logout.php`

**Propósito:** Encerramento de sessão

**Ações:**

1. Destrói a sessão
2. Limpa cookies de sessão
3. Redireciona para `login.php`

---

#### `dashboard.php`

**Propósito:** Painel principal do vendedor

**Abas:**

1. **Conexão** - Status da conta ML, botão conectar/desconectar
2. **Atividade** - Perguntas pendentes e recentes
3. **Histórico** - Todas as perguntas processadas
4. **Perfil** - Dados do usuário, WhatsApp, toggle IA

**Verificações:**

- Requer login
- Requer assinatura `ACTIVE`
- Atualiza tokens ML se expirados

---

#### `billing.php`

**Propósito:** Gestão de assinatura e pagamentos

**Cenários:**

- `PENDING` → Botão para iniciar pagamento
- `OVERDUE` → Alerta + botão para regularizar
- `CANCELED` → Informação + opção de reativar

**Verificação Dupla:**

- Checa sessão
- Revalida no banco de dados
- Redireciona se já estiver ativo

---

#### `update_profile.php`

**Propósito:** Atualização de dados do perfil

**Campos Atualizáveis:**

- Nome
- Telefone
- Número WhatsApp (para notificações)
- Toggle IA ativada/desativada

**Método:** POST com validação

---

#### `oauth_start.php`

**Propósito:** Inicia fluxo OAuth2 com Mercado Livre

**Fluxo:**

1. Verifica se usuário está logado
2. Verifica se assinatura está ativa
3. Gera token CSRF (`state`)
4. Salva state na sessão
5. Redireciona para página de login do ML

**URL Gerada:**

```
https://auth.mercadolivre.com.br/authorization
  ?response_type=code
  &client_id={ML_CLIENT_ID}
  &redirect_uri={ML_REDIRECT_URI}
  &state={csrf_token}
```

---

#### `oauth_callback.php`

**Propósito:** Processa callback do OAuth2 Mercado Livre

**Fluxo:**

1. Valida parâmetro `state` contra sessão (CSRF)
2. Verifica se recebeu `code`
3. Troca `code` por tokens (access + refresh)
4. Busca dados do usuário ML (`/users/me`)
5. Criptografa tokens com Defuse
6. Salva/atualiza `mercadolibre_users`
7. Redireciona para `dashboard.php`

**Tokens Salvos:**

- `access_token_encrypted`
- `refresh_token_encrypted`
- `token_expires_at`

---

#### `go_to_asaas_payment.php`

**Propósito:** Gera link de pagamento e redireciona

**Cenários:**

1. **Usuário sem assinatura** → Cria nova assinatura
2. **Assinatura existente** → Busca fatura pendente/vencida

**Retorno:**

- Sucesso → Redireciona para `invoiceUrl` do Asaas
- Erro → Redireciona para `billing.php` com mensagem

---

#### `super_admin.php`

**Propósito:** Painel administrativo

**Acesso:**

- Requer `SUPER_ADMIN_SECRET` via GET ou sessão
- Lista todos os usuários SaaS
- Mostra métricas gerais

**Funcionalidades:**

- Ver todos os usuários
- Alterar status de assinatura
- Ver contas ML vinculadas
- Estatísticas do sistema

---

#### `super_admin_actions.php`

**Propósito:** Processa ações do admin

**Ações Disponíveis:**

- Alterar status de assinatura
- Deletar usuário
- Forçar desconexão ML

---

#### `poll_questions.php`

**Propósito:** CRON job para fallback e timeouts

**Funções:**

1. **Polling de Perguntas** - Busca perguntas não respondidas via API (fallback se webhook falhar)
2. **Timeout de Aprovação** - Aprova automaticamente respostas pendentes após X minutos
3. **Limpeza** - Remove registros antigos do log

**Execução:**

```bash
*/5 * * * * php /path/to/poll_questions.php
```

---

#### `test.php`

**Propósito:** Testes de desenvolvimento

**Uso:** Testes manuais de funções e integrações

---

### Webhooks

---

#### `ml_webhook_receiver.php`

**Propósito:** Recebe notificações do Mercado Livre

**Eventos:**

- `questions` - Nova pergunta

**Fluxo:**

1. Valida requisição POST
2. Decodifica JSON do body
3. Extrai `resource` (ID da pergunta)
4. Busca detalhes da pergunta via API
5. Localiza usuário local
6. Verifica se IA está ativada
7. Chama `triggerAiForQuestion()`

---

#### `asaas_webhook_receiver.php`

**Propósito:** Processa eventos de pagamento Asaas

**Segurança:**

- Valida assinatura HMAC-SHA256
- Header: `Asaas-Signature`

**Eventos → Status Local:**
| Evento Asaas | Status Local |
|--------------|--------------|
| `PAYMENT_RECEIVED` | `ACTIVE` |
| `PAYMENT_CONFIRMED` | `ACTIVE` |
| `PAYMENT_OVERDUE` | `OVERDUE` |
| `PAYMENT_FAILED` | `OVERDUE` |
| `SUBSCRIPTION_UPDATED` (canceled) | `CANCELED` |

---

#### `evolution_webhook_receiver.php`

**Propósito:** Processa respostas do vendedor via WhatsApp

**Comandos:**
| Comando | Ação |
|---------|------|
| `1` ou `SIM` | Aprova resposta sugerida |
| `2:texto aqui` | Envia texto customizado |
| `3` ou `NAO` | Rejeita (não responde) |

**Fluxo:**

1. Recebe mensagem do Evolution
2. Identifica pergunta pelo `whatsapp_message_id`
3. Processa comando
4. Publica resposta no ML (se aprovado)
5. Atualiza status no log

---

### Includes (Módulos)

---

#### `includes/core_logic.php`

**Propósito:** Orquestrador principal do processamento de perguntas

**Função Principal:**

```php
triggerAiForQuestion(
    int $questionId,
    int $mlUserId,
    string $accessToken
): array
```

**Fluxo Interno:**

1. Busca detalhes da pergunta (ML API)
2. Busca detalhes do item/produto (ML API)
3. Busca histórico de perguntas do item
4. Chama Agent 1 (Analista) para contexto
5. Chama Agent 2 (Pesquisador) para resposta
6. Salva no `question_processing_log`
7. Envia notificação WhatsApp
8. Retorna resultado

---

#### `includes/agent1.php`

**Propósito:** Sistema de 2 agentes IA

**Agente 1 - Analista:**

```php
agent1_analyze_context(
    array $questionData,
    array $itemData,
    array $previousQA
): string
```

- Analisa o contexto do produto
- Identifica intenção do comprador
- Extrai informações relevantes
- Retorna análise estruturada

**Agente 2 - Pesquisador:**

```php
agent2_generate_grounded_answer(
    string $contextAnalysis,
    array $questionData,
    array $itemData
): string
```

- Usa grounding com Google Search
- Gera resposta baseada em fatos
- Aplica personalidade de vendedor
- Retorna resposta final

---

#### `includes/ml_api.php`

**Propósito:** Funções da API Mercado Livre

**Funções:**

```php
ml_getQuestion(int $questionId, string $accessToken): ?array
```

Busca detalhes de uma pergunta

```php
ml_getItem(string $itemId, string $accessToken): ?array
```

Busca detalhes de um produto

```php
ml_answerQuestion(int $questionId, string $answer, string $accessToken): bool
```

Publica resposta a uma pergunta

```php
ml_getReceivedQuestions(string $accessToken, array $params): ?array
```

Lista perguntas recebidas

```php
ml_refreshToken(string $refreshToken): ?array
```

Renova tokens expirados

```php
ml_getUserInfo(string $accessToken): ?array
```

Busca dados do usuário ML

---

#### `includes/gemini_api.php`

**Propósito:** Cliente da API Google Gemini

**Função Principal:**

```php
callGeminiAPI(
    string $prompt,
    ?string $systemInstruction = null,
    bool $useGrounding = false,
    float $temperature = 0.7,
    int $maxTokens = 1024
): ?string
```

**Parâmetros:**

- `$prompt` - Texto de entrada
- `$systemInstruction` - Instrução de sistema (personalidade)
- `$useGrounding` - Ativa Google Search grounding
- `$temperature` - Criatividade (0.0 - 1.0)
- `$maxTokens` - Limite de tokens na resposta

**Modelo:** `gemini-2.5-flash-lite`

---

#### `includes/evolution_api.php`

**Propósito:** Funções de envio WhatsApp

**Funções:**

```php
sendWhatsAppMessage(
    string $phoneNumber,
    string $message
): ?string
```

Envia mensagem de texto, retorna messageId

```php
sendQuestionNotification(
    string $phoneNumber,
    array $questionData,
    string $suggestedAnswer,
    int $logId
): ?string
```

Envia notificação formatada com opções de resposta

---

#### `includes/asaas_api.php`

**Propósito:** Funções do gateway de pagamento

**Funções:**

```php
asaas_createCustomer(
    string $name,
    string $email,
    string $phone
): ?array
```

Cria cliente no Asaas

```php
asaas_createSubscription(
    string $customerId,
    float $value,
    string $billingType = 'UNDEFINED'
): ?array
```

Cria assinatura recorrente

```php
asaas_getSubscription(string $subscriptionId): ?array
```

Busca detalhes da assinatura

```php
asaas_getPayments(string $subscriptionId): ?array
```

Lista pagamentos da assinatura

```php
asaas_getPaymentLink(string $paymentId): ?string
```

Obtém link de pagamento

---

#### `includes/db_interaction.php`

**Propósito:** CRUD do log de perguntas

**Funções:**

```php
createQuestionLog(array $data): ?int
```

Cria registro de pergunta

```php
updateQuestionLog(int $logId, array $data): bool
```

Atualiza registro

```php
getQuestionLogByQuestionId(int $questionId): ?array
```

Busca por ID da pergunta ML

```php
getQuestionLogByWhatsAppId(string $messageId): ?array
```

Busca por ID da mensagem WhatsApp

```php
getPendingQuestionsForUser(int $mlUserId): array
```

Lista perguntas pendentes

---

#### `includes/curl_helper.php`

**Propósito:** Helper para requisições HTTP

**Função:**

```php
makeCurlRequest(
    string $url,
    string $method = 'GET',
    ?array $data = null,
    array $headers = [],
    int $timeout = 30
): array
```

**Retorno:**

```php
[
    'success' => bool,
    'data' => mixed,
    'http_code' => int,
    'error' => ?string
]
```

---

#### `includes/helpers.php`

**Propósito:** Funções auxiliares de UI

**Funções:**

```php
getSubscriptionStatusClass(string $status): string
```

Retorna classes CSS Tailwind para badges de status

```php
getQuestionStatusClass(string $status): string
```

Retorna classes CSS para status de perguntas

---

#### `includes/log_helper.php`

**Propósito:** Sistema de logging

**Função:**

```php
logMessage(string $message, string $level = 'INFO'): void
```

**Níveis:** `INFO`, `WARNING`, `ERROR`, `DEBUG`

**Destino:** Arquivo de log configurável ou `error_log()`

---

### Dependências (vendor/)

---

#### `vendor/autoload.php`

Autoloader PSR-4 gerado pelo Composer

#### `vendor/defuse/php-encryption/`

Biblioteca de criptografia simétrica (AES-256-CTR)

**Uso no projeto:**

- Criptografar tokens de acesso ML
- Criptografar tokens de refresh ML

#### `vendor/vlucas/phpdotenv/`

Carregamento de variáveis de ambiente (não utilizado ativamente, secrets em PHP)

#### `vendor/symfony/polyfill-*/`

Polyfills para compatibilidade PHP 8.0

---

## 📝 Notas Finais

### Melhorias Sugeridas

1. **Rate Limiting** - Implementar limites de requisições
2. **Queue System** - Usar filas para processamento assíncrono
3. **Caching** - Implementar cache Redis para dados de produtos
4. **Testes** - Adicionar testes unitários e de integração
5. **Docker** - Containerizar a aplicação
6. **CI/CD** - Pipeline de deploy automatizado

### Contatos

Para dúvidas sobre o projeto, consulte a equipe de desenvolvimento.