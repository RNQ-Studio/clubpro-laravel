<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesMonth;
use App\Models\DuesPayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CommunityDuesTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $member;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);
        // 8 November 2026 pukul 10.00 WIB.
        Carbon::setTestNow(Carbon::parse('2026-11-08 03:00:00', 'UTC'));

        $this->creator = User::factory()->create(['name' => 'Creator']);
        $this->community = Community::factory()->approved()->for($this->creator, 'owner')->create();
        $this->member = $this->join('Budi Santoso', '2025-06-01 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'ringkasan' => ['GET', 'dues'],
            'setelan' => ['PUT', 'dues/settings'],
            'bayar' => ['POST', 'dues/payments'],
            'daftar bayar' => ['GET', 'dues/payments'],
            'terima' => ['POST', 'dues/payments/1/approve'],
            'tolak' => ['POST', 'dues/payments/1/reject'],
            'kalender' => ['GET', 'dues/members'],
            'kalender anggota' => ['GET', 'dues/members/1'],
            'ubah bulan' => ['PUT', 'dues/members/1/months/2026-03'],
            'uang masuk' => ['PUT', 'dues/members/1/entry-fee'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_tanpa_login_ditolak(string $method, string $path): void
    {
        $this->json($method, $this->url($path))->assertUnauthorized();
    }

    public function test_pengelola_mengatur_iuran_dan_tercatat(): void
    {
        Passport::actingAs($this->creator);

        $this->putJson($this->url('dues/settings'), [
            'dues_amount' => 50000,
            'entry_fee' => 100000,
            'dues_start_date' => '2026-03-10',
            'dues_bank_name' => 'BCA',
            'dues_account_number' => '123-456 7890',
            'dues_account_name' => 'Komunitas Pemanah',
        ])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.monthly_amount', 50000)
            ->assertJsonPath('data.entry_fee', 100000)
            ->assertJsonPath('data.start_date', '2026-03-10')
            ->assertJsonPath('data.due_day', 10)
            ->assertJsonPath('data.account_number', '1234567890');

        $log = Activity::query()->where('subject_id', $this->community->id)->latest('id')->firstOrFail();
        $this->assertSame($this->creator->id, $log->causer_id);
        $this->assertSame(50000, $log->properties['attributes']['dues_amount']);
    }

    public function test_admin_komunitas_juga_boleh_mengatur(): void
    {
        $admin = $this->join('Admin', '2025-01-01 00:00:00', admin: true);
        Passport::actingAs($admin);

        $this->putJson($this->url('dues/settings'), $this->settings())->assertOk();
    }

    public function test_anggota_biasa_tidak_bisa_mengatur_atau_memverifikasi(): void
    {
        $this->configure();
        Passport::actingAs($this->member);

        $this->putJson($this->url('dues/settings'), $this->settings())->assertForbidden();
        $this->getJson($this->url('dues/payments'))->assertForbidden();
        $this->getJson($this->url('dues/members'))->assertForbidden();
        $this->putJson($this->url("dues/members/{$this->member->id}/months/2026-03"), ['paid' => true])->assertForbidden();
    }

    public function test_bukan_anggota_tidak_bisa_melihat_atau_membayar(): void
    {
        $this->configure();
        $outsider = User::factory()->create();
        Passport::actingAs($outsider);

        $this->getJson($this->url('dues'))->assertForbidden();
        $this->postJson($this->url('dues/payments'), [
            'months_count' => 1,
            'proof_asset_id' => $this->proof($outsider)->id,
        ])->assertForbidden();
    }

    public function test_validasi_setelan_berbahasa_indonesia(): void
    {
        Passport::actingAs($this->creator);

        $this->putJson($this->url('dues/settings'), [
            'dues_amount' => 500,
            'entry_fee' => -1,
            'dues_start_date' => '10-03-2026',
            'dues_bank_name' => '',
            'dues_account_number' => '12ab',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'dues_amount' => 'Iuran per bulan minimal Rp1.000.',
                'entry_fee' => 'Uang masuk tidak boleh minus.',
                'dues_start_date' => 'Tanggal mulai iuran tidak valid.',
                'dues_bank_name' => 'Nama bank wajib diisi.',
                'dues_account_number' => 'Nomor rekening hanya angka, 5–30 digit.',
                'dues_account_name' => 'Nama pemilik rekening wajib diisi.',
            ]);
    }

    public function test_iuran_belum_diatur(): void
    {
        Passport::actingAs($this->member);

        $this->getJson($this->url('dues'))
            ->assertOk()
            ->assertJsonPath('data.settings.configured', false)
            ->assertJsonPath('data.me.arrears_count', 0)
            ->assertJsonPath('data.me.next_unpaid_months', [])
            ->assertJsonPath('data.me.months.0.status', 'none');

        $this->postJson($this->url('dues/payments'), [
            'months_count' => 1,
            'proof_asset_id' => $this->proof($this->member)->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['months_count' => 'Iuran komunitas ini belum diatur pengurus.']);
    }

    public function test_kalender_anggota_lama_mulai_dari_bulan_mulai_iuran(): void
    {
        $this->configure();
        Passport::actingAs($this->member);

        $me = $this->getJson($this->url('dues'))->assertOk()->json('data.me');

        $this->assertSame('2026-03', $me['billable_from']);
        $this->assertSame([
            '2026-01' => 'none', '2026-02' => 'none',
            '2026-03' => 'overdue', '2026-04' => 'overdue', '2026-05' => 'overdue', '2026-06' => 'overdue',
            '2026-07' => 'overdue', '2026-08' => 'overdue', '2026-09' => 'overdue', '2026-10' => 'overdue',
            // Jatuh tempo 10 November; hari ini tanggal 8.
            '2026-11' => 'due', '2026-12' => 'upcoming',
        ], array_column($me['months'], 'status', 'month'));
        $this->assertSame(8, $me['arrears_count']);
        $this->assertNull($me['paid_until']);
        $this->assertSame(100000, $me['entry_fee_due']);
        $this->assertSame('2026-03', $me['next_unpaid_months'][0]);
        $this->assertCount(12, $me['next_unpaid_months']);
    }

    public function test_anggota_baru_ditagih_mulai_bulan_bergabung(): void
    {
        $this->configure();
        // 15 Juni 2026 pukul 01.00 WIB = 14 Juni UTC: bulan mengikuti WIB.
        $late = $this->join('Sari', '2026-05-31 18:00:00');
        Passport::actingAs($late);

        $me = $this->getJson($this->url('dues'))->assertOk()->json('data.me');

        $this->assertSame('2026-06', $me['billable_from']);
        $this->assertSame(5, $me['arrears_count']); // Jun–Okt
        $this->assertSame('none', $me['months'][4]['status']); // Mei
    }

    public function test_tunggakan_bertambah_sesudah_jatuh_tempo(): void
    {
        $this->configure();
        Passport::actingAs($this->member);
        Carbon::setTestNow(Carbon::parse('2026-11-10 16:59:00', 'UTC')); // 10 Nov 23.59 WIB

        $this->assertSame(8, $this->getJson($this->url('dues'))->json('data.me.arrears_count'));

        Carbon::setTestNow(Carbon::parse('2026-11-10 17:00:00', 'UTC')); // 11 Nov 00.00 WIB
        $this->assertSame(9, $this->getJson($this->url('dues'))->json('data.me.arrears_count'));
    }

    public function test_jatuh_tempo_tanggal_31_di_bulan_pendek(): void
    {
        $this->configure(['dues_start_date' => '2026-01-31']);
        Passport::actingAs($this->member);
        Carbon::setTestNow(Carbon::parse('2026-02-28 03:00:00', 'UTC'));

        $months = $this->getJson($this->url('dues'))->json('data.me.months');
        $this->assertSame('overdue', $months[0]['status']);
        $this->assertSame('due', $months[1]['status']); // jatuh tempo 28 Februari

        Carbon::setTestNow(Carbon::parse('2026-03-01 03:00:00', 'UTC'));
        $this->assertSame('overdue', $this->getJson($this->url('dues'))->json('data.me.months.1.status'));
    }

    public function test_bayar_beberapa_bulan_tunggakan_dulu_dengan_uang_masuk(): void
    {
        $this->configure();
        $proof = $this->proof($this->member, ['retain_until' => now()->addDay()]);
        Passport::actingAs($this->member);

        $this->postJson($this->url('dues/payments'), [
            'months_count' => 3,
            'proof_asset_id' => $proof->id,
            'note' => 'Transfer dari rekening istri',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.months', ['2026-03', '2026-04', '2026-05'])
            ->assertJsonPath('data.monthly_amount', 50000)
            ->assertJsonPath('data.entry_fee', 100000)
            ->assertJsonPath('data.total_amount', 250000)
            ->assertJsonPath('data.note', 'Transfer dari rekening istri');

        $this->assertNull($proof->refresh()->retain_until);

        $me = $this->getJson($this->url('dues'))->json('data.me');
        $this->assertSame('pending', $me['months'][2]['status']);
        $this->assertSame('2026-06', $me['next_unpaid_months'][0]);
        // Uang masuk sudah ikut diajukan.
        $this->assertSame(0, $me['entry_fee_due']);
        // Menunggu verifikasi belum mengurangi tunggakan.
        $this->assertSame(5, $me['arrears_count']);

        $this->postJson($this->url('dues/payments'), [
            'months_count' => 2,
            'proof_asset_id' => $this->proof($this->member)->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.months', ['2026-06', '2026-07'])
            ->assertJsonPath('data.entry_fee', 0)
            ->assertJsonPath('data.total_amount', 100000);
    }

    public function test_nominal_disalin_ke_pembayaran(): void
    {
        $this->configure();
        Passport::actingAs($this->member);
        $this->pay($this->member, 1);

        $this->community->forceFill(['dues_amount' => 75000])->save();

        $this->assertSame(50000, DuesPayment::query()->firstOrFail()->monthly_amount);
    }

    #[DataProvider('invalidPayments')]
    public function test_pembayaran_tidak_valid(array $payload, string $field, string $message): void
    {
        $this->configure();
        Passport::actingAs($this->member);

        $this->postJson($this->url('dues/payments'), [
            'months_count' => 1,
            'proof_asset_id' => $this->proof($this->member)->id,
            ...$payload,
        ])->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidPayments(): array
    {
        return [
            'nol bulan' => [['months_count' => 0], 'months_count', 'Bayar minimal 1 bulan.'],
            'lebih dari 12' => [['months_count' => 13], 'months_count', 'Bayar paling banyak 12 bulan sekaligus.'],
            'tanpa bukti' => [['proof_asset_id' => null], 'proof_asset_id', 'Unggah bukti transfer.'],
        ];
    }

    public function test_bukti_milik_orang_lain_atau_dipakai_ulang_ditolak(): void
    {
        $this->configure();
        Passport::actingAs($this->member);

        $this->postJson($this->url('dues/payments'), [
            'months_count' => 1,
            'proof_asset_id' => $this->proof($this->creator)->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('proof_asset_id');

        $proof = $this->proof($this->member);
        $this->postJson($this->url('dues/payments'), ['months_count' => 1, 'proof_asset_id' => $proof->id])->assertCreated();
        $this->postJson($this->url('dues/payments'), ['months_count' => 1, 'proof_asset_id' => $proof->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proof_asset_id' => 'Foto ini sudah dipakai. Unggah ulang fotonya.']);

        $this->postJson($this->url('dues/payments'), [
            'months_count' => 1,
            'proof_asset_id' => $this->proof($this->member, ['mime_type' => 'application/pdf'])->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('proof_asset_id');
    }

    public function test_pengelola_juga_membayar_iurannya_sendiri(): void
    {
        $this->configure();
        Passport::actingAs($this->creator);

        $this->postJson($this->url('dues/payments'), [
            'months_count' => 1,
            'proof_asset_id' => $this->proof($this->creator)->id,
        ])->assertCreated()->assertJsonPath('data.user.name', 'Creator');
    }

    public function test_terima_pembayaran_bulan_dan_uang_masuk_lunas(): void
    {
        $this->configure();
        $payment = $this->pay($this->member, 3);
        Passport::actingAs($this->creator);

        $this->getJson($this->url('dues/payments?status=pending'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', 'Budi Santoso')
            ->assertJsonPath('data.0.proof_url', fn (?string $url): bool => $url !== null);

        $this->postJson($this->url("dues/payments/{$payment->id}/approve"))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', 'Creator');

        $member = $this->getJson($this->url("dues/members/{$this->member->id}"))->assertOk()->json('data.member');
        $this->assertSame(['paid', 'paid', 'paid'], array_column(array_slice($member['months'], 2, 3), 'status'));
        $this->assertSame(5, $member['arrears_count']);
        $this->assertSame('2026-05', $member['paid_until']);
        $this->assertTrue($member['entry_fee_paid']);
        $this->assertSame(0, $member['entry_fee_due']);

        $this->assertSame(
            1,
            Activity::query()->where('event', 'dues_payment_approved')->where('causer_id', $this->creator->id)->count(),
        );
    }

    public function test_keputusan_basi_409(): void
    {
        $this->configure();
        $payment = $this->pay($this->member, 1);
        Passport::actingAs($this->creator);

        $this->postJson($this->url("dues/payments/{$payment->id}/approve"))->assertOk();
        $this->postJson($this->url("dues/payments/{$payment->id}/approve"))->assertStatus(409);
        $this->postJson($this->url("dues/payments/{$payment->id}/reject"), ['note' => 'Nominal kurang'])->assertStatus(409);
    }

    public function test_tolak_wajib_alasan_dan_bulan_kembali_belum_lunas(): void
    {
        $this->configure();
        $payment = $this->pay($this->member, 2);
        Passport::actingAs($this->creator);

        $this->postJson($this->url("dues/payments/{$payment->id}/reject"), ['note' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'Tulis alasan penolakan agar anggota tahu yang harus diperbaiki.']);

        $this->postJson($this->url("dues/payments/{$payment->id}/reject"), ['note' => 'Nominal transfer kurang'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'Nominal transfer kurang')
            // Bulan yang ditolak tetap terbaca di riwayat.
            ->assertJsonPath('data.months', ['2026-03', '2026-04']);

        Passport::actingAs($this->member);
        $me = $this->getJson($this->url('dues'))->json('data');
        $this->assertSame('overdue', $me['me']['months'][2]['status']);
        $this->assertSame(100000, $me['me']['entry_fee_due']);
        $this->assertSame('rejected', $me['payments'][0]['status']);
        $this->assertSame('Nominal transfer kurang', $me['payments'][0]['review_note']);

        // Bisa mengajukan ulang bulan yang sama.
        $this->postJson($this->url('dues/payments'), ['months_count' => 2, 'proof_asset_id' => $this->proof($this->member)->id])
            ->assertCreated()
            ->assertJsonPath('data.months', ['2026-03', '2026-04']);
    }

    public function test_pembayaran_komunitas_lain_tidak_bisa_disentuh(): void
    {
        $this->configure();
        $other = Community::factory()->approved()->create();
        $foreign = $this->pay($this->member, 1);
        $foreign->forceFill(['community_id' => $other->id])->save();
        Passport::actingAs($this->creator);

        $this->postJson($this->url("dues/payments/{$foreign->id}/approve"))->assertNotFound();
    }

    public function test_ubah_manual_bulan_lunas_dan_belum_lunas(): void
    {
        $this->configure();
        Passport::actingAs($this->creator);
        $url = $this->url("dues/members/{$this->member->id}/months/2026-04");

        $this->putJson($url, ['paid' => true])->assertOk();
        $this->putJson($url, ['paid' => true])->assertOk(); // ketuk ganda
        $member = $this->getJson($this->url("dues/members/{$this->member->id}"))->json('data.member');
        $this->assertSame('paid', $member['months'][3]['status']);
        $this->assertSame(7, $member['arrears_count']);
        // Maret belum lunas: "lunas sampai" hanya dihitung berurutan dari awal tagihan.
        $this->assertNull($member['paid_until']);
        $this->assertSame(1, DuesMonth::query()->count());

        // Di luar masa iuran pun boleh ditandai, mis. bayar di muka sebelum iuran berlaku.
        $this->putJson($this->url("dues/members/{$this->member->id}/months/2026-01"), ['paid' => true])->assertOk();

        $this->putJson($url, ['paid' => false])->assertOk();
        $member = $this->getJson($this->url("dues/members/{$this->member->id}"))->json('data.member');
        $this->assertSame('overdue', $member['months'][3]['status']);
        $this->assertSame('paid', $member['months'][0]['status']);

        $this->assertSame(2, Activity::query()->where('event', 'dues_month_paid')->count());
        $this->assertSame(1, Activity::query()->where('event', 'dues_month_unpaid')->count());
    }

    public function test_bulan_yang_lunas_lewat_pembayaran_bisa_dibatalkan_manual(): void
    {
        $this->configure();
        $payment = $this->pay($this->member, 1);
        Passport::actingAs($this->creator);
        $this->postJson($this->url("dues/payments/{$payment->id}/approve"))->assertOk();

        $this->putJson($this->url("dues/members/{$this->member->id}/months/2026-03"), ['paid' => false])->assertOk();

        $this->assertSame(0, DuesMonth::query()->count());
    }

    public function test_bulan_menunggu_verifikasi_tidak_bisa_diubah_manual(): void
    {
        $this->configure();
        $this->pay($this->member, 1);
        Passport::actingAs($this->creator);

        $this->putJson($this->url("dues/members/{$this->member->id}/months/2026-03"), ['paid' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month' => 'Bulan ini sedang menunggu verifikasi pembayaran. Terima atau tolak pembayarannya dulu.']);
    }

    public function test_ubah_manual_hanya_untuk_anggota_aktif_dan_bulan_valid(): void
    {
        $this->configure();
        $pending = CommunityMember::factory()->for($this->community)->pending()->create();
        Passport::actingAs($this->creator);

        $this->putJson($this->url("dues/members/{$pending->user_id}/months/2026-03"), ['paid' => true])->assertNotFound();
        $this->putJson($this->url("dues/members/{$this->member->id}/months/2026-13"), ['paid' => true])->assertNotFound();
        $this->putJson($this->url("dues/members/{$this->member->id}/months/2026-03"), [])->assertUnprocessable();
    }

    public function test_uang_masuk_ditandai_manual(): void
    {
        $this->configure();
        Passport::actingAs($this->creator);
        $url = $this->url("dues/members/{$this->member->id}/entry-fee");

        $this->putJson($url, ['paid' => true])->assertOk();
        $this->assertTrue($this->getJson($this->url("dues/members/{$this->member->id}"))->json('data.member.entry_fee_paid'));

        Passport::actingAs($this->member);
        $this->assertSame(0, $this->getJson($this->url('dues'))->json('data.me.entry_fee_due'));
        // Pembayaran berikutnya tanpa uang masuk.
        $this->postJson($this->url('dues/payments'), ['months_count' => 1, 'proof_asset_id' => $this->proof($this->member)->id])
            ->assertJsonPath('data.total_amount', 50000);

        Passport::actingAs($this->creator);
        $this->putJson($url, ['paid' => false])->assertOk();
        $this->assertFalse($this->getJson($this->url("dues/members/{$this->member->id}"))->json('data.member.entry_fee_paid'));
    }

    public function test_kalender_semua_anggota_dengan_tunggakan_dan_pencarian(): void
    {
        $this->configure();
        $this->join('Sari Wulandari', '2026-09-20 00:00:00');
        $this->pay($this->member, 2);
        Passport::actingAs($this->creator);

        $rows = collect($this->getJson($this->url('dues/members?year=2026'))->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['Budi Santoso', 'Creator', 'Sari Wulandari'], $rows->keys()->all());
        $this->assertSame(6, $rows['Budi Santoso']['arrears_count']);
        $this->assertSame('pending', $rows['Budi Santoso']['months'][2]['status']);
        // Bulan bergabung ikut ditagih: September dan Oktober.
        $this->assertSame(2, $rows['Sari Wulandari']['arrears_count']);
        $this->assertCount(12, $rows['Creator']['months']);

        $this->getJson($this->url('dues/members?search=sari'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sari Wulandari');

        $this->getJson($this->url('dues/members?year=2025'))
            ->assertOk()
            ->assertJsonPath('data.0.months.11.status', 'none');
    }

    public function test_kalender_tidak_bertambah_query_per_anggota(): void
    {
        $this->configure();
        Passport::actingAs($this->creator);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url('dues/members'))->assertOk();

            return count(DB::getQueryLog());
        };

        $count(); // Permintaan pertama ikut memuat token & klien Passport.
        $few = $count();
        foreach (range(1, 5) as $i) {
            $this->join("Anggota {$i}", '2025-01-01 00:00:00');
        }

        $this->assertSame($few, $count());
    }

    public function test_detail_komunitas_menghitung_pembayaran_menunggu_untuk_pengelola(): void
    {
        $this->configure();
        $this->pay($this->member, 1);

        Passport::actingAs($this->creator);
        $this->getJson($this->url(''))
            ->assertOk()
            ->assertJsonPath('data.pending_dues_payments_count', 1)
            ->assertJsonPath('data.dues_configured', true);
        $this->getJson($this->url('dues'))->assertJsonPath('data.pending_payments_count', 1);

        Passport::actingAs($this->member);
        $this->getJson($this->url(''))->assertOk()->assertJsonMissingPath('data.pending_dues_payments_count');
        $this->getJson($this->url('dues'))->assertJsonMissingPath('data.pending_payments_count');
    }

    /** @param  array<string, mixed>  $overrides */
    private function configure(array $overrides = []): void
    {
        $this->community->forceFill([...$this->settings(), ...$overrides])->save();
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return [
            'dues_amount' => 50000,
            'entry_fee' => 100000,
            'dues_start_date' => '2026-03-10',
            'dues_bank_name' => 'BCA',
            'dues_account_number' => '1234567890',
            'dues_account_name' => 'Komunitas Pemanah',
        ];
    }

    private function join(string $name, string $joinedAtUtc, bool $admin = false): User
    {
        $user = User::factory()->create(['name' => $name]);
        $factory = CommunityMember::factory()->for($this->community)->for($user)->state(['joined_at' => Carbon::parse($joinedAtUtc, 'UTC')]);
        ($admin ? $factory->admin() : $factory)->create();

        return $user;
    }

    private function pay(User $user, int $months): DuesPayment
    {
        $previous = auth()->user();
        Passport::actingAs($user);
        $id = $this->postJson($this->url('dues/payments'), [
            'months_count' => $months,
            'proof_asset_id' => $this->proof($user)->id,
        ])->assertCreated()->json('data.id');
        if ($previous instanceof User) {
            Passport::actingAs($previous);
        }

        return DuesPayment::query()->findOrFail($id);
    }

    /** @param  array<string, mixed>  $attributes */
    private function proof(User $owner, array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'user_id' => $owner->getKey(),
            'mime_type' => 'image/jpeg',
            'size' => 200_000,
            ...$attributes,
        ]);
    }

    private function url(string $path): string
    {
        return rtrim("/api/v1/communities/{$this->community->id}/{$path}", '/');
    }
}
