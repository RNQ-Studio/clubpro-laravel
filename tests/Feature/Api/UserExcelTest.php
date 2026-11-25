<?php

namespace Tests\Feature\Api;

use App\Exports\UsersExport;
use App\Imports\UsersImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\Failure;
use Tests\TestCase;

class UserExcelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        Passport::actingAs($admin);
    }

    /**
     * Test export endpoint successfully triggers excel download.
     */
    public function test_export_endpoint_returns_file_download(): void
    {
        Excel::fake();

        // Buat data user dummy
        User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@anaklangit.manahpro.web.id',
        ]);

        $response = $this->getJson('/api/v1/users/export');

        $response->assertStatus(200);

        $date = date('Ymd');
        Excel::assertDownloaded("users_export_{$date}.xlsx", function (UsersExport $export) {
            $users = $export->collection();

            return $users->contains('email', 'john@anaklangit.manahpro.web.id');
        });
    }

    /**
     * Test import endpoint returns validation errors when no file uploaded.
     */
    public function test_import_endpoint_fails_when_no_file_uploaded(): void
    {
        $response = $this->postJson('/api/v1/users/import');

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'File tidak valid. Harap upload file .xlsx',
            ]);
    }

    /**
     * Test import endpoint fails when uploaded file is not .xlsx format.
     */
    public function test_import_endpoint_fails_for_invalid_file_extension(): void
    {
        $file = UploadedFile::fake()->create('users.csv', 100);

        $response = $this->postJson('/api/v1/users/import', [
            'file' => $file,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'File tidak valid. Harap upload file .xlsx',
            ]);
    }

    /**
     * Test the business logic of UsersImport (Insert new users).
     */
    public function test_users_import_inserts_new_user_without_shared_password(): void
    {
        $import = new UsersImport;

        // Baris data valid (belum ada di DB)
        $row = [
            'name' => 'Bob Builder',
            'email' => 'bob@anaklangit.manahpro.web.id',
        ];

        // Jalankan logic model() di import
        $import->model($row);

        // Pastikan user berhasil di-insert
        $this->assertDatabaseHas('users', [
            'name' => 'Bob Builder',
            'email' => 'bob@anaklangit.manahpro.web.id',
        ]);

        // Tidak ada kredensial bawaan bersama.
        $user = User::where('email', 'bob@anaklangit.manahpro.web.id')->first();
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertNull($user->email_verified_at);

        // Cek ringkasan summary
        $summary = $import->getSummary();
        $this->assertEquals(1, $summary['total_rows']);
        $this->assertEquals(1, $summary['imported']);
        $this->assertEquals(0, $summary['skipped']);
        $this->assertEmpty($summary['errors']);
    }

    /**
     * Test the business logic of UsersImport (Update existing user's name only).
     */
    public function test_users_import_updates_existing_user_name_only(): void
    {
        // Setup user dengan password kustom
        $originalPassword = Hash::make('secret123');
        $user = User::factory()->create([
            'name' => 'Alice Margatroid',
            'email' => 'alice@anaklangit.manahpro.web.id',
            'password' => $originalPassword,
        ]);

        $import = new UsersImport;

        // Baris data valid (email sudah ada di DB)
        $row = [
            'name' => 'Alice Margatroid Revised',
            'email' => 'alice@anaklangit.manahpro.web.id',
        ];

        $import->model($row);

        // Pastikan nama terupdate
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Alice Margatroid Revised',
            'email' => 'alice@anaklangit.manahpro.web.id',
        ]);

        // Pastikan password tidak berubah menjadi default
        $user->refresh();
        $this->assertEquals($originalPassword, $user->password);

        // Cek ringkasan summary
        $summary = $import->getSummary();
        $this->assertEquals(1, $summary['total_rows']);
        $this->assertEquals(1, $summary['imported']);
        $this->assertEquals(0, $summary['skipped']);
    }

    /**
     * Test validation failure capturing and statistics in UsersImport.
     */
    public function test_users_import_validation_failure_tracking(): void
    {
        $import = new UsersImport;

        // Mock validation errors
        $failure = new Failure(
            3, // Baris 3
            'email',
            ['Email tidak valid'],
            ['name' => 'John', 'email' => 'invalid-email']
        );

        $import->onFailure($failure);

        $summary = $import->getSummary();

        $this->assertEquals(1, $summary['total_rows']);
        $this->assertEquals(0, $summary['imported']);
        $this->assertEquals(1, $summary['skipped']);
        $this->assertCount(1, $summary['errors']);
        $this->assertEquals([
            'row' => 3,
            'message' => 'Email tidak valid',
        ], $summary['errors'][0]);
    }
}
