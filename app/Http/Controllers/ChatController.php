<?php

namespace App\Http\Controllers;

use App\Models\RagDocument;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RAG-powered chat endpoint.
 *
 * Flow:
 * 1. User sends a question about rugby
 * 2. We search rag_documents for relevant context
 * 3. We pass the context + question to OpenAI GPT-4o-mini
 * 4. Return the AI-generated answer
 */
class ChatController extends Controller
{
    /**
     * Token budget for the context passed to the LLM. At ~4 chars per token
     * this gives roughly 8k tokens of context — plenty of headroom against
     * gpt-4o-mini's 128k window while keeping costs bounded.
     */
    private const CONTEXT_CHAR_BUDGET = 32000;

    /**
     * How long an answer may be cached for repeat questions.
     */
    private const CACHE_TTL_SECONDS = 21600; // 6 hours

    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'question' => 'required|string|max:1000',
        ]);

        $question = $request->input('question');
        $cacheKey = $this->cacheKey($question);

        // Cache hit — return identical answer without hitting OpenAI
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached + ['cached' => true]);
        }

        // Step 1: Search RAG documents for relevant context
        $context = $this->searchDocuments($question);

        if ($context->isEmpty()) {
            return response()->json([
                'answer' => "I don't have enough data to answer that question yet. Try asking about a specific team, player, or match.",
                'sources' => [],
            ]);
        }

        // Step 2: Apply token budget — keep adding docs until we approach the limit
        $context = $this->applyContextBudget($context);

        $contextText = $context->pluck('content')->implode("\n\n---\n\n");
        $sources = $context->map(fn ($doc) => [
            'type' => $doc->source_type,
            'metadata' => $doc->metadata,
        ]);

        $apiKey = (string) config('services.openai.key');
        if ($apiKey === '') {
            return response()->json([
                'answer' => 'AI is not configured yet. Please set OPENAI_API_KEY and try again.',
                'error' => 'ai_not_configured',
            ], 503);
        }

        // Step 3: Call OpenAI API with resilience controls.
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
            ])
                ->timeout(20)
                ->retry(2, 250, throw: false)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'max_tokens' => 1024,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a knowledgeable rugby statistics assistant. Answer questions using ONLY the provided context. Be specific with stats, dates, and scores. If the context doesn\'t contain enough info, say so.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Context:\n{$contextText}\n\nQuestion: {$question}",
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('OpenAI chat request failed with exception', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'answer' => 'Sorry, the AI service is temporarily unavailable. Please try again shortly.',
                'error' => 'upstream_unavailable',
            ], 502);
        }

        if ($response->failed()) {
            Log::warning('OpenAI chat request failed', [
                'status' => $response->status(),
            ]);

            return response()->json([
                'answer' => 'Sorry, the AI service is temporarily unavailable. Please try again shortly.',
                'error' => 'upstream_unavailable',
            ], 502);
        }

        $answer = $response->json('choices.0.message.content', 'No response generated.');

        $payload = [
            'answer' => $answer,
            'sources' => $sources,
        ];

        // Only cache successful responses — avoids persisting error messages.
        Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);

        return response()->json($payload);
    }

    /**
     * Normalize the question and build a stable cache key.
     */
    private function cacheKey(string $question): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $question)));

        return 'chat:'.md5($normalized);
    }

    /**
     * Retrieve a candidate set of RAG documents for the given keywords.
     *
     * Strategy: start with an AND filter (every keyword must appear), and if
     * that yields nothing, progressively drop the most common (least selective)
     * keyword — usually a generic verb like "play" or "score" — until results
     * appear. Falls back to a pure OR match as a last resort.
     */
    private function retrieveCandidates(Collection $words): Collection
    {
        if ($words->isEmpty()) {
            return collect();
        }

        // Single-keyword queries skip straight to the loose fallback.
        if ($words->count() === 1) {
            return $this->runLooseSearch($words);
        }

        // Rank words by rarity (ascending frequency) so we drop common words first.
        $freqs = [];
        foreach ($words as $word) {
            $freqs[$word] = RagDocument::where(function ($q) use ($word) {
                $q->where('content', 'like', "%{$word}%")
                    ->orWhere('metadata', 'like', "%{$word}%");
            })->count();
        }
        $sortedWords = collect($freqs)->sort()->keys(); // rarest first

        // Iteratively relax: full AND → drop most-common word → drop next → …
        // Accept as soon as we have "enough" candidates (≥5) for scoring to work.
        // Otherwise keep the best (largest) set we've seen and fall back at the end.
        $best = collect();
        while ($sortedWords->count() >= 2) {
            $candidates = $this->runStrictSearch($sortedWords);
            if ($candidates->count() >= 5) {
                return $candidates;
            }
            if ($candidates->count() > $best->count()) {
                $best = $candidates;
            }
            // Drop the most common remaining word
            $sortedWords = $sortedWords->slice(0, -1)->values();
        }

        return $best->isNotEmpty() ? $best : $this->runLooseSearch($words);
    }

    private function runStrictSearch(Collection $words): Collection
    {
        $query = RagDocument::query();
        foreach ($words as $word) {
            $query->where(function ($q) use ($word) {
                $q->where('content', 'like', "%{$word}%")
                    ->orWhere('metadata', 'like', "%{$word}%");
            });
        }

        return $query->limit(500)->get();
    }

    private function runLooseSearch(Collection $words): Collection
    {
        return RagDocument::query()
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('content', 'like', "%{$word}%")
                        ->orWhere('metadata', 'like', "%{$word}%");
                }
            })
            ->limit(500)
            ->get();
    }

    /**
     * Accumulate docs up to the char/token budget, keeping them in their
     * already-ranked order (highest relevance first).
     */
    private function applyContextBudget(Collection $docs): Collection
    {
        $budgetRemaining = self::CONTEXT_CHAR_BUDGET;
        $kept = collect();

        foreach ($docs as $doc) {
            $len = strlen((string) $doc->content);
            if ($len === 0) {
                continue;
            }
            // Always keep at least one doc, even if it exceeds budget
            if ($kept->isEmpty() || $len <= $budgetRemaining) {
                $kept->push($doc);
                $budgetRemaining -= $len;
            }
            if ($budgetRemaining <= 0) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Ranked keyword search across RAG documents.
     *
     * Detects "recency" intent in the question ("last", "recent", "latest")
     * and adjusts ranking to prioritise the most recent matches.
     */
    private function searchDocuments(string $question)
    {
        $stopWords = [
            'the', 'a', 'an', 'is', 'was', 'are', 'were', 'what', 'who', 'how',
            'many', 'much', 'did', 'does', 'do', 'in', 'on', 'at', 'for', 'to',
            'of', 'and', 'or', 'vs', 'against', 'about', 'their', 'they', 'them',
            'has', 'had', 'have', 'been', 'with', 'from', 'this', 'that', 'can',
            'season', 'year', 'tell', 'give', 'show', 'performance', 'well',
            'last', 'recent', 'latest', 'five', 'games', 'matches', 'played',
            // Verb forms our docs express as abbreviations (W/L/D) — dropping them
            // prevents these "meta" words from blocking AND-match on entity names.
            'won', 'lost', 'win', 'lose', 'wins', 'losses', 'draw', 'drew', 'drawn',
            'score', 'scored', 'scoring', 'result', 'results', 'record',
            'play', 'plays', 'playing', 'player', 'players', 'next', 'previous',
            'before', 'after', 'when', 'where', 'which', 'there', 'here',
            'whats', 'between', 'loss', 'wl', 'w/l', 'versus',
            // Conversational fillers — users chat with the bot, these aren't semantic
            'ok', 'okay', 'please', 'pls', 'thanks', 'thx', 'hi', 'hey',
            'yes', 'no', 'yeah', 'nope', 'sure', 'cool', 'nice',
            'also', 'pretty', 'very', 'just', 'like', 'really',
            'me', 'my', 'mine', 'you', 'your', 'yours', 'we', 'our', 'us',
            'his', 'her', 'hers', 'its',
        ];

        // Detect recency intent
        $questionLower = strtolower($question);
        $wantsRecent = preg_match('/\b(last|recent|latest|current|this (?:week|weekend|month))\b/', $questionLower);

        // Detect "last N games" pattern
        $limitN = null;
        if (preg_match('/\blast\s+(\d+|five|ten)\s+(?:games?|matches?|results?)/', $questionLower, $m)) {
            $wordToNum = ['five' => 5, 'ten' => 10];
            $limitN = is_numeric($m[1]) ? (int) $m[1] : ($wordToNum[$m[1]] ?? 5);
        }

        // Replace non-word chars with space (preserves boundaries, so "won/lost" → "won lost").
        $normalized = strtolower(preg_replace('/[^\w\s]/', ' ', $question));
        $words = collect(preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($w) => trim($w))
            ->filter(fn ($w) => strlen($w) > 1 && ! in_array($w, $stopWords))
            ->unique()
            ->values();

        if ($words->isEmpty()) {
            return collect();
        }

        $candidates = $this->retrieveCandidates($words);

        // IDF weights — rare keywords score more than common ones.
        $totalDocs = max(1, RagDocument::count());
        $weights = [];
        foreach ($words as $word) {
            $freq = max(1, RagDocument::where(function ($q) use ($word) {
                $q->where('content', 'like', "%{$word}%")
                    ->orWhere('metadata', 'like', "%{$word}%");
            })->count());
            $weights[$word] = max(0.5, log($totalDocs / $freq));
        }

        $scored = $candidates->map(function ($doc) use ($words, $wantsRecent, $weights) {
            $score = 0.0;
            $contentLower = strtolower($doc->content);
            $metadataStr = strtolower(json_encode($doc->metadata ?? []));

            foreach ($words as $word) {
                $w = $weights[$word];
                if (str_contains($metadataStr, $word)) {
                    $score += 3 * $w;
                }
                if (str_contains($contentLower, $word)) {
                    $score += 1 * $w;
                }
            }

            if (str_starts_with($doc->source_type, 'team_season_review') && $score > 0) {
                $score += 2;
            }

            // Recency boost for match summaries when the user asked for recent data
            if ($wantsRecent && $doc->source_type === 'match_summary') {
                $matchDate = $doc->metadata['date'] ?? null;
                if ($matchDate) {
                    $daysAgo = now()->diffInDays(Carbon::parse($matchDate), false);
                    // Heavy recency weight: future matches get nothing, recent past gets big boost
                    if ($daysAgo > -365 && $daysAgo <= 0) {
                        // 0-365 days ago: linear boost from +20 (today) down to 0 (a year ago)
                        $score += max(0, 20 + ($daysAgo / 18));
                    }
                }
            }

            $doc->_relevance = $score;

            return $doc;
        });

        $filtered = $scored->filter(fn ($doc) => $doc->_relevance > 0);

        // For "last N" queries, only include match summaries sorted by date
        if ($limitN) {
            $matchDocs = $filtered
                ->where('source_type', 'match_summary')
                ->sortByDesc(fn ($doc) => $doc->metadata['date'] ?? '0000-00-00')
                ->take($limitN)
                ->values();

            if ($matchDocs->isNotEmpty()) {
                return $matchDocs;
            }
        }

        return $filtered
            ->sortByDesc('_relevance')
            ->take(5)
            ->values();
    }
}
