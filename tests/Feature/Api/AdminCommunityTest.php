<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\User;
use App\Support\Enums\CommunityStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminCommunityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Pemilik']);
        $this->admin->assignRole('super-admin');
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'ringkasan' => ['GET', '/api/v1/admin/summary'],
            'daftar' => ['GET', '/api/v1/admin/communities'],
            'detail' => ['GET', '/api/v1/admin/communities/{id}'],
            'verifikasi' => ['PUT', '/api/v1/admin/communities/{id}/review'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_tanpa_login_ditolak(string $method, string $path): void
    {
        $community = Community::factory()->create();

        $this->json($method, str_replace('{id}', (string) $community->id, $path))->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_selain_super_admin_ditolak_termasuk_pengajunya(string $method, string $path): void
    {
        $owner = User::factory()->create();
        $community = Community::factory()->for($owner, 'owner')->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $url = str_replace('{id}', (string) $community->id, $path);
        $body = ['status' => 'approved', 'expected_status' => 'pending'];

        foreach ([$owner, $admin, $staff] as $user) {
            Passport::actingAs($user);
            $this->json($method, $url, $body)->assertForbidden();
        }

        $this->assertSame(CommunityStatus::Pending, $community->refresh()->status);
    }

    public function test_daftar_memuat_semua_status_terbaru_di_atas(): void
    {
        // Urutan id sengaja beda dari tanggal: yang dibuktikan adalah tanggal pengajuan.
        $tengah = Community::factory()->rejected()->create(['created_at' => now()->subDays(2)]);
        $baru = Community::factory()->create(['created_at' => now()->subHour()]);
        $lama = Community::factory()->approved()->create(['created_at' => now()->subDays(3)]);
        Passport::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/communities')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.pending', 1)
            ->assertJsonPath('meta.summary.approved', 1)
            ->assertJsonPath('meta.summary.rejected', 1);

        $this->assertSame(
            [$baru->id, $tengah->id, $lama->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_daftar_menyebut_pengaju_dan_statusnya(): void
    {
        $owner = User::factory()->create(['name' => 'Rina Ayu', 'email' => 'rina@gmail.com']);
        Community::factory()->for($owner, 'owner')->create();
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/communities')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.submitter.name', 'Rina Ayu')
            ->assertJsonPath('data.0.submitter.email', 'rina@gmail.com');
    }

    public function test_pengaju_terhapus_tidak_merusak_daftar(): void
    {
        Community::factory()->create(['user_id' => null]);
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/communities')->assertOk()->assertJsonPath('data.0.submitter', null);
    }

    public function test_saring_status_dan_cari_termasuk_pengaju(): void
    {
        $owner = User::factory()->create(['name' => 'Rina Ayu']);
        Community::factory()->for($owner, 'owner')->create(['name' => 'Pemanah Bandung']);
        Community::factory()->approved()->create(['name' => 'Sepak Bola Solo', 'city_name' => 'Kota Surakarta']);
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/communities?status=approved')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sepak Bola Solo');

        foreach (['PEMANAH' => 'Pemanah Bandung', 'surakarta' => 'Sepak Bola Solo', 'rina' => 'Pemanah Bandung'] as $search => $name) {
            $this->getJson('/api/v1/admin/communities?search='.$search)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', $name);
        }

        $this->getJson('/api/v1/admin/communities?search='.urlencode('%'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/communities?status=hapus')->assertUnprocessable();
    }

    public function test_ringkasan_tidak_terpengaruh_saringan(): void
    {
        Community::factory()->approved()->create();
        Community::factory()->create();
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/communities?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.summary.total', 2);
    }

    public function test_jumlah_query_daftar_tidak_bertambah_per_komunitas(): void
    {
        Passport::actingAs($this->admin);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/admin/communities')->assertOk();

            return count(DB::getQueryLog());
        };

        Community::factory()->create();
        $count();
        $sedikit = $count();

        Community::factory()->count(5)->create();

        $this->assertSame($sedikit, $count());
    }

    public function test_detail_memuat_data_lengkap_dan_semua_foto(): void
    {
        $owner = User::factory()->create(['name' => 'Rina Ayu', 'email' => 'rina@gmail.com']);
        $community = Community::factory()->for($owner, 'owner')->create([
            'description' => str_repeat('Deskripsi panjang. ', 30),
            'address' => 'Jl. Merdeka No. 10',
            'contact_name' => 'Budi',
            'contact_phone' => '081234567890',
            'contact_email' => 'pengurus@gmail.com',
        ]);
        foreach ([1, 0] as $order) {
            $community->photos()->attach(Asset::factory()->create()->id, ['sort_order' => $order]);
        }
        Passport::actingAs($this->admin);

        $response = $this->getJson("/api/v1/admin/communities/{$community->id}")
            ->assertOk()
            ->assertJsonPath('data.address', 'Jl. Merdeka No. 10')
            ->assertJsonPath('data.contact_name', 'Budi')
            ->assertJsonPath('data.contact_phone', '081234567890')
            ->assertJsonPath('data.contact_email', 'pengurus@gmail.com')
            ->assertJsonPath('data.submitter.email', 'rina@gmail.com')
            ->assertJsonPath('data.reviewer', null)
            ->assertJsonCount(2, 'data.photo_urls');

        // Deskripsi utuh, bukan ringkasan daftar.
        $this->assertGreaterThan(300, mb_strlen($response->json('data.description')));
        $this->assertNotNull($response->json('data.logo_url'));
    }

    public function test_komunitas_tidak_ada_404(): void
    {
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/communities/999999')->assertNotFound();
        $this->getJson('/api/v1/admin/communities/abc')->assertNotFound();
    }

    public function test_menyetujui_membuat_komunitas_tampil_di_daftar_umum(): void
    {
        $community = Community::factory()->create();
        $member = User::factory()->create();

        Passport::actingAs($member);
        $this->getJson('/api/v1/communities')->assertOk()->assertJsonCount(0, 'data');

        Passport::actingAs($this->admin);
        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'approved',
            'expected_status' => 'pending',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewer.name', 'Pemilik');

        $community->refresh();
        $this->assertSame($this->admin->id, $community->reviewed_by);
        $this->assertNotNull($community->reviewed_at);

        Passport::actingAs($member);
        $this->getJson('/api/v1/communities')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/communities/{$community->id}")->assertOk();
    }

    public function test_menolak_wajib_beralasan(): void
    {
        $community = Community::factory()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'rejected',
            'expected_status' => 'pending',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['note'])
            ->assertJsonPath('errors.note.0', 'Tulis alasan penolakan agar pengaju tahu yang perlu diperbaiki.');

        $this->assertSame(CommunityStatus::Pending, $community->refresh()->status);
    }

    public function test_penolakan_tidak_tampil_di_publik_dan_alasannya_terlihat_pengaju(): void
    {
        $owner = User::factory()->create();
        $community = Community::factory()->for($owner, 'owner')->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'rejected',
            'expected_status' => 'pending',
            'note' => 'Foto kegiatan belum jelas.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        Passport::actingAs($owner);
        $this->getJson("/api/v1/communities/{$community->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'Foto kegiatan belum jelas.');

        Passport::actingAs(User::factory()->create());
        $this->getJson("/api/v1/communities/{$community->id}")->assertNotFound();
        $this->getJson('/api/v1/communities')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_verifikasi_yang_salah_bisa_dicabut(): void
    {
        $community = Community::factory()->approved()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'rejected',
            'expected_status' => 'approved',
            'note' => 'Ternyata komunitas fiktif.',
        ])->assertOk();

        $this->getJson('/api/v1/communities')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_penolakan_bisa_disetujui_dan_alasan_lama_hilang_dari_publik(): void
    {
        $community = Community::factory()->rejected()->create(['review_note' => 'Alasan lama.']);
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'approved',
            'expected_status' => 'rejected',
        ])->assertOk()->assertJsonPath('data.review_note', null);

        $this->assertNull($community->refresh()->review_note);
    }

    public function test_keputusan_super_admin_lain_tidak_tertimpa_diam_diam(): void
    {
        $community = Community::factory()->approved()->create();
        Passport::actingAs($this->admin);

        // Layar super admin ini masih menampilkan status menunggu.
        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'rejected',
            'expected_status' => 'pending',
            'note' => 'Foto kegiatan belum jelas.',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Status komunitas sudah berubah. Muat ulang untuk melihat yang terbaru.');

        $this->assertSame(CommunityStatus::Approved, $community->refresh()->status);
    }

    public function test_keputusan_sama_ditolak(): void
    {
        $community = Community::factory()->approved()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'approved',
            'expected_status' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_tidak_bisa_mengembalikan_ke_menunggu(): void
    {
        $community = Community::factory()->approved()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'pending',
            'expected_status' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_keputusan_tercatat_siapa_dan_alasannya(): void
    {
        $community = Community::factory()->create();
        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/communities/{$community->id}/review", [
            'status' => 'rejected',
            'expected_status' => 'pending',
            'note' => 'Foto kegiatan belum jelas.',
        ])->assertOk();

        $log = Activity::query()->where('event', 'community_rejected')->sole();
        $this->assertTrue($this->admin->is($log->causer));
        $this->assertTrue($community->is($log->subject));
        $this->assertSame('Foto kegiatan belum jelas.', $log->properties->get('note'));
        $this->assertSame('pending', $log->properties->get('from'));
    }

    public function test_ringkasan_panel_menghitung_pengguna_dan_komunitas(): void
    {
        Community::factory()->create();
        Community::factory()->approved()->create();
        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/summary')
            ->assertOk()
            ->assertJsonPath('data.communities.pending', 1)
            ->assertJsonPath('data.communities.approved', 1)
            ->assertJsonPath('data.communities.total', 2)
            ->assertJsonPath('data.users.super_admins', 1);
    }
}
