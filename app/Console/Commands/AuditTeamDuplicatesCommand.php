<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchTeam;
use App\Models\PlayerContract;
use App\Models\Team;
use Illuminate\Console\Command;

class AuditTeamDuplicatesCommand extends Command
{
    protected $signature = 'rugby:audit-team-duplicates
                            {--type= : Team type filter (school, club, national, etc.)}
                            {--country= : Country filter}
                            {--fix : Apply the known-alias merges}
                            {--merge-near-dupes : Auto-merge near-dupes (keeps longer/more descriptive name)}';

    protected $description = 'Find likely-duplicate teams (same entity, different names)';

    /**
     * Known canonical mappings: "wrong name" => "correct name".
     * Expand this list as new duplicates are discovered.
     */
    protected $aliases = [
        // Schools
        'Grey HS' => 'Grey High School (PE)',
        'Grey High School' => 'Grey High School (PE)',
        'Pretoria BH' => 'Pretoria Boys High',
        'Pretoria BHS' => 'Pretoria Boys High',
        'Paarl BH' => 'Paarl Boys High',
        'Paarl Boys' => 'Paarl Boys High',
        'Paarl Gim' => 'Paarl Gimnasium',
        'Gimmies' => 'Paarl Gimnasium',
        'Durban HS' => 'Durban High School',
        'Maritzburg' => 'Maritzburg College',
        'MC' => 'Maritzburg College',
        'Hilton' => 'Hilton College',
        'Kearsney' => 'Kearsney College',
        'Michaelhouse' => 'Michaelhouse',
        'Glenwood' => 'Glenwood High School',
        'SACS' => 'SACS',
        'Bishops' => 'Bishops',
        'Affies' => 'Afrikaanse Hoër Seunskool (Pta)',
        'Monnas' => 'Hoërskool Monument',
        'Monument' => 'Hoërskool Monument',
        'KES' => 'King Edward VII (KES)',
        'Jeppe' => 'Jeppe High School',
        'Saints' => 'St Stithians College',
        'St Stithians' => 'St Stithians College',
        'Albans' => 'St Albans College',
        'St Johns' => 'St Johns College',
        'Helpies' => 'Helpmekaar Kollege',
        'Helpmekaar' => 'Helpmekaar Kollege',
        'Oakdale' => 'Hoër Landbouskool Oakdale',
        'Outeniqua' => 'Hoërskool Outeniqua',
        'Framesby' => 'Hoërskool Framesby',
        'Selborne' => 'Selborne College',
        'Dale' => 'Dale College',
        'Wynberg' => 'Wynberg Boys High',
        'Westville' => 'Westville Boys High',
        'Northwood' => 'Beachwood Boys High School',
        'Bosch' => 'Rondebosch Boys High',
        'Rondebosch' => 'Rondebosch Boys High',
        'PBHS' => 'Pretoria Boys High',
        'Queens' => 'Queens College',
        'Klofies' => 'Hoërskool Waterkloof',
        'Waterkloof' => 'Hoërskool Waterkloof',
        'Zwarries' => 'Hoërskool Zwartkop',
        'Zwartkop' => 'Hoërskool Zwartkop',
        'Parkies' => 'Hoërskool Menlopark',
        'Menlopark' => 'Hoërskool Menlopark',
        'Eldo' => 'Hoërskool Eldoraigne',
        'Eldoraigne' => 'Hoërskool Eldoraigne',
        'EG Jansen' => 'Hoërskool Dr EG Jansen',
        'Jansies' => 'Hoërskool Dr EG Jansen',
        'Garsies' => 'Garsfontein Laerskool',
        'Garsfontein' => 'Hoërskool Garsfontein',
        'PRG' => 'Paul Roos Gymnasium',
        'Paul Roos' => 'Paul Roos Gymnasium',
        'GC' => 'Grey College',
        'GHS' => 'Grey High School (PE)',
        'DHS' => 'Durban High School',
        'BL' => 'Boland Landbou',
        'Boishaai' => 'Paarl Boys High',
        'House' => 'Michaelhouse',
        'KC' => 'Kearsney College',
        'Nories' => 'Hoërskool Noordheuwel',
        'Noordheuwel' => 'Hoërskool Noordheuwel',
        'SAC' => 'St Andrews College',
    ];

    public function handle(): int
    {
        $query = Team::query();
        if ($type = $this->option('type')) $query->where('type', $type);
        if ($country = $this->option('country')) $query->where('country', $country);

        $teams = $query->orderBy('name')->get();

        // 1. Known-alias merges
        $merges = [];
        foreach ($teams as $team) {
            if (isset($this->aliases[$team->name])) {
                $canonical = $this->aliases[$team->name];
                if ($team->name === $canonical) continue;
                $merges[] = [$team->name, $canonical];
            }
        }

        // 2. Near-duplicate detection by normalized key
        $byKey = [];
        foreach ($teams as $team) {
            $key = $this->normalize($team->name);
            $byKey[$key][] = $team;
        }
        $nearDupes = [];
        foreach ($byKey as $key => $group) {
            if (count($group) > 1) {
                $nearDupes[] = array_map(fn ($t) => $t->name, $group);
            }
        }

        $this->info('Known-alias duplicates: '.count($merges));
        foreach ($merges as [$from, $to]) {
            $this->line("  {$from}  →  {$to}");
        }

        $this->info("\nNear-duplicate groups (same normalized key): ".count($nearDupes));
        foreach ($nearDupes as $g) {
            $this->line('  '.implode('  ||  ', $g));
        }

        if ($this->option('fix')) {
            $this->info("\nApplying known-alias merges...");
            $merged = 0;
            foreach ($merges as [$from, $to]) {
                if ($this->mergeTeam($from, $to, $this->option('type'))) $merged++;
            }
            $this->info("Merged {$merged} teams.");
        }

        if ($this->option('merge-near-dupes')) {
            $this->info("\nMerging near-duplicate groups (longer name wins)...");
            $mergedN = 0;
            foreach ($nearDupes as $group) {
                // Pick the team with the longest name as canonical
                usort($group, fn ($a, $b) => strlen($b) <=> strlen($a));
                $canonical = $group[0];
                for ($i = 1; $i < count($group); $i++) {
                    if ($this->mergeTeam($group[$i], $canonical, $this->option('type'))) $mergedN++;
                }
            }
            $this->info("Merged {$mergedN} near-dupes.");
        }

        if (! $this->option('fix') && ! $this->option('merge-near-dupes')) {
            $this->warn("\nRun with --fix (known aliases) or --merge-near-dupes (auto same-key) to apply.");
        }

        return self::SUCCESS;
    }

    private function normalize(string $name): string
    {
        $n = strtolower($name);
        // Remove common noise
        $n = preg_replace('/\b(the|high school|hs|hoërskool|hoer skool|college|laerskool|primary|prep|gimnasium|gymnasium|boys|girls|combined)\b/u', '', $n);
        $n = preg_replace('/[^a-z0-9]/', '', $n);
        return $n;
    }

    private function mergeTeam(string $fromName, string $toName, ?string $type): bool
    {
        $fromQuery = Team::where('name', $fromName);
        if ($type) $fromQuery->where('type', $type);
        $from = $fromQuery->first();

        $toQuery = Team::where('name', $toName);
        if ($type) $toQuery->where('type', $type);
        $to = $toQuery->first();

        if (! $from || ! $to || $from->id === $to->id) return false;

        MatchTeam::where('team_id', $from->id)->update(['team_id' => $to->id]);
        MatchLineup::where('team_id', $from->id)->update(['team_id' => $to->id]);
        MatchEvent::where('team_id', $from->id)->update(['team_id' => $to->id]);
        PlayerContract::where('team_id', $from->id)->update(['team_id' => $to->id]);
        $from->delete();
        $this->line("  ✓ Merged {$fromName}");
        return true;
    }
}
