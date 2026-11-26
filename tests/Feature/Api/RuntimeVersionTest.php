<?php

namespace Tests\Feature\Api;

use App\Services\RuntimeVersionService;
use Tests\TestCase;

class RuntimeVersionTest extends TestCase
{
    public function test_public_runtime_marker_is_read_only_uncached_and_does_not_accept_a_repository_path(): void
    {
        $version = (new RuntimeVersionService)->current();
        $this->getJson('/api/v1/app/runtime?path=/etc/passwd&sha=attacker')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', $version)
            ->assertHeader('Pragma', 'no-cache');
        $response = $this->getJson('/api/v1/app/runtime');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['sha', 'available'], array_keys($response->json('data')));
    }

    public function test_runtime_route_does_not_allow_mutation(): void
    {
        $this->postJson('/api/v1/app/runtime', ['sha' => str_repeat('a', 40)])->assertStatus(405);
    }
}
