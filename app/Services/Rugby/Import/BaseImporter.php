<?php

namespace App\Services\Rugby\Import;

use App\Models\DataImport;
use Illuminate\Support\Facades\Log;

/**
 * Base class for all rugby data importers.
 * Each source (API-Sports, rugbypy, Kaggle, scrapers) extends this.
 */
abstract class BaseImporter
{
    protected DataImport $import;

    abstract public function source(): string;
    abstract public function entityType(): string;
    abstract protected function fetch(): iterable;
    abstract protected function transform(array $raw): array;
    abstract protected function upsert(array $data): void;

    public function run(): DataImport
    {
        $this->import = DataImport::create([
            'source'      => $this->source(),
            'entity_type' => $this->entityType(),
            'status'      => 'running',
            'started_at'  => now(),
        ]);

        try {
            foreach ($this->fetch() as $raw) {
                try {
                    $data = $this->transform($raw);
                    $this->upsert($data);
                    $this->import->increment('records_processed');
                } catch (\Throwable $e) {
                    $this->import->increment('records_failed');
                    $this->logError($raw, $e);
                }
            }

            $this->import->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->import->update([
                'status'       => 'failed',
                'completed_at' => now(),
                'error_log'    => [['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]],
            ]);
            Log::error("Import failed: {$this->source()}/{$this->entityType()}", ['error' => $e->getMessage()]);
        }

        return $this->import;
    }

    protected function logError(array $raw, \Throwable $e): void
    {
        $errors = $this->import->error_log ?? [];
        $errors[] = [
            'data'    => array_slice($raw, 0, 5), // truncate for storage
            'message' => $e->getMessage(),
        ];
        $this->import->update(['error_log' => $errors]);
    }
}
