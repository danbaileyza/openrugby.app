<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Quick debug command to inspect raw API-Sports responses.
 * Useful for understanding the data shape.
 */
class ApiDebugCommand extends Command
{
    protected $signature = 'rugby:api-debug
                            {endpoint : API endpoint, e.g. /leagues, /teams, /games}
                            {--param=* : Query params as key=value}
                            {--raw : Show full raw JSON response}';

    protected $description = 'Inspect a raw API-Sports response';

    public function handle(): int
    {
        $endpoint = $this->argument('endpoint');
        $params = collect($this->option('param'))->mapWithKeys(function ($p) {
            [$key, $value] = explode('=', $p, 2);
            return [$key => $value];
        })->toArray();

        $this->info("GET {$endpoint} " . json_encode($params));

        $response = Http::withHeaders([
            'x-apisports-key' => config('services.api_sports.key'),
        ])->get('https://v1.rugby.api-sports.io' . $endpoint, $params);

        if ($response->failed()) {
            $this->error("Request failed: {$response->status()}");
            return self::FAILURE;
        }

        $data = $response->json();

        // Show rate limit info
        $this->info("Remaining today: " . $response->header('x-ratelimit-requests-remaining', '?'));

        // Show any errors from the API
        if (! empty($data['errors'])) {
            $this->warn('API Errors: ' . json_encode($data['errors']));
        }

        // Show full raw response if requested
        if ($this->option('raw')) {
            $this->newLine();
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $results = $data['response'] ?? [];

        // Handle both indexed arrays and associative arrays
        if (is_array($results) && ! array_is_list($results)) {
            // Associative array (like /status)
            $this->info("Response (object):");
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Results: " . count($results));

            if (count($results) > 0) {
                $this->newLine();
                $this->info('First result:');
                $this->line(json_encode($results[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
