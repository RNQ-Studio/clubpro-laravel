<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoIpUpdateTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = storage_path('framework/testing/geoip/test.mmdb');
        config(['services.geoip.database' => $this->target]);
        File::deleteDirectory(dirname($this->target));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->target));

        parent::tearDown();
    }

    public function test_file_bulan_ini_tidak_diunduh_ulang(): void
    {
        Http::fake();
        File::ensureDirectoryExists(dirname($this->target));
        File::put($this->target, 'ada');

        $this->artisan('geoip:update')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_rilis_tidak_ada_gagal_dan_file_lama_tetap(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response('', 404)]);
        File::ensureDirectoryExists(dirname($this->target));
        File::put($this->target, 'lama');

        $this->artisan('geoip:update --force')->assertFailed();

        Http::assertSentCount(2); // Bulan ini, lalu bulan sebelumnya.
        $this->assertSame('lama', File::get($this->target));
    }

    public function test_file_rusak_tidak_menimpa_yang_lama(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response((string) gzencode('bukan database'))]);
        File::ensureDirectoryExists(dirname($this->target));
        File::put($this->target, 'lama');

        $this->artisan('geoip:update --force')->assertFailed();

        $this->assertSame('lama', File::get($this->target));
        $this->assertSame(['test.mmdb'], array_map(fn ($file) => $file->getFilename(), File::files(dirname($this->target))));
    }
}
