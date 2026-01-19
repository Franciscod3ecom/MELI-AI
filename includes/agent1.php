<?php
/**
 * Arquivo: includes/agent1.php
 * Versão: v2.0 - Implementa arquitetura de dois agentes (Analista e Pesquisador).
 * Descrição: Contém a lógica para a IA decidir se precisa de busca externa e para gerar a resposta final.
 */

require_once __DIR__ . '/log_helper.php';
require_once __DIR__ . '/gemini_api.php';

/**
 * AGENTE 1: O ANALISTA INTERNO
 * Analisa o contexto e a pergunta para determinar se uma busca externa é necessária.
 * É otimizado para ser rápido, barato e retornar uma decisão estruturada.
 *
 * @param string $mlQuestion A pergunta do cliente.
 * @param string|null $itemTitle O título do anúncio.
 * @param string|null $itemDescription A descrição do anúncio.
 * @param array|null $attrs Os atributos do anúncio.
 * @return array{answer: ?string, requires_external_search: bool}
 */
function agent1_analyze_context(string $mlQuestion, ?string $itemTitle, ?string $itemDescription, ?array $attrs): array
{
    logMessage("[Agente 1 - Analista] Iniciando análise de necessidade de busca.");

    // Formata os atributos técnicos para o prompt
    $attributesText = '';
    if (!empty($attrs)) {
        foreach ($attrs as $attr) {
            $name = $attr['name'] ?? '';
            $value = $attr['value_name'] ?? '';
            if ($name && $value) {
                $attributesText .= "- " . htmlspecialchars(trim($name)) . ": " . htmlspecialchars(trim($value)) . "\n";
            }
        }
    }
    $context = "Título: " . ($itemTitle ?? '[não informado]') . "\n\nDescrição:\n" . ($itemDescription ?? '[não informado]') . "\n\nAtributos:\n" . ($attributesText ?: '[não informado]');

    // Prompt focado em forçar uma saída JSON com a decisão
    $prompt = <<<PROMPT
Você é um assistente de análise de texto. Sua única tarefa é determinar se a resposta para a "PERGUNTA DO CLIENTE" pode ser encontrada com 100% de certeza dentro do "CONTEXTO DO PRODUTO" fornecido.

**Regras:**
1.  Se a resposta exata está no texto, `requires_external_search` deve ser `false`.
2.  Se a pergunta for sobre prazos, frete, garantias, normas técnicas, leis, ou pedir informações que CLARAMENTE não estão no texto, `requires_external_search` deve ser `true`.
3.  Se você encontrar uma resposta parcial, gere-a, mas marque `requires_external_search` como `true` para que ela possa ser complementada.
4.  Sua saída DEVE SER APENAS um objeto JSON válido, com as chaves "answer" (com a resposta que você encontrou, ou null se não encontrou nada) e "requires_external_search" (booleano).

**Exemplo 1:**
- CONTEXTO: "Voltagem: Bivolt (110V/220V)"
- PERGUNTA: "Funciona em 220V?"
- SAÍDA JSON: {"answer": "Sim, funciona perfeitamente em 220V pois é bivolt.", "requires_external_search": false}

**Exemplo 2:**
- CONTEXTO: "Material: Aço Inox."
- PERGUNTA: "Tem garantia de quanto tempo?"
- SAÍDA JSON: {"answer": null, "requires_external_search": true}

---
**CONTEXTO DO PRODUTO:**
{$context}
code
Code
**PERGUNTA DO CLIENTE:**
{$mlQuestion}
code
Code
**Sua saída (APENAS JSON):**
PROMPT;

    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0.0, // Zero criatividade para uma tarefa lógica
            'maxOutputTokens' => 300,
            'responseMimeType' => 'application/json', // Força a saída em JSON
        ]
    ];

    $resp = callGeminiAPI($payload, false); // NUNCA usa busca nesta etapa

    if ($resp['httpCode'] === 200 && $resp['is_json'] && isset($resp['response']['candidates'][0]['content']['parts'][0]['text'])) {
        $jsonText = $resp['response']['candidates'][0]['content']['parts'][0]['text'];
        $decoded = json_decode($jsonText, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['requires_external_search'])) {
            logMessage("[Agente 1 - Analista] Análise concluída. Necessita de busca externa: " . ($decoded['requires_external_search'] ? 'SIM' : 'NÃO'));
            return [
                'answer' => $decoded['answer'] ?? null,
                'requires_external_search' => (bool)$decoded['requires_external_search']
            ];
        }
    }

    logMessage("[Agente 1 - Analista] AVISO: Falha ao decodificar JSON do Agente 1. Assumindo que a busca é necessária como medida de segurança.");
    return ['answer' => null, 'requires_external_search' => true];
}


/**
 * AGENTE 2: O ESPECIALISTA PESQUISADOR
 * Chamado quando a informação interna não é suficiente. Usa o Google Search para
 * encontrar a melhor resposta e formulá-la de acordo com as regras de negócio.
 *
 * @param string $mlQuestion A pergunta do cliente.
 * @param string $itemId O ID do item no ML.
 * @param string|null $itemTitle O título do anúncio.
 * @param string|null $itemDescription A descrição do anúncio.
 * @param array|null $attrs Os atributos do anúncio.
 * @return array{ok:bool, text:?string, raw:mixed, http:int, error:?string}
 */
function agent2_generate_grounded_answer(string $mlQuestion, string $itemId, ?string $itemTitle, ?string $itemDescription, ?array $attrs): array
{
    logMessage("[Agente 2 - Pesquisador] Iniciando geração de resposta com busca externa (Grounding).");

    $attributesText = '';
    if (!empty($attrs)) {
        foreach ($attrs as $attr) {
            $name = $attr['name'] ?? '';
            $value = $attr['value_name'] ?? '';
            if ($name && $value) {
                $attributesText .= "- " . htmlspecialchars(trim($name)) . ": " . htmlspecialchars(trim($value)) . "\n";
            }
        }
    }
    if (empty(trim($attributesText))) { $attributesText = "Nenhum atributo técnico relevante disponível."; }
    $descriptionBlock = "Descrição do Anúncio:\n" . ($itemDescription && !empty(trim($itemDescription)) ? trim($itemDescription) : "Nenhuma descrição detalhada disponível.");
    $titleBlock = "* Título do Anúncio: " . ($itemTitle ?: '[Título indisponível]');
    
    $systemPrompt = <<<PROMPT
**Persona:** Você é um Especialista de Produto da nossa loja no Mercado Livre. Seu tom é profissional, educado, confiante e focado em ajudar o cliente a tomar a decisão de compra.

**Estrutura da Resposta Ideal:**
1.  **Saudação:** Comece com uma saudação curta e amigável (Ex: "Olá!", "Olá! Agradecemos o contato.").
2.  **Resposta Direta:** Responda objetivamente à pergunta do cliente usando as informações do anúncio.
3.  **Valor Agregado:** Se possível, adicione um benefício chave do produto ou uma informação positiva (Ex: "Produto a pronta entrega", "Com nota fiscal").
4.  **Encerramento:** Finalize de forma cordial e proativa (Ex: "Qualquer outra dúvida, estamos à disposição!", "Aguardamos o seu pedido!").

**Regras de Ouro:**
1.  **FONTE DA VERDADE:** Sua resposta deve ser 100% baseada no Título, Descrição e Atributos do anúncio fornecidos. Use a busca do Google apenas para complementar, nunca para contradizer.
2.  **PROIBIDO INVENTAR:** Jamais invente especificações, cores, estoque, compatibilidades ou prazos. É melhor ser honesto sobre la falta de uma informação do que dar uma resposta errada.
3.  **LIDANDO COM INCERTEZA (O QUE FAZER QUANDO NÃO HÁ INFORMAÇÃO):**
    - **NUNCA** diga apenas "Não sei".
    - **TÉCNICA 1:** Afirme o que você sabe e se comprometa a verificar o restante. (Ex: "O produto é bivolt. Vou verificar o consumo em watts com nossa equipe técnica e atualizo a resposta aqui em breve.").
    - **TÉCNICA 2:** Faça uma pergunta para obter mais detalhes. (Ex: "Para confirmar a compatibilidade, qual o ano e modelo do seu veículo?").
4.  **CONCISÃO E CLAREZA:** Mantenha a resposta curta, idealmente abaixo de 250 caracteres. Use parágrafos curtos.
5.  **FILTROS DO MERCADO LIVRE:** Evite qualquer tipo de contato (telefone, e-mail, links). Se precisar citar um número de série ou registro longo, adicione espaços para não ser bloqueado (Ex: "ANVISA 8027 531 0064").
PROMPT;

    $fullPrompt = "Instrução Principal (Constituição do Agente):\n{$systemPrompt}\n\n--- CONTEXTO DO PRODUTO (ID do Anúncio no ML: {$itemId}) ---\n" . $titleBlock . "\n" . $descriptionBlock . "\n* Atributos Técnicos Disponíveis:\n" . $attributesText . "\n-----------------------------------\n\n--- PERGUNTA ORIGINAL DO CLIENTE ---\n{$mlQuestion}\n-----------------------------------\n\nTarefa: Gere a resposta para a pergunta do cliente seguindo RIGOROSAMENTE a Instrução Principal, usando o Contexto do Produto e os resultados da ferramenta de busca. Retorne APENAS o texto da resposta.";

    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $fullPrompt]]]],
        'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 300]
    ];
    
    $resp = callGeminiAPI($payload, true);

    $http = (int)($resp['httpCode'] ?? 0);
    $text = null;
    if ($http === 200 && !empty($resp['response']['candidates'][0]['content']['parts'][0]['text'])) {
        $text = trim($resp['response']['candidates'][0]['content']['parts'][0]['text']);
    }
    
    if (!$text) {
        $text = "Olá! Agradecemos o seu contato. Estamos verificando sua dúvida e responderemos o mais breve possível. Qualquer outra dúvida, estamos à disposição!";
    }

    return ['ok' => ($http === 200 && $text !== null), 'text' => $text, 'raw' => ($resp['response'] ?? $resp), 'http' => $http, 'error' => ($resp['error'] ?? null)];
}
?>