# 🚀 Proposta de Refatoração - Meli AI v2.0

> **Versão:** 1.0  
> **Data:** 19 de Janeiro de 2026  
> **Status:** Proposta Técnica  
> **Autor:** Equipe de Desenvolvimento

---

## 📋 Índice

1. [Resumo Executivo](#-resumo-executivo)
2. [Análise da Stack Atual](#-análise-da-stack-atual)
3. [Proposta de Nova Arquitetura](#-proposta-de-nova-arquitetura)
4. [Recomendação de Backend](#-recomendação-de-backend)
5. [Comparativo de Opções de Backend](#-comparativo-de-opções-de-backend)
6. [Arquitetura Proposta Detalhada](#-arquitetura-proposta-detalhada)
7. [Estrutura de Pastas](#-estrutura-de-pastas)
8. [Stack Tecnológica Completa](#-stack-tecnológica-completa)
9. [Banco de Dados](#-banco-de-dados)
10. [Autenticação e Segurança](#-autenticação-e-segurança)
11. [Integrações Externas](#-integrações-externas)
12. [DevOps e Infraestrutura](#-devops-e-infraestrutura)
13. [Plano de Migração](#-plano-de-migração)
14. [Estimativa de Tempo](#-estimativa-de-tempo)
15. [Riscos e Mitigações](#-riscos-e-mitigações)

---

## 📊 Resumo Executivo

### Stack Atual vs. Proposta

| Aspecto            | Atual (PHP)               | Proposta                            |
| ------------------ | ------------------------- | ----------------------------------- |
| **Frontend**       | PHP + Tailwind (SSR)      | **Next.js 14+** (App Router)        |
| **Backend**        | PHP Monolítico            | **Node.js + Fastify** ou **NestJS** |
| **Banco de Dados** | MySQL                     | **PostgreSQL + Prisma ORM**         |
| **Autenticação**   | Sessões PHP               | **NextAuth.js + JWT**               |
| **Filas/Jobs**     | CRON (poll_questions.php) | **BullMQ + Redis**                  |
| **Cache**          | Nenhum                    | **Redis**                           |
| **Hospedagem**     | Hostinger (Shared)        | **Vercel + Railway/Render**         |

### Benefícios Esperados

- ✅ **+80% em segurança** (autenticação robusta, rate limiting nativo)
- ✅ **+60% em performance** (SSR/SSG, cache, edge functions)
- ✅ **+90% em manutenibilidade** (TypeScript, testes, code splitting)
- ✅ **Escalabilidade horizontal** (containers, serverless)
- ✅ **DX (Developer Experience)** muito superior

---

## 🔍 Análise da Stack Atual

### Problemas Identificados

```
┌─────────────────────────────────────────────────────────────┐
│                    STACK ATUAL - PHP                        │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ❌ Código monolítico (tudo junto)                          │
│  ❌ Sem tipagem estática (bugs em runtime)                  │
│  ❌ Webhooks sem autenticação adequada                      │
│  ❌ CRON para jobs (não escalável)                          │
│  ❌ Sem cache (consultas repetidas ao DB)                   │
│  ❌ Sessões em arquivo (não escalável)                      │
│  ❌ Deploy manual (sem CI/CD)                               │
│  ❌ Testes inexistentes                                     │
│  ❌ Difícil de debugar e monitorar                          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Débitos Técnicos Acumulados

| Débito                     | Impacto | Esforço para Corrigir em PHP |
| -------------------------- | ------- | ---------------------------- |
| Falta de tipagem           | Alto    | Impossível sem refatoração   |
| Webhooks inseguros         | Crítico | Médio                        |
| Sem filas de processamento | Alto    | Alto (precisa infra)         |
| Código acoplado            | Alto    | Muito Alto                   |
| Sem testes                 | Alto    | Muito Alto                   |

---

## 🏗 Proposta de Nova Arquitetura

### Arquitetura Híbrida (Recomendada)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           MELI AI v2.0 - ARQUITETURA                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                        FRONTEND (Next.js 14+)                       │   │
│   │  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐        │   │
│   │  │  Landing  │  │   Login   │  │ Dashboard │  │   Admin   │        │   │
│   │  │   Page    │  │  Register │  │   Panel   │  │   Panel   │        │   │
│   │  └───────────┘  └───────────┘  └───────────┘  └───────────┘        │   │
│   │                                                                     │   │
│   │  • App Router (Server Components)                                   │   │
│   │  • Server Actions (formulários)                                     │   │
│   │  • Middleware (auth, rate limit)                                    │   │
│   │  • API Routes (BFF - Backend For Frontend)                         │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                      │                                      │
│                                      ▼                                      │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                    BACKEND API (Node.js + Fastify)                  │   │
│   │  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐        │   │
│   │  │   Auth    │  │    ML     │  │  Asaas    │  │    AI     │        │   │
│   │  │  Service  │  │  Service  │  │  Service  │  │  Service  │        │   │
│   │  └───────────┘  └───────────┘  └───────────┘  └───────────┘        │   │
│   │                                                                     │   │
│   │  • REST API + tRPC (opcional)                                       │   │
│   │  • Validação com Zod                                                │   │
│   │  • Rate Limiting nativo                                             │   │
│   │  • Webhook handlers seguros                                         │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
│                                      │                                      │
│                    ┌─────────────────┼─────────────────┐                    │
│                    ▼                 ▼                 ▼                    │
│   ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐         │
│   │   PostgreSQL     │  │      Redis       │  │     BullMQ       │         │
│   │   (Prisma ORM)   │  │  (Cache/Session) │  │   (Job Queue)    │         │
│   └──────────────────┘  └──────────────────┘  └──────────────────┘         │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 💡 Recomendação de Backend

### 🏆 Recomendação Principal: **Fastify + TypeScript**

Após análise detalhada do projeto Meli AI, recomendo **Fastify** como framework de backend pelos seguintes motivos:

#### Por que Fastify?

| Característica        | Benefício para Meli AI                                 |
| --------------------- | ------------------------------------------------------ |
| **Performance**       | 2-3x mais rápido que Express, importante para webhooks |
| **TypeScript First**  | Tipagem nativa, menos bugs                             |
| **Schema Validation** | Validação JSON automática (Zod/JSON Schema)            |
| **Plugins Ecosystem** | Rate limiting, CORS, JWT, Swagger prontos              |
| **Low Overhead**      | Ideal para serverless (Vercel, Railway)                |
| **Webhook Friendly**  | Excelente para processar payloads JSON                 |

#### Stack Backend Recomendada

```typescript
// Stack Backend Completa
{
  "runtime": "Node.js 20 LTS",
  "framework": "Fastify 4.x",
  "orm": "Prisma 5.x",
  "validation": "Zod",
  "auth": "JWT + Refresh Tokens",
  "queue": "BullMQ",
  "cache": "Redis (ioredis)",
  "logging": "Pino (nativo do Fastify)",
  "testing": "Vitest + Supertest",
  "docs": "Swagger/OpenAPI (auto-gerado)"
}
```

---

## ⚖️ Comparativo de Opções de Backend

### Opção 1: Fastify (⭐ RECOMENDADO)

```
Prós:
✅ Mais rápido que Express
✅ TypeScript nativo
✅ Schema validation built-in
✅ Logging (Pino) built-in
✅ Plugins oficiais de qualidade
✅ Curva de aprendizado moderada
✅ Ótimo para webhooks

Contras:
❌ Menos popular que Express (menos tutoriais)
❌ Alguns plugins precisam de adaptação

Ideal para: APIs REST, Webhooks, Microserviços
```

### Opção 2: NestJS

```
Prós:
✅ Arquitetura enterprise (Angular-like)
✅ Dependency Injection nativo
✅ Módulos bem organizados
✅ Decorators elegantes
✅ GraphQL support excelente
✅ Microserviços built-in

Contras:
❌ Mais pesado (overhead)
❌ Curva de aprendizado alta
❌ Over-engineering para projetos médios
❌ Boilerplate excessivo

Ideal para: Grandes equipes, projetos enterprise
```

### Opção 3: Express.js

```
Prós:
✅ Mais popular (muitos recursos)
✅ Extremamente flexível
✅ Fácil de aprender
✅ Grande ecossistema

Contras:
❌ Sem TypeScript nativo
❌ Sem validation nativa
❌ Mais lento que Fastify
❌ Middleware hell
❌ Precisa de muitas libs extras

Ideal para: Prototipagem rápida, times iniciantes
```

### Opção 4: Hono (Edge-first)

```
Prós:
✅ Ultra leve e rápido
✅ TypeScript first
✅ Roda em Edge (Cloudflare Workers)
✅ API similar ao Express
✅ Zero dependencies

Contras:
❌ Ecossistema menor
❌ Relativamente novo
❌ Menos integrações prontas

Ideal para: Edge computing, APIs simples
```

### Opção 5: Next.js API Routes (Fullstack)

```
Prós:
✅ Tudo em um projeto
✅ Server Actions (formulários)
✅ Edge Functions
✅ Deploys simples (Vercel)

Contras:
❌ Não ideal para webhooks pesados
❌ Cold starts em serverless
❌ Limitado para background jobs
❌ Não separa frontend/backend

Ideal para: MVPs, projetos simples
```

### Tabela Comparativa

| Critério         | Fastify    | NestJS     | Express    | Hono       | Next.js API |
| ---------------- | ---------- | ---------- | ---------- | ---------- | ----------- |
| Performance      | ⭐⭐⭐⭐⭐ | ⭐⭐⭐     | ⭐⭐⭐     | ⭐⭐⭐⭐⭐ | ⭐⭐⭐      |
| TypeScript       | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐     | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐  |
| Curva Aprend.    | ⭐⭐⭐⭐   | ⭐⭐       | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐   | ⭐⭐⭐⭐⭐  |
| Webhooks         | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐   | ⭐⭐⭐⭐   | ⭐⭐⭐⭐   | ⭐⭐⭐      |
| Background Jobs  | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐   | ⭐⭐⭐     | ⭐⭐        |
| Escalabilidade   | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐   | ⭐⭐⭐⭐⭐ | ⭐⭐⭐      |
| Ecossistema      | ⭐⭐⭐⭐   | ⭐⭐⭐⭐   | ⭐⭐⭐⭐⭐ | ⭐⭐⭐     | ⭐⭐⭐⭐⭐  |
| **Para Meli AI** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐   | ⭐⭐⭐     | ⭐⭐⭐⭐   | ⭐⭐⭐      |

### 🎯 Veredicto Final

**Para o Meli AI, recomendo Fastify** porque:

1. **Webhooks são críticos** - Fastify é excelente para processar muitas requisições
2. **Jobs em background** - Integra bem com BullMQ
3. **TypeScript nativo** - Menos bugs, melhor manutenção
4. **Logging incluído** - Pino é o logger mais rápido
5. **Validação incluída** - JSON Schema/Zod nativos
6. **Curva de aprendizado OK** - Não é complexo como NestJS

---

## 📁 Estrutura de Pastas

### Monorepo com Turborepo

```
meli-ai-v2/
├── apps/
│   ├── web/                          # Next.js Frontend
│   │   ├── app/
│   │   │   ├── (auth)/
│   │   │   │   ├── login/
│   │   │   │   │   └── page.tsx
│   │   │   │   ├── register/
│   │   │   │   │   └── page.tsx
│   │   │   │   └── layout.tsx
│   │   │   ├── (dashboard)/
│   │   │   │   ├── dashboard/
│   │   │   │   │   └── page.tsx
│   │   │   │   ├── billing/
│   │   │   │   │   └── page.tsx
│   │   │   │   ├── settings/
│   │   │   │   │   └── page.tsx
│   │   │   │   └── layout.tsx
│   │   │   ├── (admin)/
│   │   │   │   ├── admin/
│   │   │   │   │   └── page.tsx
│   │   │   │   └── layout.tsx
│   │   │   ├── api/
│   │   │   │   └── auth/
│   │   │   │       └── [...nextauth]/
│   │   │   │           └── route.ts
│   │   │   ├── layout.tsx
│   │   │   └── page.tsx
│   │   ├── components/
│   │   │   ├── ui/                   # shadcn/ui components
│   │   │   ├── forms/
│   │   │   └── layout/
│   │   ├── lib/
│   │   │   ├── api.ts                # API client
│   │   │   ├── auth.ts               # NextAuth config
│   │   │   └── utils.ts
│   │   ├── hooks/
│   │   ├── middleware.ts
│   │   ├── next.config.js
│   │   ├── tailwind.config.js
│   │   └── package.json
│   │
│   └── api/                          # Fastify Backend
│       ├── src/
│       │   ├── app.ts                # Fastify instance
│       │   ├── server.ts             # Entry point
│       │   ├── config/
│       │   │   ├── env.ts            # Environment validation
│       │   │   └── constants.ts
│       │   ├── modules/
│       │   │   ├── auth/
│       │   │   │   ├── auth.controller.ts
│       │   │   │   ├── auth.service.ts
│       │   │   │   ├── auth.schema.ts
│       │   │   │   └── auth.routes.ts
│       │   │   ├── users/
│       │   │   │   ├── users.controller.ts
│       │   │   │   ├── users.service.ts
│       │   │   │   ├── users.schema.ts
│       │   │   │   └── users.routes.ts
│       │   │   ├── mercadolibre/
│       │   │   │   ├── ml.controller.ts
│       │   │   │   ├── ml.service.ts
│       │   │   │   ├── ml.schema.ts
│       │   │   │   └── ml.routes.ts
│       │   │   ├── questions/
│       │   │   │   ├── questions.controller.ts
│       │   │   │   ├── questions.service.ts
│       │   │   │   └── questions.routes.ts
│       │   │   ├── ai/
│       │   │   │   ├── ai.service.ts
│       │   │   │   ├── agents/
│       │   │   │   │   ├── analyst.agent.ts
│       │   │   │   │   └── researcher.agent.ts
│       │   │   │   └── gemini.client.ts
│       │   │   ├── payments/
│       │   │   │   ├── asaas.service.ts
│       │   │   │   └── payments.routes.ts
│       │   │   └── whatsapp/
│       │   │       ├── evolution.service.ts
│       │   │       └── whatsapp.routes.ts
│       │   ├── webhooks/
│       │   │   ├── ml.webhook.ts
│       │   │   ├── asaas.webhook.ts
│       │   │   └── evolution.webhook.ts
│       │   ├── jobs/
│       │   │   ├── queue.ts          # BullMQ setup
│       │   │   ├── workers/
│       │   │   │   ├── question.worker.ts
│       │   │   │   └── notification.worker.ts
│       │   │   └── processors/
│       │   │       ├── processQuestion.ts
│       │   │       └── sendWhatsApp.ts
│       │   ├── plugins/
│       │   │   ├── auth.plugin.ts
│       │   │   ├── rateLimit.plugin.ts
│       │   │   └── swagger.plugin.ts
│       │   ├── middleware/
│       │   │   ├── authenticate.ts
│       │   │   └── validateWebhook.ts
│       │   └── utils/
│       │       ├── logger.ts
│       │       ├── errors.ts
│       │       └── crypto.ts
│       ├── prisma/
│       │   ├── schema.prisma
│       │   └── migrations/
│       ├── tests/
│       │   ├── unit/
│       │   └── integration/
│       ├── Dockerfile
│       └── package.json
│
├── packages/
│   ├── database/                     # Prisma schema compartilhado
│   │   ├── prisma/
│   │   │   └── schema.prisma
│   │   └── package.json
│   ├── types/                        # TypeScript types compartilhados
│   │   ├── src/
│   │   │   ├── user.ts
│   │   │   ├── question.ts
│   │   │   └── index.ts
│   │   └── package.json
│   ├── utils/                        # Utilitários compartilhados
│   │   ├── src/
│   │   │   ├── validation.ts
│   │   │   └── formatters.ts
│   │   └── package.json
│   └── config/                       # Configs compartilhadas
│       ├── eslint/
│       ├── typescript/
│       └── tailwind/
│
├── docker-compose.yml
├── turbo.json
├── package.json
└── README.md
```

---

## 🛠 Stack Tecnológica Completa

### Frontend (Next.js 14+)

```json
{
  "dependencies": {
    "next": "^14.1.0",
    "react": "^18.2.0",
    "react-dom": "^18.2.0",
    "next-auth": "^5.0.0",
    "@tanstack/react-query": "^5.17.0",
    "zustand": "^4.4.7",
    "zod": "^3.22.4",
    "react-hook-form": "^7.49.3",
    "@hookform/resolvers": "^3.3.4",
    "tailwindcss": "^3.4.1",
    "@radix-ui/react-*": "latest",
    "lucide-react": "^0.309.0",
    "date-fns": "^3.2.0",
    "sonner": "^1.3.1"
  }
}
```

### Backend (Fastify)

```json
{
  "dependencies": {
    "fastify": "^4.25.2",
    "@fastify/cors": "^8.5.0",
    "@fastify/helmet": "^11.1.1",
    "@fastify/rate-limit": "^9.1.0",
    "@fastify/jwt": "^8.0.0",
    "@fastify/swagger": "^8.13.0",
    "@fastify/swagger-ui": "^2.1.0",
    "@prisma/client": "^5.8.0",
    "bullmq": "^5.1.0",
    "ioredis": "^5.3.2",
    "zod": "^3.22.4",
    "pino": "^8.17.2",
    "pino-pretty": "^10.3.1",
    "bcrypt": "^5.1.1",
    "@google/generative-ai": "^0.2.1",
    "axios": "^1.6.5"
  },
  "devDependencies": {
    "typescript": "^5.3.3",
    "tsx": "^4.7.0",
    "vitest": "^1.2.0",
    "prisma": "^5.8.0",
    "@types/node": "^20.10.8"
  }
}
```

---

## 🗄 Banco de Dados

### PostgreSQL + Prisma Schema

```prisma
// packages/database/prisma/schema.prisma

generator client {
  provider = "prisma-client-js"
}

datasource db {
  provider = "postgresql"
  url      = env("DATABASE_URL")
}

// ==================== ENUMS ====================

enum SubscriptionStatus {
  PENDING
  ACTIVE
  OVERDUE
  CANCELED
  EXPIRED
}

enum QuestionStatus {
  PENDING_AI
  PENDING_APPROVAL
  APPROVED
  REJECTED
  ANSWERED
  TIMEOUT
  ERROR
}

enum UserRole {
  USER
  ADMIN
  SUPER_ADMIN
}

// ==================== MODELS ====================

model User {
  id                    String             @id @default(cuid())
  email                 String             @unique
  passwordHash          String             @map("password_hash")
  name                  String
  cpfCnpj               String?            @map("cpf_cnpj")
  phone                 String?
  whatsappJid           String?            @map("whatsapp_jid")
  role                  UserRole           @default(USER)
  isActive              Boolean            @default(true) @map("is_active")
  emailVerified         DateTime?          @map("email_verified")

  // Asaas
  asaasCustomerId       String?            @map("asaas_customer_id")
  asaasSubscriptionId   String?            @map("asaas_subscription_id")
  subscriptionStatus    SubscriptionStatus @default(PENDING) @map("subscription_status")
  subscriptionExpiresAt DateTime?          @map("subscription_expires_at")

  // Relations
  mercadoLibreAccounts  MercadoLibreAccount[]
  questionLogs          QuestionLog[]
  sessions              Session[]

  // Timestamps
  createdAt             DateTime           @default(now()) @map("created_at")
  updatedAt             DateTime           @updatedAt @map("updated_at")

  @@map("users")
}

model Session {
  id           String   @id @default(cuid())
  userId       String   @map("user_id")
  token        String   @unique
  expiresAt    DateTime @map("expires_at")
  userAgent    String?  @map("user_agent")
  ipAddress    String?  @map("ip_address")

  user         User     @relation(fields: [userId], references: [id], onDelete: Cascade)

  createdAt    DateTime @default(now()) @map("created_at")

  @@index([userId])
  @@map("sessions")
}

model MercadoLibreAccount {
  id                    String    @id @default(cuid())
  userId                String    @map("user_id")
  mlUserId              BigInt    @unique @map("ml_user_id")
  mlNickname            String?   @map("ml_nickname")

  // Tokens (criptografados)
  accessTokenEncrypted  String    @map("access_token_encrypted")
  refreshTokenEncrypted String    @map("refresh_token_encrypted")
  tokenExpiresAt        DateTime  @map("token_expires_at")

  // Config
  isActive              Boolean   @default(true) @map("is_active")
  aiEnabled             Boolean   @default(true) @map("ai_enabled")

  // Relations
  user                  User      @relation(fields: [userId], references: [id], onDelete: Cascade)
  questionLogs          QuestionLog[]

  // Timestamps
  createdAt             DateTime  @default(now()) @map("created_at")
  updatedAt             DateTime  @updatedAt @map("updated_at")

  @@index([userId])
  @@map("mercadolibre_accounts")
}

model QuestionLog {
  id                          String         @id @default(cuid())

  // IDs externos
  mlQuestionId                BigInt         @unique @map("ml_question_id")
  mlUserId                    BigInt         @map("ml_user_id")
  itemId                      String         @map("item_id")

  // Dados da pergunta
  questionText                String         @map("question_text")
  questionFromId              BigInt?        @map("question_from_id")
  questionCreatedAt           DateTime?      @map("question_created_at")

  // IA
  aiContextAnalysis           String?        @map("ai_context_analysis") @db.Text
  aiSuggestedAnswer           String?        @map("ai_suggested_answer") @db.Text
  finalAnswer                 String?        @map("final_answer") @db.Text

  // Status
  status                      QuestionStatus @default(PENDING_AI)
  errorMessage                String?        @map("error_message")

  // WhatsApp
  whatsappNotificationMsgId   String?        @map("whatsapp_notification_msg_id")
  whatsappNotifiedAt          DateTime?      @map("whatsapp_notified_at")

  // Resposta
  answeredAt                  DateTime?      @map("answered_at")
  answeredBy                  String?        @map("answered_by") // 'AI_AUTO', 'USER_APPROVED', 'USER_EDITED'

  // Relations
  userId                      String         @map("user_id")
  user                        User           @relation(fields: [userId], references: [id], onDelete: Cascade)
  mercadoLibreAccountId       String         @map("mercadolibre_account_id")
  mercadoLibreAccount         MercadoLibreAccount @relation(fields: [mercadoLibreAccountId], references: [id], onDelete: Cascade)

  // Timestamps
  createdAt                   DateTime       @default(now()) @map("created_at")
  updatedAt                   DateTime       @updatedAt @map("updated_at")
  lastProcessedAt             DateTime?      @map("last_processed_at")

  @@index([mlUserId])
  @@index([status])
  @@index([userId])
  @@map("question_logs")
}

model WebhookLog {
  id          String   @id @default(cuid())
  source      String   // 'mercadolibre', 'asaas', 'evolution'
  event       String
  payload     Json
  processed   Boolean  @default(false)
  error       String?

  createdAt   DateTime @default(now()) @map("created_at")
  processedAt DateTime? @map("processed_at")

  @@index([source, processed])
  @@map("webhook_logs")
}
```

---

## 🔐 Autenticação e Segurança

### NextAuth.js + JWT

```typescript
// apps/web/lib/auth.ts

import NextAuth from "next-auth";
import CredentialsProvider from "next-auth/providers/credentials";
import { PrismaAdapter } from "@auth/prisma-adapter";
import { prisma } from "@meli-ai/database";
import bcrypt from "bcrypt";

export const { handlers, auth, signIn, signOut } = NextAuth({
  adapter: PrismaAdapter(prisma),
  session: { strategy: "jwt" },
  pages: {
    signIn: "/login",
    error: "/login",
  },
  providers: [
    CredentialsProvider({
      name: "credentials",
      credentials: {
        email: { label: "Email", type: "email" },
        password: { label: "Password", type: "password" },
      },
      async authorize(credentials) {
        if (!credentials?.email || !credentials?.password) {
          throw new Error("Email e senha são obrigatórios");
        }

        const user = await prisma.user.findUnique({
          where: { email: credentials.email as string },
        });

        if (!user || !user.passwordHash) {
          throw new Error("Credenciais inválidas");
        }

        const passwordMatch = await bcrypt.compare(
          credentials.password as string,
          user.passwordHash,
        );

        if (!passwordMatch) {
          throw new Error("Credenciais inválidas");
        }

        if (!user.isActive) {
          throw new Error("Conta desativada");
        }

        return {
          id: user.id,
          email: user.email,
          name: user.name,
          role: user.role,
          subscriptionStatus: user.subscriptionStatus,
        };
      },
    }),
  ],
  callbacks: {
    async jwt({ token, user }) {
      if (user) {
        token.id = user.id;
        token.role = user.role;
        token.subscriptionStatus = user.subscriptionStatus;
      }
      return token;
    },
    async session({ session, token }) {
      if (session.user) {
        session.user.id = token.id as string;
        session.user.role = token.role as string;
        session.user.subscriptionStatus = token.subscriptionStatus as string;
      }
      return session;
    },
  },
});
```

### Middleware de Proteção

```typescript
// apps/web/middleware.ts

import { auth } from "@/lib/auth";
import { NextResponse } from "next/server";

export default auth((req) => {
  const { nextUrl, auth } = req;
  const isLoggedIn = !!auth?.user;
  const isAdmin =
    auth?.user?.role === "ADMIN" || auth?.user?.role === "SUPER_ADMIN";
  const isSubscriptionActive = auth?.user?.subscriptionStatus === "ACTIVE";

  // Rotas públicas
  const publicRoutes = ["/", "/login", "/register"];
  if (publicRoutes.includes(nextUrl.pathname)) {
    if (isLoggedIn) {
      return NextResponse.redirect(new URL("/dashboard", nextUrl));
    }
    return NextResponse.next();
  }

  // Rotas de API de webhooks (públicas, mas validadas internamente)
  if (nextUrl.pathname.startsWith("/api/webhooks")) {
    return NextResponse.next();
  }

  // Exige login
  if (!isLoggedIn) {
    return NextResponse.redirect(new URL("/login", nextUrl));
  }

  // Rotas que exigem assinatura ativa
  const protectedRoutes = ["/dashboard", "/settings"];
  if (protectedRoutes.some((r) => nextUrl.pathname.startsWith(r))) {
    if (!isSubscriptionActive && nextUrl.pathname !== "/billing") {
      return NextResponse.redirect(new URL("/billing", nextUrl));
    }
  }

  // Rotas de admin
  if (nextUrl.pathname.startsWith("/admin")) {
    if (!isAdmin) {
      return NextResponse.redirect(new URL("/dashboard", nextUrl));
    }
  }

  return NextResponse.next();
});

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
```

---

## 🔗 Integrações Externas

### Estrutura de Serviços

```typescript
// apps/api/src/modules/mercadolibre/ml.service.ts

import { prisma } from "@meli-ai/database";
import { decrypt, encrypt } from "../../utils/crypto";
import axios from "axios";

export class MercadoLibreService {
  private readonly baseUrl = "https://api.mercadolibre.com";

  async getAccessToken(accountId: string): Promise<string> {
    const account = await prisma.mercadoLibreAccount.findUnique({
      where: { id: accountId },
    });

    if (!account) throw new Error("Conta ML não encontrada");

    // Verifica se precisa renovar
    if (new Date() >= new Date(account.tokenExpiresAt)) {
      return this.refreshToken(account);
    }

    return decrypt(account.accessTokenEncrypted);
  }

  async refreshToken(account: MercadoLibreAccount): Promise<string> {
    const refreshToken = decrypt(account.refreshTokenEncrypted);

    const response = await axios.post(`${this.baseUrl}/oauth/token`, {
      grant_type: "refresh_token",
      client_id: process.env.ML_CLIENT_ID,
      client_secret: process.env.ML_CLIENT_SECRET,
      refresh_token: refreshToken,
    });

    const { access_token, refresh_token, expires_in } = response.data;

    await prisma.mercadoLibreAccount.update({
      where: { id: account.id },
      data: {
        accessTokenEncrypted: encrypt(access_token),
        refreshTokenEncrypted: encrypt(refresh_token),
        tokenExpiresAt: new Date(Date.now() + expires_in * 1000),
      },
    });

    return access_token;
  }

  async getQuestion(questionId: number, accessToken: string) {
    const response = await axios.get(
      `${this.baseUrl}/questions/${questionId}`,
      { headers: { Authorization: `Bearer ${accessToken}` } },
    );
    return response.data;
  }

  async answerQuestion(questionId: number, text: string, accessToken: string) {
    const response = await axios.post(
      `${this.baseUrl}/answers`,
      { question_id: questionId, text },
      { headers: { Authorization: `Bearer ${accessToken}` } },
    );
    return response.data;
  }
}
```

### Webhook Handler com Validação

```typescript
// apps/api/src/webhooks/asaas.webhook.ts

import { FastifyPluginAsync } from "fastify";
import crypto from "crypto";

export const asaasWebhook: FastifyPluginAsync = async (fastify) => {
  fastify.post("/webhooks/asaas", {
    config: {
      rateLimit: {
        max: 100,
        timeWindow: "1 minute",
      },
    },
    preHandler: async (request, reply) => {
      // Validar assinatura HMAC
      const signature = request.headers["asaas-signature"] as string;
      const payload = JSON.stringify(request.body);

      if (!signature) {
        return reply.status(403).send({ error: "Missing signature" });
      }

      const expectedSignature = crypto
        .createHmac("sha256", process.env.ASAAS_WEBHOOK_SECRET!)
        .update(payload)
        .digest("hex");

      if (
        !crypto.timingSafeEqual(
          Buffer.from(signature),
          Buffer.from(expectedSignature),
        )
      ) {
        fastify.log.warn("Invalid Asaas webhook signature");
        return reply.status(403).send({ error: "Invalid signature" });
      }
    },
    handler: async (request, reply) => {
      const { event, payment, subscription } = request.body as any;

      fastify.log.info({ event }, "Asaas webhook received");

      // Adiciona à fila para processamento
      await fastify.queues.asaas.add("process-payment-event", {
        event,
        payment,
        subscription,
      });

      return reply.status(200).send({ received: true });
    },
  });
};
```

---

## 🚀 DevOps e Infraestrutura

### Docker Compose (Desenvolvimento)

```yaml
# docker-compose.yml

version: "3.8"

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: meli_ai
      POSTGRES_PASSWORD: meli_ai_dev
      POSTGRES_DB: meli_ai
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U meli_ai"]
      interval: 5s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    command: redis-server --appendonly yes

  api:
    build:
      context: .
      dockerfile: apps/api/Dockerfile
    environment:
      DATABASE_URL: postgresql://meli_ai:meli_ai_dev@postgres:5432/meli_ai
      REDIS_URL: redis://redis:6379
      NODE_ENV: development
    ports:
      - "3001:3001"
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_started
    volumes:
      - ./apps/api:/app/apps/api
      - /app/node_modules

volumes:
  postgres_data:
  redis_data:
```

### Opções de Deploy em Produção

```
┌─────────────────────────────────────────────────────────────┐
│                    OPÇÕES DE HOSPEDAGEM                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  OPÇÃO 1: Vercel + Railway (Recomendado para início)       │
│  ┌─────────────┐   ┌─────────────┐   ┌─────────────┐       │
│  │   Vercel    │   │   Railway   │   │   Railway   │       │
│  │  (Next.js)  │   │  (Fastify)  │   │ (Postgres   │       │
│  │   FREE/Pro  │   │   $5-20/mo  │   │  + Redis)   │       │
│  └─────────────┘   └─────────────┘   └─────────────┘       │
│  Custo: ~$15-40/mês                                        │
│                                                             │
│  OPÇÃO 2: Vercel + Render                                  │
│  ┌─────────────┐   ┌─────────────┐   ┌─────────────┐       │
│  │   Vercel    │   │   Render    │   │   Render    │       │
│  │  (Next.js)  │   │  (Fastify)  │   │ (Postgres)  │       │
│  │   FREE/Pro  │   │   $7/mo+    │   │   FREE+     │       │
│  └─────────────┘   └─────────────┘   └─────────────┘       │
│  Custo: ~$10-30/mês                                        │
│                                                             │
│  OPÇÃO 3: DigitalOcean (Mais Controle)                     │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                 DigitalOcean App Platform            │   │
│  │   Next.js + Fastify + Managed Postgres + Redis      │   │
│  │   $20-50/mês (tudo incluído)                        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  OPÇÃO 4: AWS/GCP (Enterprise)                             │
│  ECS/Cloud Run + RDS/Cloud SQL + ElastiCache               │
│  Custo: $50-200+/mês                                       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 📅 Plano de Migração

### Fase 1: Setup Inicial (Semana 1-2)

```
□ Criar monorepo com Turborepo
□ Configurar Next.js 14 com App Router
□ Configurar Fastify com TypeScript
□ Configurar Prisma com PostgreSQL
□ Configurar Docker Compose
□ Configurar ESLint + Prettier
□ Configurar CI/CD básico (GitHub Actions)
```

### Fase 2: Autenticação (Semana 3)

```
□ Implementar NextAuth.js
□ Criar páginas de login/registro
□ Implementar middleware de proteção
□ Migrar tabela de usuários
□ Testar fluxo completo de auth
```

### Fase 3: Core Features (Semana 4-5)

```
□ Dashboard principal
□ Integração OAuth Mercado Livre
□ Página de billing/assinatura
□ Integração Asaas
□ Migrar dados de usuários existentes
```

### Fase 4: IA e Webhooks (Semana 6-7)

```
□ Webhook handler Mercado Livre (seguro)
□ Webhook handler Asaas (com HMAC)
□ Webhook handler Evolution
□ Serviço de IA (Gemini)
□ Sistema de agentes (Analyst + Researcher)
□ Fila de processamento (BullMQ)
```

### Fase 5: WhatsApp e Notificações (Semana 8)

```
□ Integração Evolution API
□ Sistema de notificações
□ Fluxo de aprovação de respostas
□ Testes end-to-end
```

### Fase 6: Admin e Polimento (Semana 9-10)

```
□ Painel administrativo
□ Logs e monitoramento
□ Otimizações de performance
□ Testes de carga
□ Documentação final
□ Deploy em produção
```

---

## ⏱ Estimativa de Tempo

| Fase      | Descrição              | Duração        | Desenvolvedores |
| --------- | ---------------------- | -------------- | --------------- |
| 1         | Setup e Infraestrutura | 2 semanas      | 1               |
| 2         | Autenticação           | 1 semana       | 1               |
| 3         | Core Features          | 2 semanas      | 1-2             |
| 4         | IA e Webhooks          | 2 semanas      | 1-2             |
| 5         | WhatsApp               | 1 semana       | 1               |
| 6         | Admin e Deploy         | 2 semanas      | 1               |
| **Total** |                        | **10 semanas** | **1-2 devs**    |

### Com Paralelização (2 desenvolvedores)

- **Frontend Developer**: Fases 1-3 + UI/UX
- **Backend Developer**: Fases 4-6 + Integrações
- **Tempo Total: 6-7 semanas**

---

## ⚠️ Riscos e Mitigações

| Risco                           | Probabilidade | Impacto | Mitigação                            |
| ------------------------------- | ------------- | ------- | ------------------------------------ |
| Curva de aprendizado TypeScript | Média         | Médio   | Treinamento prévio, pair programming |
| Migração de dados               | Baixa         | Alto    | Scripts de migração testados, backup |
| Downtime durante migração       | Média         | Alto    | Deploy paralelo, DNS switch rápido   |
| Incompatibilidade APIs externas | Baixa         | Médio   | Testes de integração antecipados     |
| Custo de infraestrutura         | Média         | Baixo   | Começar com tier gratuito, escalar   |

---

## 📝 Próximos Passos

1. **Aprovar esta proposta** com stakeholders
2. **Definir equipe** (1-2 desenvolvedores)
3. **Criar repositório** do novo projeto
4. **Setup inicial** do monorepo
5. **Iniciar Fase 1** (Setup e Infraestrutura)

---

## 🔗 Recursos Úteis

- [Next.js 14 Documentation](https://nextjs.org/docs)
- [Fastify Documentation](https://fastify.dev/docs/latest/)
- [Prisma Documentation](https://www.prisma.io/docs)
- [NextAuth.js Documentation](https://next-auth.js.org/)
- [BullMQ Documentation](https://docs.bullmq.io/)
- [Turborepo Documentation](https://turbo.build/repo/docs)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)

---

> **Documento preparado para discussão técnica.**  
> Sujeito a alterações conforme feedback da equipe.
