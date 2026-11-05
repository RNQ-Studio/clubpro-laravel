<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityActivity;
use App\Models\CommunityMember;
use App\Models\User;
use App\Services\CommunityService;
use App\Support\Enums\AssetStatus;
use App\Support\Enums\StorageType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CommunityClubTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');

        $this->creator = User::factory()->create();
        $this->community = Community::factory()->approved()->for($this->creator, 'owner')->create();
    }

    public function test_kegiatan_terbagi_mendatang_dan_lampau(): void
    {
        $dekat = $this->activity('Dekat', now()->addDay());
        $jauh = $this->activity('Jauh', now()->addMonth());
        $lalu = $this->activity('Lalu', now()->subDay());
        $lama = $this->activity('Lama', now()->subMonth());
        CommunityActivity::factory()->create(['title' => 'Komunitas Lain']);
        Passport::actingAs(User::factory()->create());

        $response = $this->getJson($this->url('activities'))->assertOk();

        $this->assertSame([$dekat->id, $jauh->id], array_column($response->json('data.upcoming'), 'id'));
        $this->assertSame([$lalu->id, $lama->id], array_column($response->json('data.past'), 'id'));
    }

    public function test_creator_dan_admin_mengelola_kegiatan(): void
    {
        foreach ([$this->creator, $this->member(admin: true)] as $actor) {
            Passport::actingAs($actor);

            $id = $this->postJson($this->url('activities'), [
                'title' => 'Latihan Bersama',
                'description' => 'Bawa perlengkapan sendiri.',
                'location' => 'Lapangan Gasibu',
                'starts_at' => now()->addWeek()->toIso8601String(),
            ])->assertCreated()->assertJsonPath('data.title', 'Latihan Bersama')->json('data.id');

            $this->putJson($this->url("activities/{$id}"), [
                'title' => 'Latihan Akbar',
                'starts_at' => now()->addWeeks(2)->toIso8601String(),
            ])->assertOk()->assertJsonPath('data.title', 'Latihan Akbar');

            $this->deleteJson($this->url("activities/{$id}"))->assertOk();
            $this->assertDatabaseMissing('community_activities', ['id' => $id]);
        }
    }

    public function test_anggota_biasa_tidak_bisa_mengelola_kegiatan(): void
    {
        $activity = $this->activity('Latihan', now()->addDay());
        Passport::actingAs($this->member());

        $body = ['title' => 'Coba', 'starts_at' => now()->addDay()->toIso8601String()];
        $this->postJson($this->url('activities'), $body)->assertForbidden();
        $this->putJson($this->url("activities/{$activity->id}"), $body)->assertForbidden();
        $this->deleteJson($this->url("activities/{$activity->id}"))->assertForbidden();
    }

    public function test_kegiatan_divalidasi_berbahasa_indonesia(): void
    {
        Passport::actingAs($this->creator);

        $this->postJson($this->url('activities'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.title.0', 'Nama kegiatan wajib diisi.')
            ->assertJsonPath('errors.starts_at.0', 'Waktu mulai wajib diisi.');

        $this->postJson($this->url('activities'), [
            'title' => 'Latihan',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDay()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonPath('errors.ends_at.0', 'Waktu selesai harus sesudah waktu mulai.');
    }

    public function test_kegiatan_komunitas_lain_tidak_bisa_diubah_lewat_url(): void
    {
        $other = CommunityActivity::factory()->create();
        Passport::actingAs($this->creator);

        $this->deleteJson($this->url("activities/{$other->id}"))->assertNotFound();
        $this->assertDatabaseHas('community_activities', ['id' => $other->id]);
    }

    public function test_kegiatan_pengajuan_orang_lain_tertutup(): void
    {
        $pending = Community::factory()->create();
        Passport::actingAs($this->creator);

        $this->getJson("/api/v1/communities/{$pending->id}/activities")->assertNotFound();
    }

    public function test_detail_menghitung_kegiatan_mendatang_dan_foto_ber_id(): void
    {
        $this->activity('Dekat', now()->addDay());
        $this->activity('Lalu', now()->subDay());
        $asset = $this->image($this->creator);
        $this->community->photos()->attach($asset->id, ['sort_order' => 0]);
        Passport::actingAs($this->creator);

        $this->getJson("/api/v1/communities/{$this->community->id}")
            ->assertOk()
            ->assertJsonPath('data.upcoming_activities_count', 1)
            ->assertJsonPath('data.photos.0.id', $asset->id)
            ->assertJsonCount(1, 'data.photo_urls');
    }

    public function test_pengelola_menambah_foto_galeri(): void
    {
        $admin = $this->member(admin: true);
        $asset = $this->image($admin, ['retain_until' => now()->addDay()]);
        Passport::actingAs($admin);

        $this->postJson($this->url('photos'), ['asset_id' => $asset->id])
            ->assertCreated()
            ->assertJsonCount(1, 'data.photos');

        $this->assertNull($asset->refresh()->retain_until);
    }

    public function test_foto_baru_masuk_paling_akhir(): void
    {
        $first = $this->image($this->creator);
        $second = $this->image($this->creator);
        Passport::actingAs($this->creator);

        $this->postJson($this->url('photos'), ['asset_id' => $first->id])->assertCreated();
        $this->postJson($this->url('photos'), ['asset_id' => $second->id])
            ->assertCreated()
            ->assertJsonPath('data.photos.1.id', $second->id);
    }

    public function test_anggota_biasa_tidak_bisa_mengubah_galeri(): void
    {
        $member = $this->member();
        $mine = $this->image($member);
        $existing = $this->image($this->creator);
        $this->community->photos()->attach($existing->id, ['sort_order' => 0]);
        Passport::actingAs($member);

        $this->postJson($this->url('photos'), ['asset_id' => $mine->id])->assertForbidden();
        $this->deleteJson($this->url("photos/{$existing->id}"))->assertForbidden();
    }

    public function test_foto_unggahan_orang_lain_ditolak(): void
    {
        $stranger = $this->image(User::factory()->create());
        Passport::actingAs($this->creator);

        $this->postJson($this->url('photos'), ['asset_id' => $stranger->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_id']);
    }

    public function test_galeri_berbatas(): void
    {
        foreach (range(1, CommunityService::MAX_GALLERY_PHOTOS) as $order) {
            $this->community->photos()->attach($this->image($this->creator)->id, ['sort_order' => $order]);
        }
        Passport::actingAs($this->creator);

        $this->postJson($this->url('photos'), ['asset_id' => $this->image($this->creator)->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_id']);
    }

    public function test_menghapus_foto_dari_galeri_dan_penyimpanan(): void
    {
        Storage::disk('public')->put('x/foto.jpg', 'isi');
        $asset = $this->image($this->creator, [
            'storage_type' => StorageType::Local,
            'path' => 'x/foto.jpg',
            'url' => null,
        ]);
        $this->community->photos()->attach($asset->id, ['sort_order' => 0]);
        Passport::actingAs($this->creator);

        $this->deleteJson($this->url("photos/{$asset->id}"))
            ->assertOk()
            ->assertJsonCount(0, 'data.photos');

        Storage::disk('public')->assertMissing('x/foto.jpg');
        $this->assertSame(AssetStatus::HardDeleted, $asset->refresh()->status);
    }

    public function test_foto_komunitas_lain_tidak_bisa_dihapus_lewat_url(): void
    {
        $other = Community::factory()->approved()->create();
        $asset = $this->image(User::factory()->create());
        $other->photos()->attach($asset->id, ['sort_order' => 0]);
        Passport::actingAs($this->creator);

        $this->deleteJson($this->url("photos/{$asset->id}"))->assertNotFound();

        $this->assertSame(AssetStatus::Active, $asset->refresh()->status);
    }

    public function test_profil_sesama_anggota_hanya_data_umum(): void
    {
        $viewer = $this->member();
        $target = User::factory()->create([
            'name' => 'Rina Ayu',
            'email' => 'rina@gmail.com',
            'phone' => '081234567890',
            'address' => 'Jl. Rahasia 1',
            'birth_date' => '2001-04-12',
            'emergency_contact_phone' => '081298765432',
        ]);
        CommunityMember::factory()->admin()->for($this->community)->for($target)->create();
        Passport::actingAs($viewer);

        $response = $this->getJson("/api/v1/members/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Rina Ayu')
            ->assertJsonPath('data.communities.0.id', $this->community->id)
            ->assertJsonPath('data.communities.0.role', 'admin')
            ->assertJsonPath('data.communities.0.role_label', 'Admin');

        foreach (['rina@gmail.com', '081234567890', 'Jl. Rahasia 1', '2001-04-12', '081298765432'] as $private) {
            $this->assertStringNotContainsString($private, (string) $response->getContent());
        }
    }

    public function test_profil_hanya_menyebut_komunitas_yang_sama(): void
    {
        $viewer = $this->member();
        $target = User::factory()->create();
        CommunityMember::factory()->for($this->community)->for($target)->create();
        CommunityMember::factory()->for($target)->create(); // Komunitas lain milik target.
        Passport::actingAs($viewer);

        $this->getJson("/api/v1/members/{$target->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.communities');
    }

    public function test_profil_tertutup_bagi_yang_tidak_satu_komunitas(): void
    {
        $target = $this->member();
        Passport::actingAs(User::factory()->create());

        $this->getJson("/api/v1/members/{$target->id}")->assertNotFound();
    }

    public function test_permintaan_bergabung_belum_membuka_profil_anggota(): void
    {
        $target = $this->member();
        $requester = User::factory()->create();
        CommunityMember::factory()->pending()->for($this->community)->for($requester)->create();
        Passport::actingAs($requester);

        $this->getJson("/api/v1/members/{$target->id}")->assertNotFound();
    }

    public function test_profil_sendiri_selalu_terbuka(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson("/api/v1/members/{$user->id}")->assertOk()->assertJsonCount(0, 'data.communities');
    }

    private function url(string $path): string
    {
        return "/api/v1/communities/{$this->community->id}/{$path}";
    }

    private function activity(string $title, mixed $startsAt): CommunityActivity
    {
        return CommunityActivity::factory()->for($this->community)->create([
            'title' => $title,
            'starts_at' => $startsAt,
        ]);
    }

    private function member(bool $admin = false): User
    {
        $user = User::factory()->create();
        $factory = CommunityMember::factory()->for($this->community)->for($user);
        ($admin ? $factory->admin() : $factory)->create();

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function image(User $owner, array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'user_id' => $owner->getKey(),
            'mime_type' => 'image/jpeg',
            'size' => 200_000,
            ...$attributes,
        ]);
    }
}
