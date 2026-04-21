<?php

namespace App\Console\Commands;

use App\Models\MatchOfficial;
use App\Models\Referee;
use App\Models\RugbyMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SetMatchOfficialsCommand extends Command
{
    protected $signature = 'rugby:set-match-officials
                            {match : Match UUID}
                            {--official=* : Official as "Full Name|role"}
                            {--clear : Remove existing officials first}
                            {--dry-run : Preview without writing}';

    protected $description = 'Manually upsert match officials for a specific match';

    private array $roleMap = [
        'ref' => 'referee',
        'referee' => 'referee',
        'ar1' => 'assistant_referee_1',
        'assistant_1' => 'assistant_referee_1',
        'assistant_referee_1' => 'assistant_referee_1',
        'ar2' => 'assistant_referee_2',
        'assistant_2' => 'assistant_referee_2',
        'assistant_referee_2' => 'assistant_referee_2',
        'tmo' => 'tmo',
        'reserve' => 'reserve_referee',
        'fourth' => 'reserve_referee',
        'reserve_referee' => 'reserve_referee',
    ];

    public function handle(): int
    {
        $matchId = (string) $this->argument('match');
        $entries = (array) $this->option('official');
        $clear = (bool) $this->option('clear');
        $dryRun = (bool) $this->option('dry-run');

        if (empty($entries)) {
            $this->error('Provide at least one --official="Full Name|role" option.');
            return 1;
        }

        $match = RugbyMatch::find($matchId);
        if (! $match) {
            $this->error("Match not found: {$matchId}");
            return 1;
        }

        if ($clear) {
            $count = $match->officials()->count();
            if (! $dryRun) {
                $match->officials()->delete();
            }
            $this->line("Cleared {$count} existing official(s)." . ($dryRun ? ' [dry-run]' : ''));
        }

        $created = 0;
        $existing = 0;

        foreach ($entries as $entry) {
            [$name, $role] = $this->parseEntry((string) $entry);

            if ($name === '') {
                $this->warn("Skipped invalid entry: {$entry}");
                continue;
            }

            $normalizedRole = $this->normalizeRole($role);
            if (! $normalizedRole) {
                $this->warn("Skipped entry with invalid role: {$entry}");
                continue;
            }

            [$firstName, $lastName] = $this->splitName($name);

            $referee = Referee::firstOrCreate(
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ],
                [
                    'external_source' => 'manual',
                ]
            );

            $exists = MatchOfficial::where('match_id', $match->id)
                ->where('referee_id', $referee->id)
                ->where('role', $normalizedRole)
                ->exists();

            if ($exists) {
                $existing++;
                continue;
            }

            if (! $dryRun) {
                MatchOfficial::create([
                    'match_id' => $match->id,
                    'referee_id' => $referee->id,
                    'role' => $normalizedRole,
                ]);
            }

            $created++;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Created', $created],
                ['Already existed', $existing],
                ['Total officials on match', $match->officials()->count()],
            ]
        );

        if ($dryRun) {
            $this->info('Dry run completed; no rows written.');
        }

        return 0;
    }

    private function parseEntry(string $entry): array
    {
        $parts = array_map('trim', explode('|', $entry, 2));
        $name = $parts[0] ?? '';
        $role = $parts[1] ?? 'referee';

        return [$name, $role];
    }

    private function normalizeRole(string $role): ?string
    {
        $key = Str::lower(trim($role));
        $key = str_replace([' ', '-'], '_', $key);

        return $this->roleMap[$key] ?? null;
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) === 0) {
            return ['Unknown', 'Official'];
        }

        if (count($parts) === 1) {
            return [$parts[0], 'Official'];
        }

        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return [$first, $last];
    }
}
