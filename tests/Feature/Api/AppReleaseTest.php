<?php

namespace Tests\Feature\Api;

use App\Models\AppRelease;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class AppReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const CHUNK = 64 * 1024;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['app_release.disk' => 'local', 'app_release.chunk_max_kb' => 64]);

        $client = app(ClientRepository::class)->createPasswordGrantClient('Test Password Grant', 'users', true);
        config([
            'passport.password_client.id' => $client->id,
            'passport.password_client.secret' => $client->plainSecret,
        ]);

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);

        User::factory()->create(['email' => 'releaser@example.com'])->assignRole('admin');
        $this->adminToken = $this->tokenFor('releaser@example.com');
    }

    // ── Alur upload lengkap ─────────────────────────────────────────────────

    public function test_chunked_upload_publishes_release_and_serves_download(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-17 03:30:00', 'UTC'));
        $apk = $this->fakeApk(200 * 1024);

        $release = $this->uploadApk($apk, ['version_name' => '1.2.0', 'version_code' => 7, 'release_notes' => 'Perbaikan login.'])
            ->assertCreated()
            ->assertJsonPath('data.app', 'main')
            ->assertJsonPath('data.app_name', 'CEO')
            ->assertJsonPath('data.version_code', 7)
            ->assertJsonPath('data.file_sha256', hash('sha256', $apk))
            ->assertJsonPath('data.download_filename', 'CEO-Dev-v1.2.0-build7-2026-10-17-1030WIB.apk')
            ->json('data');

        $this->assertStringEndsWith('/apk/main/dev', $release['download_url']);
        $this->assertDatabaseCount('app_releases', 1);

        $download = $this->get('/apk/main/dev')->assertOk();
        $this->assertSame($apk, $download->streamedContent());
        $this->assertSame('application/vnd.android.package-archive', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString(
            'attachment; filename=CEO-Dev-v1.2.0-build7-2026-10-17-1030WIB.apk',
            (string) $download->headers->get('Content-Disposition'),
        );

        $this->getJson('/api/v1/app/releases/latest?app=main&channel=dev')
            ->assertOk()
            ->assertJsonPath('data.version_name', '1.2.0')
            ->assertJsonPath('data.release_notes', 'Perbaikan login.');

        // Sesi upload sementara sudah dibersihkan.
        $this->assertSame([], Storage::disk('local')->directories('app-release-uploads'));
    }

    public function test_new_release_replaces_old_file_so_only_latest_is_stored(): void
    {
        $this->uploadApk($this->fakeApk(100 * 1024, 'old'), ['version_code' => 1])->assertCreated();
        $oldPath = AppRelease::query()->sole()->file_path;

        $newApk = $this->fakeApk(120 * 1024, 'new');
        $this->uploadApk($newApk, ['version_code' => 2, 'version_name' => '1.0.1'])->assertCreated();

        $release = AppRelease::query()->sole();
        $this->assertSame(2, $release->version_code);
        $this->assertNotSame($oldPath, $release->file_path);

        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame([$release->file_path], Storage::disk('local')->files('app-releases/main/dev'));
        $this->assertSame($newApk, $this->get('/apk/main/dev')->streamedContent());
    }

    public function test_channels_and_apps_are_stored_independently(): void
    {
        $this->uploadApk($this->fakeApk(70 * 1024, 'a'), ['version_code' => 3])->assertCreated();
        $this->uploadApk($this->fakeApk(70 * 1024, 'b'), ['version_code' => 1, 'app' => 'variant'])->assertCreated();

        $this->assertDatabaseCount('app_releases', 2);
        $this->get('/apk/main/dev')->assertOk();
        $this->get('/apk/variant/dev')->assertOk();
        $this->get('/apk/main/staging')->assertNotFound();
    }

    // ── Penjaga konsistensi ─────────────────────────────────────────────────

    public function test_version_code_must_increase(): void
    {
        $this->uploadApk($this->fakeApk(70 * 1024), ['version_code' => 5])->assertCreated();

        $this->startUpload($this->fakeApk(70 * 1024, 'x'), ['version_code' => 5])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_signing_certificate_change_is_rejected_unless_allowed(): void
    {
        $this->uploadApk($this->fakeApk(70 * 1024), ['version_code' => 1, 'signing_sha256' => str_repeat('a', 64)])->assertCreated();

        $other = ['version_code' => 2, 'signing_sha256' => str_repeat('b', 64)];
        $this->startUpload($this->fakeApk(70 * 1024, 'x'), $other)->assertStatus(409);

        $this->uploadApk($this->fakeApk(70 * 1024, 'x'), $other + ['allow_signing_change' => true])->assertCreated();
        $this->assertSame(str_repeat('b', 64), AppRelease::query()->sole()->signing_sha256);
    }

    public function test_checksum_mismatch_rejects_release_and_discards_session(): void
    {
        $apk = $this->fakeApk(70 * 1024);
        $uploadId = $this->startUpload($apk, ['file_sha256' => hash('sha256', 'something else')])->json('data.upload_id');

        $this->sendChunks($uploadId, $apk);
        $this->postJson("/api/v1/app/releases/uploads/{$uploadId}/complete", [], $this->auth())
            ->assertStatus(422);

        $this->assertDatabaseCount('app_releases', 0);
        $this->getJson("/api/v1/app/releases/uploads/{$uploadId}", $this->auth())->assertNotFound();
    }

    public function test_non_apk_payload_is_rejected(): void
    {
        $notZip = str_repeat('x', 70 * 1024);

        $this->uploadApk($notZip)->assertStatus(422);
        $this->assertDatabaseCount('app_releases', 0);
    }

    public function test_incomplete_upload_cannot_be_completed(): void
    {
        $apk = $this->fakeApk(150 * 1024);
        $uploadId = $this->startUpload($apk)->json('data.upload_id');
        $this->sendChunk($uploadId, 0, substr($apk, 0, self::CHUNK))->assertOk();

        $this->postJson("/api/v1/app/releases/uploads/{$uploadId}/complete", [], $this->auth())
            ->assertStatus(422);
    }

    // ── Chunk: idempoten & bisa dilanjutkan ─────────────────────────────────

    public function test_chunks_are_idempotent_and_resumable(): void
    {
        $apk = $this->fakeApk(150 * 1024);
        $uploadId = $this->startUpload($apk)->json('data.upload_id');
        $first = substr($apk, 0, self::CHUNK);

        $this->sendChunk($uploadId, 0, $first)->assertJsonPath('data.received_bytes', self::CHUNK);
        // Retry chunk yang sama (mis. sesudah timeout) tidak menggandakan data.
        $this->sendChunk($uploadId, 0, $first)->assertJsonPath('data.received_bytes', self::CHUNK);
        // Offset melompat ditolak; status memberi tahu dari mana melanjutkan.
        $this->sendChunk($uploadId, self::CHUNK * 2, $first)->assertStatus(409);
        $this->getJson("/api/v1/app/releases/uploads/{$uploadId}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.received_bytes', self::CHUNK);

        $this->sendChunks($uploadId, $apk, self::CHUNK);
        $this->postJson("/api/v1/app/releases/uploads/{$uploadId}/complete", [], $this->auth())->assertCreated();
        $this->assertSame($apk, $this->get('/apk/main/dev')->streamedContent());
    }

    public function test_chunk_beyond_declared_size_is_rejected(): void
    {
        $apk = $this->fakeApk(70 * 1024);
        $uploadId = $this->startUpload($apk)->json('data.upload_id');

        $this->sendChunks($uploadId, $apk);
        $this->sendChunk($uploadId, strlen($apk), 'extra')->assertStatus(422);
    }

    public function test_aborted_upload_is_removed(): void
    {
        $uploadId = $this->startUpload($this->fakeApk(70 * 1024))->json('data.upload_id');

        $this->deleteJson("/api/v1/app/releases/uploads/{$uploadId}", [], $this->auth())->assertOk();
        $this->getJson("/api/v1/app/releases/uploads/{$uploadId}", $this->auth())->assertNotFound();
    }

    public function test_stale_upload_sessions_are_pruned_on_new_upload(): void
    {
        $staleId = $this->startUpload($this->fakeApk(70 * 1024))->json('data.upload_id');

        $this->travel(config('app_release.upload_ttl_minutes') + 1)->minutes();
        $this->adminToken = $this->tokenFor('releaser@example.com');
        $this->startUpload($this->fakeApk(70 * 1024, 'y'))->assertCreated();

        Storage::disk('local')->assertMissing("app-release-uploads/{$staleId}");
    }

    // ── Otorisasi ───────────────────────────────────────────────────────────

    public function test_upload_requires_authentication(): void
    {
        $this->postJson('/api/v1/app/releases/uploads', $this->metadata($this->fakeApk(70 * 1024)))
            ->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_upload(): void
    {
        User::factory()->create(['email' => 'member@example.com']);
        $this->adminToken = $this->tokenFor('member@example.com');

        $this->startUpload($this->fakeApk(70 * 1024))->assertForbidden();
    }

    public function test_upload_session_is_private_to_its_creator(): void
    {
        $uploadId = $this->startUpload($this->fakeApk(70 * 1024))->json('data.upload_id');

        User::factory()->create(['email' => 'other-admin@example.com'])->assignRole('admin');
        $this->adminToken = $this->tokenFor('other-admin@example.com');

        $this->getJson("/api/v1/app/releases/uploads/{$uploadId}", $this->auth())->assertNotFound();
    }

    public function test_unknown_app_is_rejected(): void
    {
        $this->startUpload($this->fakeApk(70 * 1024), ['app' => 'nope'])->assertStatus(422);
        $this->getJson('/api/v1/app/releases/latest?app=nope')->assertStatus(422);
    }

    public function test_latest_and_download_return_404_without_release(): void
    {
        $this->getJson('/api/v1/app/releases/latest?app=main')->assertNotFound();
        $this->get('/apk/main/dev')->assertNotFound();
    }

    public function test_deleting_release_removes_its_file(): void
    {
        $this->uploadApk($this->fakeApk(70 * 1024))->assertCreated();
        $release = AppRelease::query()->sole();

        $release->delete();

        Storage::disk('local')->assertMissing($release->file_path);
        $this->get('/apk/main/dev')->assertNotFound();
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function tokenFor(string $email): string
    {
        // Guard api menyimpan user hasil request sebelumnya dalam satu test;
        // lupakan agar token berikutnya benar-benar dibaca ulang.
        $this->app['auth']->forgetGuards();

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->json('data.access_token');
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken];
    }

    private function fakeApk(int $size, string $seed = 'apk'): string
    {
        $body = '';
        for ($i = 0; strlen($body) < $size; $i++) {
            $body .= hash('sha256', $seed.$i, true);
        }

        return "PK\x03\x04".substr($body, 0, $size - 4);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function metadata(string $apk, array $overrides = []): array
    {
        return array_merge([
            'app' => 'main',
            'channel' => 'dev',
            'version_name' => '1.0.0',
            'version_code' => 1,
            'file_size' => strlen($apk),
            'file_sha256' => hash('sha256', $apk),
            'git_sha' => 'abc1234',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    private function startUpload(string $apk, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/app/releases/uploads', $this->metadata($apk, $overrides), $this->auth());
    }

    private function sendChunk(string $uploadId, int $offset, string $bytes): TestResponse
    {
        return $this->call(
            'POST',
            "/api/v1/app/releases/uploads/{$uploadId}/chunks?offset={$offset}",
            server: $this->transformHeadersToServerVars($this->auth() + [
                'Content-Type' => 'application/octet-stream',
                'Accept' => 'application/json',
            ]),
            content: $bytes,
        );
    }

    private function sendChunks(string $uploadId, string $apk, int $from = 0): void
    {
        for ($offset = $from; $offset < strlen($apk); $offset += self::CHUNK) {
            $this->sendChunk($uploadId, $offset, substr($apk, $offset, self::CHUNK))->assertOk();
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function uploadApk(string $apk, array $overrides = []): TestResponse
    {
        $start = $this->startUpload($apk, $overrides)->assertCreated();
        $uploadId = $start->json('data.upload_id');

        $this->sendChunks($uploadId, $apk);

        return $this->postJson("/api/v1/app/releases/uploads/{$uploadId}/complete", [], $this->auth());
    }
}
