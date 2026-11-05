<?php

namespace Tests\Feature\Api;

use App\Services\IndonesiaRegionService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class RegionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_data_sesuai_jumlah_wilayah_resmi_indonesia(): void
    {
        $provinces = collect(app(IndonesiaRegionService::class)->provinces());
        $cities = $provinces->flatMap(fn (array $province): array => $province['cities']);

        $this->assertCount(38, $provinces);
        $this->assertCount(514, $cities);
        $this->assertCount(416, $cities->filter(fn (array $city): bool => str_starts_with($city['name'], 'Kabupaten')));
        $this->assertCount(98, $cities->filter(fn (array $city): bool => str_starts_with($city['name'], 'Kota')));
        $this->assertCount(514, $cities->pluck('code')->unique());
    }

    public function test_provinsi_hasil_pemekaran_papua_ada(): void
    {
        $names = collect(app(IndonesiaRegionService::class)->provinces())->pluck('name');

        foreach (['Papua Selatan', 'Papua Tengah', 'Papua Pegunungan', 'Papua Barat Daya'] as $province) {
            $this->assertTrue($names->contains($province), $province);
        }
    }

    public function test_setiap_kota_berada_di_provinsi_sesuai_kodenya(): void
    {
        foreach (app(IndonesiaRegionService::class)->provinces() as $province) {
            $this->assertNotEmpty($province['cities'], $province['name']);
            foreach ($province['cities'] as $city) {
                $this->assertStringStartsWith($province['code'].'.', $city['code']);
                $this->assertSame(trim($city['name']), $city['name']);
            }
        }
    }

    public function test_endpoint_terbuka_dan_bisa_di_cache(): void
    {
        $response = $this->getJson('/api/v1/regions/indonesia')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(38, 'data');

        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));

        $westJava = collect($response->json('data'))->firstWhere('code', '32');
        $this->assertSame('Jawa Barat', $westJava['name']);
        $this->assertContains('Kota Bandung', array_column($westJava['cities'], 'name'));
    }

    public function test_pencarian_kota_mengikuti_provinsinya(): void
    {
        $regions = app(IndonesiaRegionService::class);

        $this->assertSame('Kota Bandung', $regions->city('32', '32.73')['name'] ?? null);
        $this->assertNull($regions->city('31', '32.73'));
        $this->assertNull($regions->city('99', '32.73'));
        $this->assertNull($regions->province(null));
    }
}
