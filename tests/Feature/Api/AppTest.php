<?php

namespace Tests\Feature\Api;

use App\Models\AppConfig;
use App\Models\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AppTest extends TestCase
{
    use RefreshDatabase;

    // ── App Version ──────────────────────────────────────────────────────────

    public function test_version_endpoint_returns_android_info(): void
    {
        AppVersion::create([
            'platform' => 'android',
            'min_version' => '2.0.0',
            'latest_version' => '2.5.0',
            'force_update' => true,
            'store_url' => 'https://play.google.com/store/apps/details?id=com.example',
        ]);

        $this->getJson('/api/v1/app/version?platform=android')
            ->assertOk()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.min_version', '2.0.0')
            ->assertJsonPath('data.latest_version', '2.5.0')
            ->assertJsonPath('data.force_update', true);
    }

    public function test_version_endpoint_returns_ios_info(): void
    {
        AppVersion::create([
            'platform' => 'ios',
            'min_version' => '1.5.0',
            'latest_version' => '2.0.0',
            'force_update' => false,
        ]);

        $this->getJson('/api/v1/app/version?platform=ios')
            ->assertOk()
            ->assertJsonPath('data.platform', 'ios')
            ->assertJsonPath('data.force_update', false);
    }

    public function test_version_endpoint_defaults_to_android(): void
    {
        AppVersion::create([
            'platform' => 'android',
            'min_version' => '1.0.0',
            'latest_version' => '1.0.0',
            'force_update' => false,
        ]);

        $this->getJson('/api/v1/app/version')
            ->assertOk()
            ->assertJsonPath('data.platform', 'android');
    }

    public function test_version_endpoint_returns_404_for_unknown_platform(): void
    {
        $this->getJson('/api/v1/app/version?platform=windows')
            ->assertNotFound();
    }

    public function test_version_endpoint_is_unauthenticated(): void
    {
        AppVersion::create([
            'platform' => 'android',
            'min_version' => '1.0.0',
            'latest_version' => '1.0.0',
            'force_update' => false,
        ]);

        $this->getJson('/api/v1/app/version?platform=android')
            ->assertOk();
    }

    // ── App Config ───────────────────────────────────────────────────────────

    public function test_config_endpoint_returns_all_configs(): void
    {
        AppConfig::create(['key' => 'tos_url', 'value' => 'https://example.com/tos', 'type' => 'string']);
        AppConfig::create(['key' => 'maintenance_mode', 'value' => 'false', 'type' => 'boolean']);

        $response = $this->getJson('/api/v1/app/config')->assertOk();

        $this->assertSame('https://example.com/tos', $response->json('data.tos_url'));
        $this->assertFalse($response->json('data.maintenance_mode'));
    }

    public function test_app_config_casts_boolean_correctly(): void
    {
        AppConfig::create(['key' => 'bool_true', 'value' => 'true', 'type' => 'boolean']);
        AppConfig::create(['key' => 'bool_false', 'value' => 'false', 'type' => 'boolean']);
        AppConfig::create(['key' => 'bool_one', 'value' => '1', 'type' => 'boolean']);

        $this->assertTrue(AppConfig::get('bool_true'));
        $this->assertFalse(AppConfig::get('bool_false'));
        $this->assertTrue(AppConfig::get('bool_one'));
    }

    public function test_app_config_casts_integer_correctly(): void
    {
        AppConfig::create(['key' => 'max_retry', 'value' => '5', 'type' => 'integer']);

        $this->assertSame(5, AppConfig::get('max_retry'));
    }

    public function test_app_config_casts_json_correctly(): void
    {
        AppConfig::create(['key' => 'feature_flags', 'value' => '{"chat":true,"video":false}', 'type' => 'json']);

        $flags = AppConfig::get('feature_flags');
        $this->assertTrue($flags['chat']);
        $this->assertFalse($flags['video']);
    }

    public function test_app_config_returns_default_for_missing_key(): void
    {
        $this->assertSame('fallback', AppConfig::get('nonexistent', 'fallback'));
        $this->assertNull(AppConfig::get('nonexistent'));
    }

    // ── Maintenance Mode ─────────────────────────────────────────────────────

    public function test_maintenance_mode_blocks_api_requests(): void
    {
        AppConfig::create(['key' => 'maintenance_mode', 'value' => 'true', 'type' => 'boolean']);
        AppConfig::create(['key' => 'maintenance_message', 'value' => 'Down for maintenance.', 'type' => 'string']);

        $this->postJson('/api/v1/auth/login', ['email' => 'x@x.com', 'password' => 'pw'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Down for maintenance.');
    }

    public function test_maintenance_mode_does_not_block_app_config_endpoint(): void
    {
        AppConfig::create(['key' => 'maintenance_mode', 'value' => 'true', 'type' => 'boolean']);

        $this->getJson('/api/v1/app/config')->assertOk();
    }

    public function test_maintenance_mode_does_not_block_version_endpoint(): void
    {
        AppConfig::create(['key' => 'maintenance_mode', 'value' => 'true', 'type' => 'boolean']);
        AppVersion::create(['platform' => 'android', 'min_version' => '1.0.0', 'latest_version' => '1.0.0', 'force_update' => false]);

        $this->getJson('/api/v1/app/version?platform=android')->assertOk();
    }

    /**
     * Store `array` (bawaan test) tidak men-serialize, jadi pakai `file` seperti
     * database/redis di server: nilai yang dibaca ulang dari cache harus tetap utuh.
     */
    public function test_cached_config_survives_a_serializing_cache_store(): void
    {
        config(['cache.default' => 'file']);
        Cache::flush();

        try {
            AppConfig::create(['key' => 'maintenance_mode', 'value' => 'true', 'type' => 'boolean']);
            AppConfig::create(['key' => 'maintenance_message', 'value' => 'Down.', 'type' => 'string']);

            $this->assertTrue(AppConfig::get('maintenance_mode'));
            // Pembacaan kedua datang dari cache file (hasil unserialize).
            $this->assertTrue(AppConfig::get('maintenance_mode'));
            $this->assertSame('fallback', AppConfig::get('missing', 'fallback'));
            $this->assertSame('fallback', AppConfig::get('missing', 'fallback'));

            // Middleware check.maintenance membaca AppConfig dari cache di tiap request.
            $this->postJson('/api/v1/auth/login', ['email' => 'x@x.com', 'password' => 'pw'])->assertStatus(503);
            $this->postJson('/api/v1/auth/login', ['email' => 'x@x.com', 'password' => 'pw'])
                ->assertStatus(503)
                ->assertJsonPath('message', 'Down.');
        } finally {
            Cache::flush();
        }
    }
}
