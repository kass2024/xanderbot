<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\KnowledgeBase;
use App\Models\AiCache;

class AIEngine
{
    protected string $model;

    // Tunable thresholds - Lowered to use AI more readily
    protected float $faqThreshold = 0.75;
    protected float $groundThreshold = 0.50;
    protected int $candidateLimit = 5;
    protected int $timeout = 30;

    // Disable in production
    protected bool $debug = true;

    // Greeting patterns
    protected array $greetings = [
        'hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening'
    ];

    // Human escalation keywords
    protected array $humanKeywords = [
        'human', 'agent', 'support', 'representative', 'talk to someone',
        'call me', 'customer care', 'live agent', 'speak to human'
    ];

    public function __construct()
    {
        $this->model = config('services.openai.model', 'gpt-4');
    }

    /*
    |--------------------------------------------------------------------------
    | MAIN ENTRY
    |--------------------------------------------------------------------------
    */

    public function reply(int $clientId, string $message, $conversation = null): array
    {
        $requestId = Str::uuid()->toString();
        $originalMessage = $message;
        $normalized = $this->normalize($message);
        $hash = hash('sha256', $clientId . $normalized);

        $this->log('MESSAGE_RECEIVED', [
            'conversation_id' => $conversation?->id,
            'original' => $originalMessage,
            'normalized' => $normalized
        ], $requestId);

        /*
        |--------------------------------------------------------------------------
        | HUMAN MODE PROTECTION
        |--------------------------------------------------------------------------
        */

        if ($conversation && $conversation->isEscalated()) {
            $this->log('HUMAN_OR_ESCALATED_ACTIVE', [
                'conversation_id' => $conversation->id
            ], $requestId);

            return [
                'text' => '',
                'attachments' => [],
                'confidence' => 0,
                'source' => 'human_active'
            ];
        }

        try {
            /*
            |--------------------------------------------------------------------------
            | EMPTY MESSAGE
            |--------------------------------------------------------------------------
            */

            if ($normalized === '') {
                return $this->greetingResponse();
            }

            /*
            |--------------------------------------------------------------------------
            | USER REQUESTED HUMAN
            |--------------------------------------------------------------------------
            */

            if ($this->needsHuman($normalized)) {
                $this->log('HUMAN_REQUESTED', [
                    'conversation_id' => $conversation?->id
                ], $requestId);

                return $this->handoverToHuman($conversation, $requestId);
            }

            /*
            |--------------------------------------------------------------------------
            | GREETING DETECTION
            |--------------------------------------------------------------------------
            */

            if ($this->isGreeting($normalized)) {
                $this->log('GREETING_DETECTED', [], $requestId);
                return $this->greetingResponse();
            }

            /*
            |--------------------------------------------------------------------------
            | STAGE 1: DIRECT DATABASE SEARCH (MULTIPLE STRATEGIES)
            |--------------------------------------------------------------------------
            */

            // Strategy 1: Direct LIKE query on question (MOST RELIABLE)
            $directMatch = $this->findDirectMatch($clientId, $originalMessage, $normalized);
            if ($directMatch) {
                $this->log('DIRECT_DB_MATCH', [
                    'question' => $directMatch->question,
                    'id' => $directMatch->id
                ], $requestId);

                $response = $this->formatFromKnowledge($directMatch, 1.0, 'direct_match');
                return $this->store($clientId, $hash, $response);
            }

            // Strategy 1b: Fuzzy similarity on question text (typos / short paraphrases)
            $similarMatch = $this->findBySimilarQuestion($clientId, $normalized);
            if ($similarMatch) {
                $this->log('SIMILAR_QUESTION_MATCH', [
                    'question' => $similarMatch->question,
                    'id' => $similarMatch->id,
                ], $requestId);

                $response = $this->formatFromKnowledge($similarMatch, 0.92, 'similar_question');
                return $this->store($clientId, $hash, $response);
            }

            // Strategy 2: Keyword search across question + answer (grouped SQL)
            $keywordMatch = $this->findKeywordDatabaseMatch($clientId, $normalized);
            if ($keywordMatch) {
                $this->log('KEYWORD_DB_MATCH', [
                    'question' => $keywordMatch->question,
                    'id' => $keywordMatch->id,
                ], $requestId);

                $response = $this->formatFromKnowledge($keywordMatch, 0.95, 'keyword_match');
                return $this->store($clientId, $hash, $response);
            }

            // Strategy 3: Token overlap on question + answer (strong FAQ signal before AI)
            $overlapMatch = $this->findByTokenOverlap($clientId, $normalized);
            if ($overlapMatch) {
                $this->log('FAQ_TOKEN_OVERLAP', [
                    'question' => $overlapMatch->question,
                    'id' => $overlapMatch->id,
                ], $requestId);

                $response = $this->formatFromKnowledge($overlapMatch, 0.88, 'faq_token_overlap');
                return $this->store($clientId, $hash, $response);
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK CACHE (After direct DB attempts)
            |--------------------------------------------------------------------------
            */

            $cached = $this->getCached($clientId, $hash);
            if ($cached) {
                $this->log('CACHE_HIT', [
                    'source' => $cached['source'] ?? 'unknown'
                ], $requestId);
                return $cached;
            }

            /*
            |--------------------------------------------------------------------------
            | STAGE 2: SEMANTIC RETRIEVAL (if embeddings work)
            |--------------------------------------------------------------------------
            */

            $candidates = $this->retrieveCandidates($clientId, $normalized, $requestId);

            if (!empty($candidates)) {
                $best = $candidates[0];

                $this->log('SEMANTIC_TOP_MATCH', [
                    'score' => round($best['score'], 4),
                    'question' => $best['knowledge']->question,
                    'id' => $best['knowledge']->id
                ], $requestId);

                if ($best['score'] >= $this->faqThreshold) {
                    $this->log('FAQ_SEMANTIC_MATCH', [
                        'score' => $best['score']
                    ], $requestId);

                    $response = $this->formatFromKnowledge(
                        $best['knowledge'],
                        $best['score'],
                        'semantic_match'
                    );

                    return $this->store($clientId, $hash, $response);
                }

                if ($best['score'] >= $this->groundThreshold) {
                    $this->log('GROUNDED_AI_MODE', [
                        'score' => $best['score']
                    ], $requestId);

                    return $this->handleGroundedAI(
                        $clientId,
                        $hash,
                        $originalMessage,
                        $candidates,
                        $requestId
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | STAGE 3: PURE AI (Last Resort)
            |--------------------------------------------------------------------------
            */

            $this->log('PURE_AI_MODE', [
                'candidates_count' => count($candidates)
            ], $requestId);

            $response = $this->handlePureAI(
                $clientId,
                $hash,
                $originalMessage,
                $requestId
            );

            /*
            |--------------------------------------------------------------------------
            | LOW CONFIDENCE ESCALATION
            |--------------------------------------------------------------------------
            */

            if (($response['confidence'] ?? 1) < 0.35) {
                $this->log('AI_LOW_CONFIDENCE_ESCALATION', [
                    'confidence' => $response['confidence'] ?? null
                ], $requestId);

                return $this->handoverToHuman($conversation, $requestId);
            }

            return $response;

        } catch (\Throwable $e) {
            Log::error('AIENGINE_FATAL', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId
            ]);

            return $this->errorResponse();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DIRECT DATABASE SEARCH METHODS (MOST RELIABLE)
    |--------------------------------------------------------------------------
    */

    /**
     * Find direct match using SQL LIKE queries
     */
    protected function findDirectMatch(int $clientId, string $original, string $normalized): ?KnowledgeBase
    {
        // Try exact match with original message
        $match = KnowledgeBase::forClient($clientId)
            ->active()
            ->whereRaw('LOWER(question) = ?', [Str::lower($original)])
            ->with('attachments')
            ->first();

        if ($match) {
            return $match;
        }

        // Try exact match with normalized message
        $match = KnowledgeBase::forClient($clientId)
            ->active()
            ->whereRaw('LOWER(question) = ?', [$normalized])
            ->with('attachments')
            ->first();

        if ($match) {
            return $match;
        }

        // Try contains match (question contains key phrase)
        $keyPhrases = [
            'what is xander',
            'xander global',
            'visa consultant',
            'study visa',
            'documents required',
            'visa process',
            'scholarships',
            'university admission',
            'countries apply'
        ];

        foreach ($keyPhrases as $phrase) {
            if (str_contains($normalized, $phrase) || str_contains(Str::lower($original), $phrase)) {
                $match = KnowledgeBase::forClient($clientId)
                    ->active()
                    ->where('question', 'LIKE', '%' . $phrase . '%')
                    ->with('attachments')
                    ->first();

                if ($match) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * Find match by extracting and matching keywords (question + answer)
     */
    protected function findKeywordDatabaseMatch(int $clientId, string $message): ?KnowledgeBase
    {
        $keywords = $this->extractImportantKeywords($message);

        if (empty($keywords)) {
            return null;
        }

        $this->log('EXTRACTED_KEYWORDS', [
            'keywords' => array_values($keywords),
        ], uniqid());

        $query = KnowledgeBase::forClient($clientId)->active();

        $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 2) {
                    $like = '%'.$keyword.'%';
                    $q->orWhere('question', 'LIKE', $like)
                        ->orWhere('answer', 'LIKE', $like);
                }
            }
        });

        $results = $query->with('attachments')->get();

        if ($results->isEmpty()) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;
        $keywordCount = max(count($keywords), 1);

        foreach ($results as $faq) {
            $haystack = Str::lower($faq->question.' '.$faq->answer);
            $hits = 0;

            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 2 && str_contains($haystack, $keyword)) {
                    $hits++;
                }
            }

            $score = $hits / $keywordCount;
            $questionLower = Str::lower($faq->question);
            if (str_contains($questionLower, $message) || str_contains($message, Str::substr($questionLower, 0, min(40, strlen($questionLower))))) {
                $score += 0.35;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $faq;
            }
        }

        return $bestScore >= 0.25 ? $bestMatch : null;
    }

    /**
     * similar_text() over active FAQ questions — catches near-duplicates
     */
    protected function findBySimilarQuestion(int $clientId, string $normalized): ?KnowledgeBase
    {
        if (strlen($normalized) < 4) {
            return null;
        }

        $best = null;
        $bestPct = 0.0;

        $faqs = KnowledgeBase::forClient($clientId)
            ->active()
            ->get(['id', 'question']);

        foreach ($faqs as $faq) {
            $q = Str::lower(trim($faq->question));
            if ($q === '') {
                continue;
            }
            similar_text($normalized, $q, $pct);
            if ($pct > $bestPct) {
                $bestPct = $pct;
                $best = $faq;
            }
        }

        if ($best && $bestPct >= 48.0) {
            return KnowledgeBase::forClient($clientId)
                ->active()
                ->with('attachments')
                ->find($best->id);
        }

        return null;
    }

    /**
     * Token overlap between user message and FAQ question+answer
     */
    protected function findByTokenOverlap(int $clientId, string $normalized): ?KnowledgeBase
    {
        $userTokens = array_unique(array_filter(
            explode(' ', $normalized),
            fn ($t) => strlen($t) > 2
        ));

        if (count($userTokens) < 2) {
            return null;
        }

        $faqs = KnowledgeBase::forClient($clientId)->active()->get(['id', 'question', 'answer']);
        $best = null;
        $bestScore = 0.0;

        foreach ($faqs as $faq) {
            $blob = Str::lower($faq->question.' '.$faq->answer);
            $blob = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $blob);
            $blobTokens = array_unique(array_filter(
                explode(' ', (string) $blob),
                fn ($t) => strlen($t) > 2
            ));

            if (count($blobTokens) < 2) {
                continue;
            }

            $overlap = count(array_intersect($userTokens, $blobTokens));
            if ($overlap < 2) {
                continue;
            }

            $score = $overlap / max(count($userTokens), count($blobTokens), 1);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $faq;
            }
        }

        if ($best && $bestScore >= 0.22) {
            return KnowledgeBase::forClient($clientId)
                ->active()
                ->with('attachments')
                ->find($best->id);
        }

        return null;
    }

    /**
     * Extract important keywords from message
     */
    protected function extractImportantKeywords(string $message): array
    {
        $stopwords = [
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'what', 'how',
            'can', 'you', 'your', 'have', 'has', 'need', 'want', 'about',
            'please', 'thanks', 'thank', 'would', 'could', 'should', 'tell',
            'know', 'like', 'just', 'get', 'are', 'not', 'was', 'were', 'will',
            'help', 'me', 'any', 'some', 'does', 'did',
        ];

        $words = explode(' ', $message);
        
        return array_filter($words, function($word) use ($stopwords) {
            $word = trim($word);
            return strlen($word) >= 3 && !in_array($word, $stopwords);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RETRIEVAL (Fallback method)
    |--------------------------------------------------------------------------
    */

    protected function retrieveCandidates(int $clientId, string $message, string $requestId): array
    {
        try {
            $embeddingService = app(\App\Services\Chatbot\EmbeddingService::class);
            $queryVector = $embeddingService->generate($message);

            if (!$queryVector) {
                $this->log('EMBEDDING_FAILED', [], $requestId);
                return [];
            }

            $items = KnowledgeBase::forClient($clientId)
                ->active()
                ->whereNotNull('embedding')
                ->with('attachments')
                ->get();

            $results = [];
            $keywords = $this->extractImportantKeywords($message);

            foreach ($items as $item) {
                if (!is_array($item->embedding)) {
                    continue;
                }

                // Base cosine similarity
                $score = $this->cosine($queryVector, $item->embedding);

                /*
                |--------------------------------------------------------------------------
                | KEYWORD BOOST
                |--------------------------------------------------------------------------
                */

                $questionText = Str::lower($item->question);
                $boost = 0;

                foreach ($keywords as $keyword) {
                    if (str_contains($questionText, $keyword)) {
                        $boost += 0.05;
                    }
                }

                $boost = min($boost, 0.20);
                $finalScore = $score + $boost;

                $results[] = [
                    'knowledge' => $item,
                    'score' => $finalScore
                ];
            }

            usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($results, 0, $this->candidateLimit);

        } catch (\Throwable $e) {
            $this->log('RETRIEVAL_ERROR', [
                'error' => $e->getMessage()
            ], $requestId);
            return [];
        }
    }

    protected function cosine(array $a, array $b): float
    {
        $dot = 0; $normA = 0; $normB = 0;

        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0);
            $normA += $v * $v;
            $normB += ($b[$i] ?? 0) * ($b[$i] ?? 0);
        }

        return $dot / (sqrt($normA) * sqrt($normB) + 1e-10);
    }

    /*
    |--------------------------------------------------------------------------
    | AI MODES
    |--------------------------------------------------------------------------
    */

    protected function handlePureAI(int $clientId, string $hash, string $message, string $requestId): array
    {
        $prompt = "You are a professional visa and education consultant working for Xander Global Scholars, a reputable education consultancy.

Your role is to provide accurate, helpful information about:
- Student visa applications and requirements for various countries
- Study abroad programs and university applications
- Scholarship opportunities and financial aid
- Education consultancy services
- Application processes and document requirements
- Country-specific immigration information

Guidelines:
1. Be professional, friendly, and helpful
2. Provide accurate and up-to-date information
3. If you're not certain about specific requirements, recommend checking official sources
4. Suggest consulting with our expert consultants for complex cases
5. Keep responses comprehensive but easy to understand
6. Mention our services when relevant

User question: $message";

        $answer = $this->callOpenAI($prompt, $requestId);

        return $this->store(
            $clientId,
            $hash,
            $this->formatResponse(
                $answer ?? "I'm here to help with your visa and study abroad questions! Xander Global Scholars provides comprehensive consultancy services for international education.

I can assist with information about:
• Student visa requirements and applications
• Study abroad programs and universities
• Scholarship opportunities
• Application processes and required documents
• Country-specific guidance

For personalized assistance with your specific situation, I recommend consulting with our expert education consultants who can provide tailored guidance for your goals.",
                [],
                0.50,
                'pure_ai'
            )
        );
    }

    protected function handleGroundedAI(
        int $clientId,
        string $hash,
        string $message,
        array $candidates,
        string $requestId
    ): array {

        $context = collect($candidates)
            ->take(3)
            ->map(fn($c) => 
                "Question: {$c['knowledge']->question}\nAnswer: {$c['knowledge']->answer}"
            )
            ->implode("\n\n");

        $prompt = "
You are a professional visa and immigration assistant working for a visa consultancy.

Your role is to help users by answering questions using the provided knowledge base context.

GUIDELINES:
1. Use the CONTEXT as the primary source of truth.
2. If the user's question is similar to information in the context, provide the closest relevant answer.
3. You may paraphrase or summarize the context to make the answer clearer.
4. Do NOT invent facts that are completely unrelated to the context.
5. If the question is partially related, provide the most helpful information available.
6. If the question is completely unrelated to the context, respond politely with:
   \"I will connect you with a human agent for further assistance.\"
7. Keep answers short, clear, and professional.
8. Do not mention the word 'context' or explain how you generated the answer.

CONTEXT:
$context

USER QUESTION:
$message

Provide the best possible helpful answer using the information above.
";

        $answer = $this->callOpenAI($prompt, $requestId);

        $response = $this->formatResponse(
            $answer ?? "Based on available information, I can help with visa and study abroad services. For detailed guidance, please contact our expert consultants.",
            $candidates[0]['knowledge']->attachments ?? [],
            0.65,
            'grounded_ai'
        );

        return $this->store($clientId, $hash, $response);
    }

    protected function callOpenAI(string $prompt, string $requestId): ?string
    {
        try {
            $apiKey = config('services.openai.key');
            
            if (!$apiKey) {
                Log::error('OPENAI_KEY_MISSING', [
                    'request_id' => $requestId
                ]);
                return null;
            }

            $response = Http::withToken($apiKey)
                ->timeout($this->timeout)
                ->retry(2, 500)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system', 
                            'content' => 'You are a professional visa and education consultant for Xander Global Scholars. Provide accurate, helpful, and detailed responses about visa services, study abroad programs, and educational opportunities. Always be professional and friendly. If you are not certain about specific visa requirements, advise users to consult with official sources or our expert consultants.'
                        ],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 800
                ]);

            if ($response->failed()) {
                Log::error('OPENAI_FAILED', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'request_id' => $requestId
                ]);
                return null;
            }

            $json = $response->json();
            $content = $json['choices'][0]['message']['content'] ?? null;
            
            if ($content) {
                Log::info('OPENAI_SUCCESS', [
                    'response_preview' => substr($content, 0, 100),
                    'request_id' => $requestId
                ]);
            }

            return $content;

        } catch (\Throwable $e) {
            Log::error('OpenAI ERROR', [
                'error' => $e->getMessage(),
                'request_id' => $requestId
            ]);
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function normalize(string $text): string
    {
        // Remove extra spaces
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        // Remove punctuation but keep important characters
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        
        // Remove multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);
        
        return Str::lower(trim($text));
    }

    protected function isGreeting(string $msg): bool
    {
        return in_array($msg, $this->greetings);
    }

    protected function needsHuman(string $message): bool
    {
        foreach ($this->humanKeywords as $word) {
            if (str_contains($message, $word)) {
                Log::info('AI_ESCALATION_KEYWORD', [
                    'keyword' => $word,
                    'message' => $message
                ]);
                return true;
            }
        }
        return false;
    }

    protected function handoverToHuman($conversation, string $requestId): array
    {
        if ($conversation) {
            $conversation->update([
                'status' => 'human',
                'escalation_reason' => 'user_requested',
                'last_activity_at' => now()
            ]);

            $this->log('ESCALATED_TO_HUMAN', [
                'conversation_id' => $conversation->id
            ], $requestId);

            try {
                $router = app(\App\Services\AgentRouter::class);
                $agent = $router->assignAgent($conversation);

                if ($agent) {
                    app(\App\Services\AgentNotifier::class)
                        ->notifyAgent($agent, $conversation);
                }
            } catch (\Throwable $e) {
                Log::error('AGENT_ASSIGNMENT_FAILED', [
                    'error' => $e->getMessage(),
                    'conversation_id' => $conversation->id
                ]);
            }
        }

        return [
            'text' => "I'm connecting you to a human agent 👩‍💻 Please wait.",
            'attachments' => [],
            'confidence' => 1,
            'source' => 'handover'
        ];
    }

    protected function getCached(int $clientId, string $hash): ?array
    {
        $cached = AiCache::where('client_id', $clientId)
            ->where('message_hash', $hash)
            ->where('created_at', '>', now()->subHour())
            ->first();

        if ($cached) {
            $decoded = json_decode($cached->response, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    protected function formatFromKnowledge($knowledge, float $confidence, string $source): array
    {
        $attachments = [];

        if ($knowledge->relationLoaded('attachments') && $knowledge->attachments) {
            foreach ($knowledge->attachments as $attachment) {
                if (!$attachment->url && !$attachment->file_path) {
                    continue;
                }

                $type = strtolower($attachment->type ?? 'document');

                if (in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $type = 'image';
                }

                if (in_array($type, ['pdf', 'doc', 'docx'])) {
                    $type = 'document';
                }

                $url = $attachment->file_path 
                    ? asset('storage/' . ltrim($attachment->file_path, '/'))
                    : $attachment->url;

                $filename = $attachment->file_path 
                    ? basename($attachment->file_path)
                    : basename(parse_url($attachment->url, PHP_URL_PATH));

                $attachments[] = [
                    'type'     => $type,
                    'url'      => $url,
                    'filename' => $filename,
                ];
            }
        }

        return [
            'text'        => $knowledge->answer ?? '',
            'attachments' => $attachments,
            'confidence'  => $confidence,
            'source'      => $source,
        ];
    }

    protected function formatResponse(string $text, array $attachments, float $confidence, string $source): array
    {
        return [
            'text' => $text,
            'attachments' => $attachments,
            'confidence' => $confidence,
            'source' => $source
        ];
    }

    protected function greetingResponse(): array
    {
        return [
            'text' => "Hello! 👋 Welcome to Xander Global Scholars!\n\nI'm your virtual assistant for visa and education services. I can help you with:\n\n📋 **Student Visa Requirements**\n🎓 **Study Abroad Programs**\n🏛️ **University Applications**\n💰 **Scholarship Opportunities**\n📝 **Application Processes**\n🌍 **Country-specific Guidance**\n\nJust ask me anything about studying abroad or visa services!\n\nNeed to speak to a human? Type 'talk to human' anytime.",
            'attachments' => [],
            'confidence' => 1.0,
            'source' => 'greeting'
        ];
    }

    protected function errorResponse(): array
    {
        return [
            'text' => "I'm experiencing technical difficulties. Please try again in a moment.",
            'attachments' => [],
            'confidence' => 0,
            'source' => 'error'
        ];
    }

    protected function store(int $clientId, string $hash, array $response): array
    {
        AiCache::updateOrCreate(
            ['client_id' => $clientId, 'message_hash' => $hash],
            ['response' => json_encode($response)]
        );

        return $response;
    }

    protected function log(string $title, array $data, string $requestId): void
    {
        if ($this->debug) {
            Log::channel('chatbot')->info("AIEngine {$title}", array_merge(
                ['request_id' => $requestId],
                $data
            ));
        }
    }
}