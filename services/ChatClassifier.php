<?php

namespace app\services;

use app\services\LLMProviders\OllamaProvider;

/**
 * Language-agnostic structured chat classifier.
 *
 * Replaces regex-heavy classification in Dapp.php with a single LLM call
 * that returns structured JSON including intent, search params, sentiment.
 * Falls back to fast heuristics for obvious intents (greeting, order#, escalate).
 */
class ChatClassifier
{
    private array $config;
    private array $appConfig;
    private $logger;

    public function __construct(array $classifierConfig, array $appConfig, $logger)
    {
        $this->config = $classifierConfig;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * Classify a customer message. Returns structured result with guaranteed keys.
     */
    public function classify(
        string $message,
        string $conversationHistory,
        string $shopName,
        array  $sessionContext = []
    ): ?array {
        // Phase 1: Heuristic — instant regex for obvious intents
        $heuristic = $this->heuristicClassify($message);
        if ($heuristic) {
            $this->logger->debug('ChatClassifier: heuristic match', [
                'intent' => $heuristic['intent'],
            ]);
            return $heuristic;
        }

        // Phase 2: LLM classifier with structured JSON extraction
        $result = $this->llmClassify($message, $conversationHistory, $shopName, $sessionContext);
        if (!$result) {
            return null;
        }

        // Phase 3: Safety-net post-corrections
        $result = $this->applyPostCorrections($result, $message);

        // Phase 4: Normalize search query for Shopify
        if (!empty($result['search_query'])) {
            $result['search_query'] = $this->normalizeSearchQuery($result['search_query']);
        }

        // Phase 5: Derive search_query if product_search but missing
        if (in_array($result['intent'], ['product_search', 'spare_parts']) && empty($result['search_query'])) {
            $result['search_query'] = $this->deriveSearchQuery($message);
        }

        $this->logger->info('ChatClassifier result', [
            'intent'       => $result['intent'],
            'confidence'   => $result['confidence'] ?? 0,
            'search_query' => $result['search_query'] ?? null,
            'price_min'    => $result['price_min'] ?? null,
            'price_max'    => $result['price_max'] ?? null,
            'sort_by'      => $result['sort_by'] ?? null,
            'result_limit' => $result['result_limit'] ?? null,
            'is_followup'  => $result['is_followup'] ?? false,
        ]);

        return $result;
    }

    // ───────────────────── Heuristic (fast regex) ─────────────────────

    private function heuristicClassify(string $message): ?array
    {
        $lower = mb_strtolower(trim($message));
        $base = [
            'confidence'     => 0.95,
            'sentiment'      => 'neutral',
            'search_query'   => null,
            'order_query'    => null,
            'customer_email' => null,
            'price_min'      => null,
            'price_max'      => null,
            'sort_by'        => null,
            'result_limit'   => null,
            'is_followup'    => false,
            '_source'        => 'heuristic',
        ];

        // Greeting
        if (preg_match('/^(hi|hello|hey|howdy|good (morning|afternoon|evening)|yo|sup)\b/i', $lower)
            || preg_match('/^(thanks?|thank you|thx|bye|goodbye|see ya|cheers|take care)\b/i', $lower)) {
            $sentiment = preg_match('/thanks?|thank you|thx|cheers/i', $lower) ? 'positive' : 'neutral';
            return array_merge($base, ['intent' => 'greeting', 'sentiment' => $sentiment]);
        }

        // Escalate
        if (preg_match('/\b(speak|talk|connect|transfer).*(human|agent|person|representative|support|manager)\b/i', $lower)
            || preg_match('/\b(human|real person|live agent)\b/i', $lower)) {
            return array_merge($base, ['intent' => 'escalate', 'sentiment' => 'frustrated']);
        }

        // Order status — mentions order number or tracking
        if (preg_match('/#(\d{3,})\b/', $message, $m)) {
            return array_merge($base, ['intent' => 'order_status', 'order_query' => '#' . $m[1]]);
        }
        if (preg_match('/\b(where is|track|status of|check on)\s+(my\s+)?order\b/i', $lower)) {
            return array_merge($base, ['intent' => 'order_status']);
        }

        // Order cancel
        if (preg_match('/\bcancel\s+(my\s+)?order\b/i', $lower)) {
            return array_merge($base, ['intent' => 'order_cancel', 'sentiment' => 'negative']);
        }

        // Return/refund
        if (preg_match('/\b(return|refund|send back|exchange)\s+(my|this|the|a)\b/i', $lower)
            || preg_match('/\b(i want|i need|i\'d like)\s+(a\s+)?(return|refund|exchange)\b/i', $lower)) {
            return array_merge($base, ['intent' => 'return_request', 'sentiment' => 'negative']);
        }

        // Send transcript
        if (preg_match('/\b(email|send|mail)\s+(me\s+)?(this\s+)?(chat|transcript|conversation)\b/i', $lower)) {
            return array_merge($base, ['intent' => 'send_transcript']);
        }

        // Identify — email address in message
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $message, $em)) {
            return array_merge($base, ['intent' => 'identify', 'customer_email' => $em[0]]);
        }

        return null;
    }

    // ───────────────────── LLM classifier ─────────────────────

    private function llmClassify(
        string $message,
        string $conversationHistory,
        string $shopName,
        array  $sessionContext
    ): ?array {
        $model   = $this->config['model'] ?? 'qwen2.5:7b';
        $host    = $this->config['host'] ?? 'http://127.0.0.1:11435';
        $timeout = (int) ($this->config['timeout'] ?? 10);

        $personality = $this->appConfig['bot_personality'] ?? [];
        $botName = $personality['name'] ?? 'Assistant';

        // Build conversation state for classifier
        $stateContext = '';
        $lastIntent = $sessionContext['last_intent'] ?? null;
        $interactionState = $sessionContext['interaction_state'] ?? null;
        $lastSentiment = ($sessionContext['last_classify'] ?? [])['sentiment'] ?? null;
        if ($lastIntent || $interactionState) {
            $parts = [];
            if ($lastIntent) $parts[] = "last_intent={$lastIntent}";
            if ($interactionState) $parts[] = "state={$interactionState}";
            if ($lastSentiment) $parts[] = "last_sentiment={$lastSentiment}";
            $stateContext = "\n\nConversation state: " . implode(', ', $parts);
        }

        $systemPrompt = <<<PROMPT
You are {$botName}, a customer support intent classifier for {$shopName}.

Analyze the customer message and respond with a SINGLE JSON object.

INTENTS:
- product_search: Looking for products to buy, browse, or compare
- order_status: Asking about an existing order
- order_update: Changing delivery address or order details
- order_cancel: Cancelling an order
- return_request: Return, refund, or exchange
- repair_request: Repair services
- spare_parts: Replacement parts
- send_transcript: Email this conversation
- faq: Policy, shipping, store questions (NOT product browsing)
- greeting: Hello/goodbye/thanks
- escalate: Wants a human agent
- identify: Providing email or account info
- general: Conversational, follow-up, unclear

EXTRACTION:
- search_query: Product they want (no price/sort qualifiers). Null if not product_search.
- order_query: Order number like "#1234". Null if not mentioned.
- customer_email: Email if provided. Null otherwise.
- price_min: Min price in dollars ("over \$500" -> 500). Null if unstated.
- price_max: Max price in dollars ("under \$800" -> 800). Null if unstated.
- sort_by: ONLY set when user explicitly asks for sorting. Examples in any language: "cheapest"/"les moins chers"/"más baratos" -> PRICE_ASC, "most expensive"/"les plus chers" -> PRICE_DESC, "best selling" -> BEST_SELLING, "newest"/"latest" -> CREATED_AT_DESC. Price filters like "under $800" do NOT imply sorting. Null otherwise.
- result_limit: Specific count ("show me 3" -> 3, "cheapest" -> 1). Null otherwise.
- is_followup: true if referencing prior conversation ("it", "that one", "the first one").
- sentiment: positive or neutral or negative or frustrated or hostile
- confidence: 0.0 to 1.0

RULES:
- is_followup=true -> intent should be "general", not product_search
- faq = policy/shipping, NOT product browsing
- Classify in ANY language
- Maintain sentiment continuity: a frustrated customer stays frustrated unless they express relief
PROMPT;

        $userPrompt = !empty($conversationHistory)
            ? "History:\n{$conversationHistory}{$stateContext}\n\nMessage: \"{$message}\"\nJSON:"
            : "Message: \"{$message}\"\nJSON:";

        try {
            $provider = new OllamaProvider([
                'host'    => $host,
                'model'   => $model,
                'timeout' => $timeout,
            ]);

            $result = $provider->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], [
                'model'          => $model,
                'temperature'    => 0.1,
                'context_length' => 2048,
                'format'         => 'json',
            ]);

            if (!($result['success'] ?? false) || empty($result['response'])) {
                $this->logger->warning('ChatClassifier LLM failed', [
                    'error' => $result['error'] ?? 'empty response',
                ]);
                return null;
            }

            $raw = trim($result['response']);

            // Strip markdown fences / prefixes (safety net — format:json should prevent these)
            if (str_starts_with($raw, '```')) {
                $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
                $raw = preg_replace('/```\s*$/m', '', $raw);
                $raw = trim($raw);
            }
            $raw = preg_replace('/^(?:JSON|Output|Response)\s*:\s*/i', '', $raw);
            $raw = trim($raw);

            $parsed = json_decode($raw, true);
            if (!is_array($parsed) || empty($parsed['intent'])) {
                $this->logger->warning('ChatClassifier invalid JSON', ['raw' => $raw]);
                return null;
            }

            // Ensure all expected keys exist with defaults
            $parsed += [
                'confidence'     => 0.85,
                'sentiment'      => 'neutral',
                'search_query'   => null,
                'order_query'    => null,
                'customer_email' => null,
                'price_min'      => null,
                'price_max'      => null,
                'sort_by'        => null,
                'result_limit'   => null,
                'is_followup'    => false,
                '_source'        => 'llm',
            ];

            // Cast numeric fields
            $parsed['price_min']    = $parsed['price_min'] !== null ? (float) $parsed['price_min'] : null;
            $parsed['price_max']    = $parsed['price_max'] !== null ? (float) $parsed['price_max'] : null;
            $parsed['result_limit'] = $parsed['result_limit'] !== null ? (int) $parsed['result_limit'] : null;
            $parsed['confidence']   = (float) $parsed['confidence'];
            $parsed['is_followup']  = (bool) $parsed['is_followup'];

            // Validate/correct structured params with regex safety net
            $parsed = $this->validateStructuredParams($parsed, $message);

            return $parsed;

        } catch (\Throwable $e) {
            $this->logger->warning('ChatClassifier exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ───────────────────── Structured param validation ─────────────────────

    /**
     * Regex safety net: verify/correct LLM's price, sort, and limit extraction.
     * Small models often swap price_min/max or put garbage in sort_by.
     */
    private function validateStructuredParams(array $parsed, string $message): array
    {
        $lower = mb_strtolower($message);

        // Validate sort_by — must be one of the allowed enum values
        $validSorts = ['PRICE_ASC', 'PRICE_DESC', 'BEST_SELLING', 'CREATED_AT_DESC'];
        if (!empty($parsed['sort_by']) && !in_array($parsed['sort_by'], $validSorts)) {
            $parsed['sort_by'] = null;
        }

        // Regex-based price extraction as ground truth
        $regexMax = null;
        $regexMin = null;
        if (preg_match('/\b(?:under|below|less than|up to|max|cheaper than|no more than)\s*\$?([\d,]+(?:\.\d{2})?)\b/i', $lower, $pm)) {
            $regexMax = (float) str_replace(',', '', $pm[1]);
        }
        if (preg_match('/\b(?:over|above|more than|at least|min|starting at)\s*\$?([\d,]+(?:\.\d{2})?)\b/i', $lower, $pm)) {
            $regexMin = (float) str_replace(',', '', $pm[1]);
        }
        if (preg_match('/\bbetween\s*\$?([\d,]+(?:\.\d{2})?)\s*(?:and|-)\s*\$?([\d,]+(?:\.\d{2})?)\b/i', $lower, $pm)) {
            $regexMin = (float) str_replace(',', '', $pm[1]);
            $regexMax = (float) str_replace(',', '', $pm[2]);
        }

        // Override LLM prices with regex when regex found something definitive
        if ($regexMax !== null) $parsed['price_max'] = $regexMax;
        if ($regexMin !== null) $parsed['price_min'] = $regexMin;

        // Fix swapped min/max (LLM sometimes reverses them)
        if ($parsed['price_min'] !== null && $parsed['price_max'] !== null
            && $parsed['price_min'] > $parsed['price_max']) {
            [$parsed['price_min'], $parsed['price_max']] = [$parsed['price_max'], $parsed['price_min']];
        }

        // Regex-based sort detection as ground truth
        if (preg_match('/\b(most expensive|priciest|highest price|premium)\b/i', $lower)) {
            $parsed['sort_by'] = 'PRICE_DESC';
            if (!$parsed['result_limit']) $parsed['result_limit'] = 1;
        } elseif (preg_match('/\b(cheapest|least expensive|lowest price|most affordable|budget)\b/i', $lower)) {
            $parsed['sort_by'] = 'PRICE_ASC';
            if (!$parsed['result_limit']) $parsed['result_limit'] = 1;
        } elseif (preg_match('/\b(best selling|most popular|top selling)\b/i', $lower)) {
            $parsed['sort_by'] = 'BEST_SELLING';
            if (!$parsed['result_limit']) $parsed['result_limit'] = 1;
        } elseif (preg_match('/\b(newest|latest|just arrived|new arrival)\b/i', $lower)) {
            $parsed['sort_by'] = 'CREATED_AT_DESC';
            if (!$parsed['result_limit']) $parsed['result_limit'] = 1;
        }

        // Detect "top N" / "N best" patterns for result_limit
        if (preg_match('/\b(?:top|best)\s+(\d+)\b/i', $lower, $m)) {
            $parsed['result_limit'] = (int) $m[1];
        } elseif (preg_match('/\b(\d+)\s*(?:good|great|best)?\s*(?:deal|suggestion|recommendation|option|pick)s?\b/i', $lower, $m)) {
            $n = (int) $m[1];
            if ($n > 0 && $n <= 10) $parsed['result_limit'] = $n;
        }

        return $parsed;
    }

    // ───────────────────── Post-corrections ─────────────────────

    private function applyPostCorrections(array $parsed, string $message): array
    {
        $lower = mb_strtolower($message);

        // Correction 1: product_search overreach
        if ($parsed['intent'] === 'product_search') {
            $reclassify = false;

            // Conversational questions that aren't product searches
            if (preg_match('/\b(your name|who are you|what are you|how are you|are you real|are you a bot|are you human|do you poop|tell me about yourself|what can you do)\b/i', $lower)) {
                $reclassify = true;
            }

            // Purchase intent — not a new search, wants to buy something already shown
            if (!$reclassify && preg_match('/\b(buy|purchase|add to cart|order|i\'ll take|i want)\b/i', $lower)
                && preg_match('/\b(it|this|that|the \$[\d,.]+|the one)\b/i', $lower)) {
                $reclassify = true;
            }

            // search_query is a single generic word
            $sq = mb_strtolower($parsed['search_query'] ?? '');
            $genericQueries = ['name', 'help', 'yes', 'no', 'ok', 'sure', 'thanks', 'thank you', 'hi', 'hello', 'hey', 'please', 'fine', 'good', 'great', 'cool'];
            if (!$reclassify && in_array($sq, $genericQueries)) {
                $reclassify = true;
            }

            // LLM flagged as followup — not a new search
            if (!$reclassify && ($parsed['is_followup'] ?? false)) {
                $reclassify = true;
            }

            if ($reclassify) {
                $parsed['_corrected_from'] = 'product_search';
                $parsed['intent'] = 'general';
                $parsed['search_query'] = null;
            }
        }

        // Correction 2: product-related messages misclassified as general/faq/greeting
        if (in_array($parsed['intent'], ['general', 'faq', 'greeting']) && !($parsed['is_followup'] ?? false)) {
            $productSignals = $this->appConfig['product_signals'] ?? [
                'product', 'price', 'shop', 'browse', 'catalog',
                'show me', 'looking for', 'in stock',
                'available', 'how much', 'cost', 'compare',
                'deal', 'deals', 'sale', 'discount', 'cheap',
                'recommend', 'suggestion', 'best seller',
            ];
            $conversational = preg_match('/\b(your name|who are you|what are you|how are you|what can you do|help me)\b/i', $lower);
            if (!$conversational) {
                foreach ($productSignals as $signal) {
                    if (str_contains($lower, $signal)) {
                        $parsed['_corrected_from'] = $parsed['intent'];
                        $parsed['intent'] = 'product_search';
                        break;
                    }
                }
            }
        }

        // Correction 3: upgrade sentiment for profanity/abuse
        if (in_array($parsed['sentiment'] ?? '', ['negative', 'frustrated', 'neutral'])) {
            if (preg_match('/\b(stupid|sucks?|terrible|awful|worst|garbage|trash|bullshit|horrible|disgusting|pathetic|ridiculous|unacceptable|scam|rip.?off|wtf|pissed|furious|livid)\b/i', $lower)
                || preg_match('/\b(fuck|shit|damn|bitch)\b/i', $lower)) {
                $parsed['sentiment'] = 'hostile';
            }
        }

        // Ensure sentiment is always present
        if (empty($parsed['sentiment'])) {
            $parsed['sentiment'] = 'neutral';
        }

        return $parsed;
    }

    // ───────────────────── Search query helpers ─────────────────────

    private function normalizeSearchQuery(string $sq): string
    {
        $sq = trim($sq);

        // De-pluralize basic trailing 's' (snowboards -> snowboard)
        if (preg_match('/\w{4,}s$/i', $sq) && !preg_match('/(wax|glass|dress|pass)$/i', $sq)) {
            $sq = preg_replace('/s$/i', '', $sq);
        }

        // Strip qualifiers that confuse Shopify search
        $sq = preg_replace('/\b(most|least|best|worst|cheapest|expensive|top|premium|budget|affordable|selling|popular|newest|latest)\b/i', '', $sq);
        $sq = trim(preg_replace('/\s+/', ' ', $sq));

        return $sq;
    }

    private function deriveSearchQuery(string $message): ?string
    {
        $stripped = preg_replace('/^(show me|do you have|looking for|i want|find me|search for|whats?|what is|what are|your)\s+/i', '', trim($message));
        $stripped = preg_replace('/\?+$/', '', $stripped);
        $stripped = preg_replace('/^(the |a |an |some |any |most |least |cheapest |expensive |best )+/i', '', $stripped);
        $stripped = trim($stripped);

        return mb_strlen($stripped) >= 2 ? $stripped : null;
    }
}
