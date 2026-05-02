<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Auto-link squad teams to their parent school.
 *
 * Detects sub-squad naming patterns ("Cherries", "U16A", "2nd XV", "Inv XV")
 * and finds the parent team by stripping the suffix and matching the
 * remaining base name against existing top-level teams.
 *
 * Conservative — only links when there's exactly one base-name match in the
 * DB. Run with --dry-run first.
 */
class LinkSubTeamsCommand extends Command
{
    protected $signature = 'rugby:link-sub-teams
                            {--type= : Limit to one team type (e.g. school)}
                            {--dry-run : Report only}';

    protected $description = 'Auto-link 2nd XV / U16A / Cherries / Invitational teams to their parent school';

    /**
     * Suffix patterns that mark a team as a sub-squad. Each entry is a
     * regex; the first capture group (or the whole match if none) is what
     * we strip to derive the parent name.
     */
    private const SUFFIX_PATTERNS = [
        '/\\s*(?:"|\'|")?cherries(?:"|\'|")?\\s*$/i',
        '/\\s+u1[2-9][a-z]?\\s*$/i',
        '/\\s+(?:2nd|3rd|4th|5th)\\s*xv\\s*$/i',
        '/\\s+inv(?:itational)?\\s*xv\\s*$/i',
        '/\\s+colts?\\s*$/i',
        '/\\s+(?:primary|junior)(?:\\s+\\(.*\\))?\\s*$/i',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $type = $this->option('type');

        $candidates = Team::query()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->whereNull('parent_team_id')
            ->get();

        $linked = 0;
        $ambiguous = 0;
        $noParent = 0;

        foreach ($candidates as $team) {
            $base = $this->derivebase($team->name);
            if ($base === null) {
                continue; // Not a sub-squad name pattern.
            }

            // Find candidate parents — teams whose name equals or starts with
            // the base. Exclude teams whose own name looks like a sub-squad
            // pattern (e.g. don't make "KES 2nd XV" the parent of "KES U16A").
            $candidates = Team::query()
                ->where('id', '!=', $team->id)
                ->where(function ($q) use ($base) {
                    $q->where('name', $base)
                        ->orWhere('name', 'like', $base.' %');
                })
                ->get();

            $parents = $candidates->reject(fn ($p) => $this->derivebase($p->name) !== null);

            // Prefer exact name match if present.
            $exact = $parents->firstWhere(fn ($p) => mb_strtolower($p->name) === mb_strtolower($base));
            $parent = $exact ?: ($parents->count() === 1 ? $parents->first() : null);

            if (! $parent) {
                $parents->isEmpty() ? $noParent++ : $ambiguous++;
                continue;
            }

            $this->line(sprintf('  link  %-40s -> %s', $team->name, $parent->name));
            if (! $dryRun) {
                $team->update(['parent_team_id' => $parent->id]);
            }
            $linked++;
        }

        $this->newLine();
        $this->table(['', 'Count'], [
            ['Linked', $linked],
            ['Ambiguous parent', $ambiguous],
            ['No parent found', $noParent],
        ]);

        if ($dryRun) {
            $this->warn('Dry run — no changes written.');
        }

        return self::SUCCESS;
    }

    /**
     * If the team name looks like a sub-squad ("Grey College Cherries",
     * "SACS U16A"), return the inferred parent name ("Grey College", "SACS").
     * Returns null if the name doesn't match any sub-squad pattern.
     */
    private function derivebase(string $name): ?string
    {
        foreach (self::SUFFIX_PATTERNS as $pattern) {
            $stripped = preg_replace($pattern, '', $name);
            if ($stripped !== null && $stripped !== $name) {
                $base = trim($stripped);

                return $base !== '' ? $base : null;
            }
        }

        return null;
    }
}
