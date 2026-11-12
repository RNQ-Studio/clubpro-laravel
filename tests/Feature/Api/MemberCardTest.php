<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesMonth;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MemberCardTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $member;

    private CommunityMember $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);
        // 8 November 2026 pukul 10.00 WIB.
        Carbon::setTestNow(Carbon::parse('2026-11-08 03:00:00', 'UTC'));

        $creator = User::factory()->create(['name' => 'Creator']);
        $this->community = Community::factory()->approved()->for($creator, 'owner')->create([
            'name' => 'Pemanah Nusantara',
            'short_name' => 'P.N',
        ]);
        $this->member = User::factory()->create(['name' => 'Budi Santoso']);
        $this->membership = CommunityMember::factory()->for($this->community)->for($this->member)
            ->state(['joined_at' => Carbon::parse('2025-06-01 00:00:00', 'UTC')])
            ->create();
        Passport::actingAs($this->member);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tanpa_login_ditolak(): void
    {
        auth()->forgetGuards();

        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_kartu_memuat_identitas_anggota_dan_komunitas(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.member_number', sprintf('PN-%06d', $this->membership->id))
            ->assertJsonPath('data.name', 'Budi Santoso')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.role_label', 'Anggota')
            ->assertJsonPath('data.joined_at', '2025-06-01T00:00:00+00:00')
            ->assertJsonPath('data.community.id', $this->community->id)
            ->assertJsonPath('data.community.name', 'Pemanah Nusantara')
            ->assertJsonPath('data.community.short_name', 'P.N')
            ->assertJsonStructure(['data' => ['avatar_url', 'community' => ['logo_url', 'type_label', 'city_name']]])
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.phone');
    }

    public function test_iuran_belum_diatur_kartu_berlaku_selama_menjadi_anggota(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.validity', [
                'status' => 'no_dues',
                'valid_until' => null,
                'paid_until' => null,
                'pending_payment' => false,
            ]);
    }

    public function test_belum_ada_bulan_lunas_kartu_belum_aktif(): void
    {
        $this->configure();

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.validity.status', 'inactive')
            ->assertJsonPath('data.validity.valid_until', null)
            ->assertJsonPath('data.validity.paid_until', null);
    }

    public function test_lunas_berturut_berlaku_sampai_jatuh_tempo_bulan_berikutnya(): void
    {
        $this->configure();
        $this->paid('2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11');

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.validity.status', 'active')
            ->assertJsonPath('data.validity.paid_until', '2026-11')
            ->assertJsonPath('data.validity.valid_until', '2026-12-10');
    }

    public function test_bulan_lunas_terputus_hanya_dihitung_sampai_celah(): void
    {
        $this->configure();
        $this->paid('2026-03', '2026-04', '2026-06', '2026-07');

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.validity.status', 'expired')
            ->assertJsonPath('data.validity.paid_until', '2026-04')
            ->assertJsonPath('data.validity.valid_until', '2026-05-10');
    }

    public function test_masih_berlaku_pada_hari_jatuh_tempo_dan_kedaluwarsa_esoknya(): void
    {
        $this->configure();
        $this->paid('2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10');

        // 10 November 23.59 WIB: tagihan November belum menunggak.
        Carbon::setTestNow(Carbon::parse('2026-11-10 16:59:00', 'UTC'));
        $this->getJson($this->url())
            ->assertJsonPath('data.validity.status', 'active')
            ->assertJsonPath('data.validity.valid_until', '2026-11-10');

        // 11 November 00.00 WIB.
        Carbon::setTestNow(Carbon::parse('2026-11-10 17:00:00', 'UTC'));
        $this->getJson($this->url())
            ->assertJsonPath('data.validity.status', 'expired');
    }

    public function test_jatuh_tempo_tanggal_31_mengikuti_bulan_pendek(): void
    {
        $this->configure(['dues_start_date' => '2026-01-31']);
        $this->membership->forceFill(['joined_at' => Carbon::parse('2025-12-01', 'UTC')])->save();
        $this->paid('2026-01');

        $this->getJson($this->url())
            ->assertJsonPath('data.validity.paid_until', '2026-01')
            ->assertJsonPath('data.validity.valid_until', '2026-02-28');
    }

    public function test_tagihan_dimulai_dari_bulan_bergabung(): void
    {
        $this->configure();
        $this->membership->forceFill(['joined_at' => Carbon::parse('2026-09-15', 'UTC')])->save();
        // Maret–Agustus bukan tagihan anggota ini; September jadi bulan pertama.
        $this->paid('2026-09', '2026-10');

        $this->getJson($this->url())
            ->assertJsonPath('data.validity.status', 'active')
            ->assertJsonPath('data.validity.paid_until', '2026-10')
            ->assertJsonPath('data.validity.valid_until', '2026-11-10');
    }

    public function test_pembayaran_menunggu_verifikasi_tidak_dihitung(): void
    {
        $this->configure();
        $this->postJson($this->url('dues/payments'), [
            'months_count' => 2,
            'proof_asset_id' => Asset::factory()->create([
                'user_id' => $this->member->getKey(),
                'mime_type' => 'image/jpeg',
                'size' => 200_000,
            ])->id,
        ])->assertCreated();

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.validity.status', 'inactive')
            ->assertJsonPath('data.validity.pending_payment', true);
    }

    public function test_hanya_anggota_aktif(): void
    {
        $this->membership->forceFill(['status' => 'pending'])->save();
        $this->getJson($this->url())
            ->assertForbidden()
            ->assertJsonPath('message', 'Kartu anggota hanya untuk anggota aktif komunitas ini.');

        Passport::actingAs(User::factory()->create());
        $this->getJson($this->url())->assertForbidden();

        // Super admin pun tidak punya kartu di komunitas yang tidak diikutinya.
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        Passport::actingAs($admin);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_komunitas_belum_disetujui_tidak_ditemukan(): void
    {
        $other = Community::factory()->create();

        $this->getJson("/api/v1/communities/{$other->id}/card")->assertNotFound();
    }

    /** @param  array<string, mixed>  $overrides */
    private function configure(array $overrides = []): void
    {
        $this->community->forceFill([
            'dues_amount' => 50000,
            'entry_fee' => 0,
            'dues_start_date' => '2026-03-10',
            'dues_bank_name' => 'BCA',
            'dues_account_number' => '1234567890',
            'dues_account_name' => 'Komunitas Pemanah',
            ...$overrides,
        ])->save();
    }

    private function paid(string ...$months): void
    {
        foreach ($months as $month) {
            $this->community->hasMany(DuesMonth::class)->create([
                'user_id' => $this->member->getKey(),
                'month' => "{$month}-01",
                'status' => 'paid',
            ]);
        }
    }

    private function url(string $path = 'card'): string
    {
        return "/api/v1/communities/{$this->community->id}/{$path}";
    }
}
