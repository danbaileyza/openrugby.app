<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\Player;
use App\Models\RagDocument;
use App\Models\RugbyMatch;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Component;

class Chat extends Component
{
    public string $question = '';

    public array $messages = [];

    public bool $loading = false;

    public function ask()
    {
        if (blank($this->question)) {
            return;
        }

        $userMessage = $this->question;
        // Capture prior user message BEFORE we append the current one
        $previousUserMessage = collect($this->messages)
            ->where('role', 'user')
            ->last()['content'] ?? null;

        $this->messages[] = ['role' => 'user', 'content' => $userMessage];
        $this->question = '';
        $this->loading = true;

        // Step 1: Try to answer directly from DB (meta-questions, counts, lists)
        $directAnswer = $this->tryDirectAnswer($userMessage);

        if ($directAnswer) {
            $this->messages[] = ['role' => 'assistant', 'content' => $directAnswer];
            $this->loading = false;

            return;
        }

        // Step 2: Search RAG documents for contextual questions.
        // Merge in the previous user question so follow-ups like "who do they
        // play next?" still retrieve the right entity's docs.
        $retrievalQuery = $previousUserMessage
            ? $previousUserMessage.' '.$userMessage
            : $userMessage;
        $context = $this->searchDocuments($retrievalQuery);

        if ($context->isEmpty()) {
            $this->messages[] = [
                'role' => 'assistant',
                'content' => "I don't have enough data to answer that yet. Try asking about a specific team, player, or match — for example:\n\n• \"How did the Stormers do in 2024?\"\n• \"Tell me about Eben Etzebeth\"\n• \"What were the URC results in round 10?\"",
            ];
            $this->loading = false;

            return;
        }

        $contextText = $context->pluck('content')->implode("\n\n---\n\n");

        // Build the OpenAI messages array with short-term chat history so
        // the LLM can resolve pronouns ("they", "their", "it") against the
        // preceding turns. Keep the last 4 messages (2 Q/A turns) max.
        $history = collect($this->messages)
            ->slice(-5, 4) // exclude the freshly-appended current user message
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values()
            ->toArray();

        $openaiMessages = array_merge(
            [
                [
                    'role' => 'system',
                    'content' => 'You are a knowledgeable rugby statistics assistant for Open Rugby. '
                        .'Answer using ONLY the provided Context block. Be specific with stats, dates, and scores. '
                        .'If the user asks a follow-up with pronouns ("they", "them"), resolve them from the preceding turns in this chat. '
                        .'If the Context doesn\'t contain enough info, say so honestly. Keep answers concise.',
                ],
            ],
            $history,
            [
                ['role' => 'user', 'content' => "Context:\n{$contextText}\n\nQuestion: {$userMessage}"],
            ]
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.openai.key'),
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'max_tokens' => 1024,
                'messages' => $openaiMessages,
            ]);

            $answer = $response->json('choices.0.message.content', 'Sorry, I couldn\'t generate a response.');
        } catch (\Throwable $e) {
            $answer = 'Sorry, there was an error connecting to the AI. Please check your OpenAI API key.';
        }

        $this->messages[] = ['role' => 'assistant', 'content' => $answer];
        $this->loading = false;
    }

    /**
     * Try to answer simple questions directly from the DB.
     *
     * Handles: counts, lookups, "show me X", "list X", "how many X",
     * "who does X play next" / "X next match"
     */
    private function tryDirectAnswer(string $question): ?string
    {
        $q = strtolower($question);

        // --- Next match / upcoming fixture for a team ---
        // Catches: "who does X play next", "X next match", "when do X play next",
        // "next game for X", "what's next for X".
        if (preg_match('/\b(next\s+(match|game|fixture|opponent)|play(s|ing)?\s+next|when\s+(do|does|are|is).+play|what.s\s+next)\b/i', $q)) {
            $team = $this->resolveTeamFromQuestion($question);
            if ($team) {
                $next = RugbyMatch::whereHas('matchTeams', fn ($q) => $q->where('team_id', $team->id))
                    ->where('kickoff', '>=', now())
                    ->where('status', '!=', 'cancelled')
                    ->orderBy('kickoff')
                    ->limit(3)
                    ->with(['matchTeams.team', 'season.competition', 'venue'])
                    ->get();

                if ($next->isEmpty()) {
                    return "I don't have any upcoming fixtures for **{$team->name}** in the database. They may be between seasons, or we haven't yet imported their next round.";
                }

                $lines = ["**{$team->name}** — next ".Str::plural('fixture', $next->count()).":\n"];
                foreach ($next as $m) {
                    $home = $m->matchTeams->firstWhere('side', 'home');
                    $away = $m->matchTeams->firstWhere('side', 'away');
                    $isHome = $home?->team_id === $team->id;
                    $opp = $isHome ? ($away?->team->name ?? 'TBD') : ($home?->team->name ?? 'TBD');
                    $sideTag = $isHome ? '(home)' : '(away)';
                    $when = $m->kickoff->format('D j M Y · H:i');
                    $comp = $m->season->competition->name;
                    $venue = $m->venue?->name ? " · {$m->venue->name}" : '';
                    $lines[] = "• **{$when}** — vs {$opp} {$sideTag} · {$comp}{$venue}";
                }

                return implode("\n", $lines);
            }
        }

        // --- Counts / How many ---
        if (preg_match('/how many|count|total|number of/', $q)) {
            if (str_contains($q, 'player')) {
                $total = Player::where('is_active', true)->count();

                // Check for nationality filter
                if (preg_match('/south african|springbok/i', $q)) {
                    $count = Player::where('is_active', true)->where('nationality', 'like', '%south afric%')->count();

                    return "We track **{$count}** South African players out of **{$total}** total active players.";
                }
                if (preg_match('/new zealand|all black/i', $q)) {
                    $count = Player::where('is_active', true)->where('nationality', 'like', '%new zealand%')->count();

                    return "We track **{$count}** New Zealand players out of **{$total}** total active players.";
                }
                if (preg_match('/(?:from|in)\s+(\w[\w\s]*)/i', $question, $m)) {
                    $country = trim($m[1], '? ');
                    $count = Player::where('is_active', true)->where('nationality', 'like', "%{$country}%")->count();
                    if ($count > 0) {
                        return "We track **{$count}** players from {$country} out of **{$total}** total active players.";
                    }
                }

                return "We currently track **{$total}** active players across all competitions.";
            }

            // Only answer generic database-wide counts — never hijack questions
            // that mention a specific team/school/player (detected via mid-sentence
            // capitals in the original question, e.g. "Grey High School").
            $hasProperNoun = (bool) preg_match('/\s[A-Z]/', $question);
            if (! $hasProperNoun) {
                if (str_contains($q, 'team')) {
                    return 'We track **'.Team::count().'** teams across all competitions.';
                }
                if (str_contains($q, 'match') || str_contains($q, 'game')) {
                    $total = RugbyMatch::count();
                    $completed = RugbyMatch::where('status', 'ft')->count();

                    return "We have **{$total}** matches in the database, of which **{$completed}** are completed (full time).";
                }
                if (str_contains($q, 'competition') || str_contains($q, 'league') || str_contains($q, 'tournament')) {
                    return 'We track **'.Competition::count().'** competitions across union, league, and sevens.';
                }
            }
        }

        // --- Show/List players ---
        if (preg_match('/show me|list|give me/', $q) && str_contains($q, 'player')) {
            $limit = 10;
            if (preg_match('/(\d+)\s*player/', $q, $m)) {
                $limit = min((int) $m[1], 25);
            }

            $query = Player::where('is_active', true);

            // Filter by nationality if mentioned
            if (preg_match('/south african|springbok/i', $q)) {
                $query->where('nationality', 'like', '%south afric%');
            } elseif (preg_match('/new zealand|all black/i', $q)) {
                $query->where('nationality', 'like', '%new zealand%');
            }

            // Filter by position if mentioned
            if (str_contains($q, 'prop') || str_contains($q, 'hooker') || str_contains($q, 'front row')) {
                $query->where('position_group', 'front_row');
            } elseif (str_contains($q, 'lock') || str_contains($q, 'second row')) {
                $query->where('position_group', 'second_row');
            } elseif (str_contains($q, 'flanker') || str_contains($q, 'number 8') || str_contains($q, 'back row')) {
                $query->where('position_group', 'back_row');
            } elseif (str_contains($q, 'scrumhalf') || str_contains($q, 'flyhalf') || str_contains($q, 'halfback')) {
                $query->where('position_group', 'halfback');
            } elseif (str_contains($q, 'centre') || str_contains($q, 'center')) {
                $query->where('position_group', 'centre');
            } elseif (str_contains($q, 'wing') || str_contains($q, 'fullback') || str_contains($q, 'back three')) {
                $query->where('position_group', 'back_three');
            }

            $players = $query->orderBy('last_name')->limit($limit)->get();

            if ($players->isEmpty()) {
                return null; // Fall through to RAG
            }

            $lines = ["Here are {$players->count()} players:\n"];
            foreach ($players as $p) {
                $pos = str($p->position)->replace('_', ' ')->title();
                $nat = $p->nationality ?? 'Unknown';
                $lines[] = "• **{$p->first_name} {$p->last_name}** — {$pos} ({$nat})";
            }

            $total = $query->count();
            if ($total > $limit) {
                $lines[] = "\n*...and ".($total - $limit).' more. Browse all on the [Players page](/players).*';
            }

            return implode("\n", $lines);
        }

        // --- Show/List teams ---
        if (preg_match('/show me|list|give me/', $q) && str_contains($q, 'team')) {
            $limit = 10;
            if (preg_match('/(\d+)\s*team/', $q, $m)) {
                $limit = min((int) $m[1], 25);
            }

            $query = Team::query();
            if (preg_match('/south afric/i', $q)) {
                $query->where('country', 'like', '%South Africa%');
            }

            $teams = $query->orderBy('name')->limit($limit)->get();
            if ($teams->isEmpty()) {
                return null;
            }

            $lines = ["Here are {$teams->count()} teams:\n"];
            foreach ($teams as $t) {
                $lines[] = "• **{$t->name}** — {$t->country} ({$t->type})";
            }

            return implode("\n", $lines);
        }

        // --- Database overview ---
        if (preg_match('/what data|what do you (know|have|track)|overview|database|stats.*hub/i', $q)) {
            $stats = [
                'competitions' => Competition::count(),
                'teams' => Team::count(),
                'players' => Player::where('is_active', true)->count(),
                'matches' => RugbyMatch::count(),
                'completed' => RugbyMatch::where('status', 'ft')->count(),
                'rag_docs' => RagDocument::count(),
            ];

            return "Here's what's in the RugbyStats database:\n\n"
                ."• **{$stats['competitions']}** competitions\n"
                ."• **{$stats['teams']}** teams\n"
                ."• **{$stats['players']}** active players\n"
                ."• **{$stats['matches']}** matches ({$stats['completed']} completed)\n"
                ."• **{$stats['rag_docs']}** AI knowledge documents\n\n"
                .'Data sources: API-Sports (2022–2024 seasons), rugbypy (current players), and Kaggle (historical).';
        }

        return null; // Not a direct-answer question
    }

    /**
     * Pull a team name out of free text. Tries multi-word capitalised
     * sequences first (e.g. "Grey High School"), then falls back to a
     * lowercase substring scan over known team names.
     */
    private function resolveTeamFromQuestion(string $question): ?Team
    {
        // Strip the trigger words so they don't accidentally match team names.
        $stripped = preg_replace(
            '/\b(who|what|when|where|do|does|are|is|will|the|next|match|game|fixture|opponent|play|plays|playing|s)\b/i',
            ' ',
            $question
        );
        $stripped = trim(preg_replace('/[^\w\s]/', ' ', $stripped));

        // Capitalised multi-word sequences from the original question.
        preg_match_all('/\b([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+)*)\b/', $question, $caps);
        $candidates = collect($caps[1] ?? [])
            ->filter(fn ($s) => strlen($s) >= 3)
            ->sortByDesc(fn ($s) => strlen($s))
            ->values();

        foreach ($candidates as $candidate) {
            $team = Team::where('name', 'like', "%{$candidate}%")
                ->orWhere('short_name', 'like', "%{$candidate}%")
                ->orderByRaw('LENGTH(name) ASC')
                ->first();
            if ($team) {
                return $team;
            }
        }

        // Lowercase fallback — try the longest remaining word phrase.
        $words = collect(preg_split('/\s+/', strtolower($stripped), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => strlen($w) >= 3)
            ->values();

        for ($len = $words->count(); $len >= 1; $len--) {
            for ($start = 0; $start + $len <= $words->count(); $start++) {
                $phrase = $words->slice($start, $len)->implode(' ');
                $team = Team::whereRaw('LOWER(name) LIKE ?', ["%{$phrase}%"])
                    ->orderByRaw('LENGTH(name) ASC')
                    ->first();
                if ($team) {
                    return $team;
                }
            }
        }

        return null;
    }

    /**
     * Ranked keyword search across RAG documents.
     * Mirrors the improved logic in ChatController: iterative AND relaxation,
     * extended stopwords, and punctuation-as-space normalization.
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
            'won', 'lost', 'win', 'lose', 'wins', 'losses', 'draw', 'drew', 'drawn',
            'score', 'scored', 'scoring', 'result', 'results', 'record',
            'play', 'plays', 'playing', 'player', 'players', 'next', 'previous',
            'before', 'after', 'when', 'where', 'which', 'there', 'here',
            // Extra fillers that slipped through
            'whats', 'between', 'loss', 'wl', 'w/l', 'versus',
            'ok', 'okay', 'please', 'pls', 'thanks', 'thx', 'hi', 'hey',
            'yes', 'no', 'yeah', 'nope', 'sure', 'cool', 'nice',
            'also', 'pretty', 'very', 'just', 'like', 'really',
            'me', 'my', 'mine', 'you', 'your', 'yours', 'we', 'our', 'us',
            'his', 'her', 'hers', 'its',
        ];

        // Replace non-word chars with space (preserves boundaries — "won/lost" → "won lost").
        $normalized = strtolower(preg_replace('/[^\w\s]/', ' ', $question));
        $words = collect(preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($w) => trim($w))
            ->filter(fn ($w) => strlen($w) > 1 && ! in_array($w, $stopWords))
            ->unique()
            ->values();

        if ($words->isEmpty()) {
            return collect();
        }

        // Typo correction: any keyword with no doc hits is matched against a
        // vocabulary of known team/player/competition tokens (Levenshtein ≤2).
        $words = $this->correctTypos($words);

        $candidates = $this->retrieveCandidates($words);

        // Precompute IDF-style weight per keyword: rare words score more than
        // common ones. Prevents "boys" from dominating "wynberg".
        $totalDocs = max(1, RagDocument::count());
        $weights = [];
        foreach ($words as $word) {
            $freq = max(1, RagDocument::where(function ($q) use ($word) {
                $q->where('content', 'like', "%{$word}%")
                    ->orWhere('metadata', 'like', "%{$word}%");
            })->count());
            // log(N/df) — range roughly 0.3 (very common) to 5+ (very rare)
            $weights[$word] = max(0.5, log($totalDocs / $freq));
        }

        $scored = $candidates->map(function ($doc) use ($words, $weights) {
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

            $doc->_relevance = round($score, 2);

            return $doc;
        });

        return $scored
            ->filter(fn ($doc) => $doc->_relevance > 0)
            ->sortByDesc('_relevance')
            ->take(5)
            ->values();
    }

    private function retrieveCandidates(Collection $words): Collection
    {
        if ($words->count() === 1) {
            return $this->runLooseSearch($words);
        }

        // Rank words by corpus rarity (ascending — rarest first).
        $freqs = [];
        foreach ($words as $word) {
            $freqs[$word] = RagDocument::where(function ($q) use ($word) {
                $q->where('content', 'like', "%{$word}%")
                    ->orWhere('metadata', 'like', "%{$word}%");
            })->count();
        }
        $sortedWords = collect($freqs)->sort()->keys();

        // Try AND of all keywords, relaxing by dropping the most-common one each pass.
        $best = collect();
        while ($sortedWords->count() >= 2) {
            $candidates = $this->runStrictSearch($sortedWords);
            if ($candidates->count() >= 5) {
                return $candidates;
            }
            if ($candidates->count() > $best->count()) {
                $best = $candidates;
            }
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

    /**
     * Replace unrecognised keywords with their closest match from known
     * team / player / competition names (Levenshtein ≤ 2). Fixes user typos
     * like "wynbery" → "wynberg".
     */
    private function correctTypos(Collection $words): Collection
    {
        $vocab = Cache::remember('chat:name_vocab', 600, function () {
            $names = array_merge(
                Team::pluck('name')->all(),
                Competition::pluck('name')->all(),
                Player::select('first_name', 'last_name')->get()
                    ->map(fn ($p) => $p->first_name.' '.$p->last_name)->all(),
            );
            $tokens = [];
            foreach ($names as $n) {
                foreach (preg_split('/\s+/', strtolower((string) $n)) as $t) {
                    $t = preg_replace('/[^\w]/', '', $t);
                    if (strlen($t) >= 4) {
                        $tokens[$t] = true;
                    }
                }
            }

            return array_keys($tokens);
        });

        return $words->map(function ($word) use ($vocab) {
            if (strlen($word) < 4) {
                return $word;
            }
            // Only correct keywords that have no direct corpus matches
            $hasHits = RagDocument::where(function ($q) use ($word) {
                $q->where('content', 'like', "%{$word}%")
                    ->orWhere('metadata', 'like', "%{$word}%");
            })->exists();
            if ($hasHits) {
                return $word;
            }

            $best = null;
            $bestDist = 3; // within 2 edits
            foreach ($vocab as $v) {
                if (abs(strlen($v) - strlen($word)) > 2) {
                    continue;
                }
                $d = levenshtein($word, $v);
                if ($d < $bestDist) {
                    $best = $v;
                    $bestDist = $d;
                    if ($d === 0) {
                        break;
                    }
                }
            }

            return $best ?? $word;
        })->unique()->values();
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

    public function render()
    {
        return view('livewire.chat')
            ->layout('layouts.app', ['title' => 'Rugby Chat Bot']);
    }
}
