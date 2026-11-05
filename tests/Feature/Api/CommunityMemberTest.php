<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CommunityMemberTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);

        $this->creator = User::factory()->create(['name' => 'Creator']);
        $this->community = Community::factory()->approved()->for($this->creator, 'owner')->create();
    }

    public function test_pengaju_otomatis_menjadi_creator(): void
    {
        $member = $this->community->membershipOf($this->creator);

        $this->assertSame(CommunityMemberRole::Creator, $member?->role);
        $this->assertSame(CommunityMemberStatus::Active, $member?->status);
    }

    public function test_migrasi_mengisi_creator_untuk_komunitas_lama(): void
    {
        DB::table('community_members')->delete();
        $migration = require database_path('migrations/2026_11_05_190000_create_community_members_table.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('community_members')->count());
        $this->assertSame(CommunityMemberRole::Creator, $this->community->membershipOf($this->creator)?->role);
    }

    /** @return array<string, array{string, string}> */
    public static function protectedEndpoints(): array
    {
        return [
            'gabung' => ['POST', '/api/v1/communities/{c}/join'],
            'anggota' => ['GET', '/api/v1/communities/{c}/members'],
            'komunitas saya' => ['GET', '/api/v1/communities/joined'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_tanpa_login_ditolak(string $method, string $path): void
    {
        $this->json($method, str_replace('{c}', (string) $this->community->id, $path))->assertUnauthorized();
    }

    public function test_gabung_menunggu_persetujuan_dan_belum_jadi_anggota(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->postJson($this->url('join'))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.role', 'member');

        $this->getJson('/api/v1/communities/joined')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('members'))->assertForbidden();
        $this->getJson("/api/v1/communities/{$this->community->id}")
            ->assertOk()
            ->assertJsonPath('data.my_status', 'pending')
            ->assertJsonPath('data.can_manage', false);
    }

    public function test_gabung_dua_kali_tidak_menggandakan(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->postJson($this->url('join'))->assertOk();
        $this->postJson($this->url('join'))->assertOk();

        $this->assertSame(2, $this->community->members()->count());
    }

    public function test_komunitas_belum_terverifikasi_tidak_bisa_diikuti(): void
    {
        $pending = Community::factory()->for($this->creator, 'owner')->create();
        Passport::actingAs(User::factory()->create());

        // Pengajuan orang lain seolah tidak ada.
        $this->postJson("/api/v1/communities/{$pending->id}/join")->assertNotFound();
    }

    public function test_anggota_aktif_tidak_bisa_gabung_lagi(): void
    {
        $member = $this->member();
        Passport::actingAs($member->user);

        $this->postJson($this->url('join'))->assertUnprocessable();
    }

    public function test_creator_menerima_permintaan(): void
    {
        $request = $this->member(pending: true);
        Passport::actingAs($this->creator);

        $this->postJson($this->url("members/{$request->id}/approve"))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertNotNull($request->refresh()->joined_at);
        $this->assertSame(1, Activity::query()->where('event', 'community_member_approved')->count());

        Passport::actingAs($request->user);
        $this->getJson('/api/v1/communities/joined')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_juga_bisa_menerima_permintaan(): void
    {
        $request = $this->member(pending: true);
        Passport::actingAs($this->member(admin: true)->user);

        $this->postJson($this->url("members/{$request->id}/approve"))->assertOk();
    }

    public function test_menolak_permintaan_menghapusnya_dan_bisa_minta_lagi(): void
    {
        $request = $this->member(pending: true);
        Passport::actingAs($this->creator);

        $this->deleteJson($this->url("members/{$request->id}"))->assertOk();

        $this->assertDatabaseMissing('community_members', ['id' => $request->id]);
        $this->assertSame(1, Activity::query()->where('event', 'community_member_rejected')->count());

        Passport::actingAs($request->user);
        $this->postJson($this->url('join'))->assertOk();
    }

    public function test_daftar_memuat_aktif_saja_urut_peran_tanpa_data_privat(): void
    {
        $this->member(name: 'Zaki');
        $this->member(name: 'Budi', admin: true);
        $this->member(name: 'Menunggu', pending: true);
        $viewer = $this->member(name: 'Andi');
        Passport::actingAs($viewer->user);

        $rows = $this->getJson($this->url('members'))->assertOk()->json('data');

        $this->assertSame(['Creator', 'Budi', 'Andi', 'Zaki'], array_column($rows, 'name'));
        $this->assertSame('Creator', $rows[0]['role_label']);
        $this->assertSame('Admin', $rows[1]['role_label']);
        foreach (['email', 'phone', 'address', 'birth_date', 'emergency_contact_phone'] as $private) {
            $this->assertArrayNotHasKey($private, $rows[0]);
        }
    }

    public function test_daftar_anggota_tertutup_untuk_bukan_anggota(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson($this->url('members'))->assertForbidden();
    }

    public function test_permintaan_hanya_terlihat_creator_dan_admin(): void
    {
        $this->member(name: 'Menunggu', pending: true);
        $member = $this->member();

        Passport::actingAs($member->user);
        $this->getJson($this->url('members?status=pending'))->assertForbidden();

        Passport::actingAs($this->creator);
        $this->getJson($this->url('members?status=pending'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Menunggu');
    }

    public function test_jumlah_query_daftar_tidak_bertambah_per_anggota(): void
    {
        Passport::actingAs($this->creator);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url('members'))->assertOk();

            return count(DB::getQueryLog());
        };

        $this->member();
        $count();
        $sedikit = $count();

        foreach (range(1, 5) as $_) {
            $this->member();
        }

        $this->assertSame($sedikit, $count());
    }

    public function test_avatar_path_lama_tidak_merusak_daftar(): void
    {
        $user = User::factory()->create(['avatar' => 'avatars/lama.jpg']);
        CommunityMember::factory()->for($this->community)->for($user)->create();
        Passport::actingAs($this->creator);

        $this->getJson($this->url('members'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_creator_dan_admin_bisa_memberi_akses_admin(): void
    {
        foreach ([$this->creator, $this->member(admin: true)->user] as $actor) {
            $target = $this->member();
            Passport::actingAs($actor);

            $this->putJson($this->url("members/{$target->id}/role"), ['role' => 'admin'])
                ->assertOk()
                ->assertJsonPath('data.role', 'admin');
        }

        $this->assertSame(2, Activity::query()->where('event', 'community_admin_granted')->count());
    }

    public function test_akses_admin_langsung_berlaku(): void
    {
        $member = $this->member();
        $request = $this->member(pending: true);

        Passport::actingAs($member->user);
        $this->postJson($this->url("members/{$request->id}/approve"))->assertForbidden();

        $member->update(['role' => CommunityMemberRole::Admin]);
        Passport::actingAs($member->user->refresh());
        $this->postJson($this->url("members/{$request->id}/approve"))->assertOk();
    }

    public function test_anggota_biasa_tidak_bisa_mengelola(): void
    {
        $member = $this->member();
        $other = $this->member();
        Passport::actingAs($member->user);

        $this->putJson($this->url("members/{$other->id}/role"), ['role' => 'admin'])->assertForbidden();
        $this->putJson($this->url("members/{$member->id}/role"), ['role' => 'admin'])->assertForbidden();
        $this->deleteJson($this->url("members/{$other->id}"))->assertForbidden();

        $this->assertSame(CommunityMemberRole::Member, $member->refresh()->role);
    }

    public function test_hanya_creator_yang_bisa_mencabut_admin(): void
    {
        $admin = $this->member(admin: true);
        $other = $this->member(admin: true);

        Passport::actingAs($admin->user);
        $this->putJson($this->url("members/{$other->id}/role"), ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
        $this->assertSame(CommunityMemberRole::Admin, $other->refresh()->role);

        Passport::actingAs($this->creator);
        $this->putJson($this->url("members/{$other->id}/role"), ['role' => 'member'])->assertOk();
        $this->assertSame(1, Activity::query()->where('event', 'community_admin_revoked')->count());
    }

    public function test_admin_boleh_mundur_sendiri(): void
    {
        $admin = $this->member(admin: true);
        Passport::actingAs($admin->user);

        $this->putJson($this->url("members/{$admin->id}/role"), ['role' => 'member'])->assertOk();
    }

    public function test_peran_creator_tidak_bisa_diubah_atau_diberikan(): void
    {
        $creator = $this->community->membershipOf($this->creator);
        $member = $this->member();
        Passport::actingAs($this->member(admin: true)->user);

        $this->putJson($this->url("members/{$creator?->id}/role"), ['role' => 'member'])->assertUnprocessable();
        $this->putJson($this->url("members/{$member->id}/role"), ['role' => 'creator'])->assertUnprocessable();

        $this->assertSame(CommunityMemberRole::Creator, $creator?->refresh()->role);
    }

    public function test_permintaan_belum_diterima_tidak_bisa_dijadikan_admin(): void
    {
        $request = $this->member(pending: true);
        Passport::actingAs($this->creator);

        $this->putJson($this->url("members/{$request->id}/role"), ['role' => 'admin'])->assertUnprocessable();
    }

    public function test_mengeluarkan_anggota(): void
    {
        $member = $this->member();
        Passport::actingAs($this->member(admin: true)->user);

        $this->deleteJson($this->url("members/{$member->id}"))->assertOk();

        $this->assertDatabaseMissing('community_members', ['id' => $member->id]);
        $this->assertSame(1, Activity::query()->where('event', 'community_member_removed')->count());
    }

    public function test_admin_tidak_bisa_mengeluarkan_admin_lain_atau_creator(): void
    {
        $other = $this->member(admin: true);
        $creator = $this->community->membershipOf($this->creator);
        Passport::actingAs($this->member(admin: true)->user);

        $this->deleteJson($this->url("members/{$other->id}"))->assertUnprocessable();
        $this->deleteJson($this->url("members/{$creator?->id}"))->assertUnprocessable();

        $this->assertDatabaseHas('community_members', ['id' => $other->id]);
    }

    public function test_creator_bisa_mengeluarkan_admin(): void
    {
        $admin = $this->member(admin: true);
        Passport::actingAs($this->creator);

        $this->deleteJson($this->url("members/{$admin->id}"))->assertOk();
    }

    public function test_keluar_dari_komunitas(): void
    {
        $member = $this->member();
        Passport::actingAs($member->user);

        $this->postJson($this->url('leave'))->assertOk();

        $this->assertDatabaseMissing('community_members', ['id' => $member->id]);
    }

    public function test_membatalkan_permintaan_bergabung(): void
    {
        $request = $this->member(pending: true);
        Passport::actingAs($request->user);

        $this->postJson($this->url('leave'))->assertOk();

        $this->assertDatabaseMissing('community_members', ['id' => $request->id]);
    }

    public function test_creator_tidak_bisa_keluar(): void
    {
        Passport::actingAs($this->creator);

        $this->postJson($this->url('leave'))->assertUnprocessable();
    }

    public function test_bukan_anggota_tidak_bisa_keluar(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->postJson($this->url('leave'))->assertUnprocessable();
    }

    public function test_admin_komunitas_lain_tidak_bisa_menyentuh_anggota_komunitas_ini(): void
    {
        $member = $this->member();
        $other = Community::factory()->approved()->create();
        $outsider = User::factory()->create();
        CommunityMember::factory()->admin()->for($other)->for($outsider)->create();
        Passport::actingAs($outsider);

        // Lewat komunitasnya sendiri: id anggota bukan miliknya.
        $this->putJson("/api/v1/communities/{$other->id}/members/{$member->id}/role", ['role' => 'admin'])->assertNotFound();
        $this->deleteJson("/api/v1/communities/{$other->id}/members/{$member->id}")->assertNotFound();
        // Lewat komunitas ini: bukan pengelola.
        $this->putJson($this->url("members/{$member->id}/role"), ['role' => 'admin'])->assertForbidden();

        $this->assertSame(CommunityMemberRole::Member, $member->refresh()->role);
    }

    public function test_komunitas_saya_memuat_peran_dan_jumlah_anggota(): void
    {
        $this->member();
        $this->member(pending: true);
        Community::factory()->create(['name' => 'Bukan Punya Saya']);
        Passport::actingAs($this->creator);

        $this->getJson('/api/v1/communities/joined')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.my_role', 'creator')
            ->assertJsonPath('data.0.members_count', 2);
    }

    public function test_creator_melihat_pengajuannya_yang_belum_terverifikasi(): void
    {
        Community::factory()->for($this->creator, 'owner')->create(['name' => 'Masih Menunggu']);
        Passport::actingAs($this->creator);

        $names = collect($this->getJson('/api/v1/communities/joined')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Masih Menunggu'));
    }

    public function test_verifikasi_dicabut_hilang_dari_daftar_anggota_biasa(): void
    {
        $member = $this->member();
        $this->community->forceFill(['status' => 'rejected'])->save();
        Passport::actingAs($member->user);

        $this->getJson('/api/v1/communities/joined')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pengajuan_baru_membuat_baris_creator(): void
    {
        $community = Community::factory()->approved()->create(['user_id' => null]);
        $this->assertSame(0, $community->members()->count());

        Passport::actingAs($this->creator);
        $this->getJson('/api/v1/communities/'.$this->community->id)
            ->assertOk()
            ->assertJsonPath('data.my_role', 'creator')
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.pending_members_count', 0);
    }

    public function test_jumlah_permintaan_hanya_untuk_pengelola(): void
    {
        $member = $this->member();
        Passport::actingAs($member->user);

        $this->getJson('/api/v1/communities/'.$this->community->id)
            ->assertOk()
            ->assertJsonMissingPath('data.pending_members_count');
    }

    public function test_super_admin_setara_creator_untuk_menengahi(): void
    {
        $admin = $this->member(admin: true);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');
        Passport::actingAs($superAdmin);

        $this->getJson($this->url('members'))->assertOk();
        $this->putJson($this->url("members/{$admin->id}/role"), ['role' => 'member'])->assertOk();
    }

    private function url(string $path): string
    {
        return "/api/v1/communities/{$this->community->id}/{$path}";
    }

    private function member(string $name = 'Anggota', bool $admin = false, bool $pending = false): CommunityMember
    {
        $factory = CommunityMember::factory()->for($this->community)->for(User::factory()->create(['name' => $name]));
        $factory = $admin ? $factory->admin() : $factory;

        return ($pending ? $factory->pending() : $factory)->create();
    }
}
