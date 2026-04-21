<?php

namespace Tests\Feature;

use App\Models\RagDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_503_when_openai_key_is_missing(): void
    {
        config(['services.openai.key' => '']);

        RagDocument::create([
            'source_type' => 'match_summary',
            'documentable_type' => 'test',
            'documentable_id' => (string) str()->uuid(),
            'content' => 'Stormers beat the Sharks 24-12.',
            'metadata' => ['team' => 'Stormers', 'date' => '2026-04-01'],
            'generated_at' => now(),
        ]);

        $response = $this->postJson('/api/chat', [
            'question' => 'How did the Stormers do?',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('error', 'ai_not_configured');
    }

    public function test_it_returns_502_when_openai_upstream_fails(): void
    {
        config(['services.openai.key' => 'test-key']);

        RagDocument::create([
            'source_type' => 'match_summary',
            'documentable_type' => 'test',
            'documentable_id' => (string) str()->uuid(),
            'content' => 'Stormers beat the Sharks 24-12.',
            'metadata' => ['team' => 'Stormers', 'date' => '2026-04-01'],
            'generated_at' => now(),
        ]);

        Http::fake([
            'https://api.openai.com/*' => Http::response(['error' => 'failure'], 500),
        ]);

        $response = $this->postJson('/api/chat', [
            'question' => 'How did the Stormers do?',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('error', 'upstream_unavailable');
    }
}
