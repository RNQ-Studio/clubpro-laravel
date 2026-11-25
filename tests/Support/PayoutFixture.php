<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MemberIdentity;
use App\Models\Payout;
use App\Models\PayoutFragment;
use App\Models\PayoutPart;
use App\Models\User;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Services\MoneyMovementService;
use App\Support\Enums\StorageType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Synthetic data only. No production bank, PIN, message or money is used. */
trait PayoutFixture
{
    private User $founder;

    private User $sender;

    private User $recipient;

    private User $admin;

    private Community $community;

    private MoneyMovementService $movement;

    private LedgerService $ledger;

    private string $fixturePin;

    private string $fixtureBankNumber;

    private function fixture(int $funding = 100000): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringContainsString('test', (string) config('database.connections.pgsql.database'));
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('gcs');
        $this->seed(RolePermissionSeeder::class);
        $this->fixturePin = (string) random_int(100000, 999999);
        $this->fixtureBankNumber = '000'.random_int(1000000, 9999999);
        $this->founder = User::factory()->create(['name' => 'Pendiri Fixture']);
        $this->community = Community::factory()->approved()->for($this->founder, 'owner')->create([
            'dues_bank_name' => 'Bank Fixture', 'dues_account_number' => '000'.random_int(1000000, 9999999), 'dues_account_name' => 'Kas Fixture',
        ]);
        $this->sender = User::factory()->create(['name' => 'Pengirim Fixture', 'pin_hash' => Hash::make($this->fixturePin)]);
        $this->recipient = User::factory()->create(['name' => 'Penerima Fixture']);
        $this->admin = User::factory()->create(['name' => 'Admin Fixture']);
        CommunityMember::factory()->for($this->community)->for($this->sender)->create();
        CommunityMember::factory()->for($this->community)->for($this->recipient)->create();
        CommunityMember::factory()->for($this->community)->for($this->admin)->admin()->create();
        $this->movement = app(MoneyMovementService::class);
        $this->ledger = app(LedgerService::class);
        $this->ledger->wallet($this->sender);
        $this->ledger->wallet($this->recipient);
        $this->movement->savePrimary($this->sender, $this->bankData());
        $this->fund($this->sender, $this->community, $this->founder, $funding);
    }

    /** @return array<string,mixed> */
    private function bankData(int $version = 0): array
    {
        return ['bank_code' => 'FIXTURE', 'bank_name' => 'Bank Fixture', 'account_number' => $this->fixtureBankNumber, 'account_name' => 'Pihak Ketiga Fixture', 'expected_version' => $version, 'acknowledged' => true];
    }

    private function fund(User $user, Community $community, User $reviewer, int $amount): void
    {
        $proof = $this->proof($user);
        $receipts = app(IncomingReceiptService::class);
        $receipt = $receipts->submit($user, $community, ['claimed_amount' => $amount, 'proof_asset_id' => $proof->id, 'bank_reference' => 'CLAIM-'.Str::uuid(), 'idempotency_key' => (string) Str::uuid()]);
        $receipts->verify($reviewer, $receipt, $amount, 'FUNDED-'.Str::uuid(), (string) Str::uuid());
    }

    private function proof(User $owner): Asset
    {
        $proof = Asset::factory()->create(['user_id' => $owner->id, 'storage_type' => StorageType::Private, 'path' => 'payout-fixture/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'size' => 64]);
        Storage::disk('local')->put($proof->path, 'SYNTHETIC-PROOF-'.Str::uuid());

        return $proof;
    }

    /** @return array<string,mixed> */
    private function payoutData(int $amount = 70000, ?string $key = null): array
    {
        return ['community_id' => $this->community->id, 'amount' => $amount, 'bank_account_version' => 1, 'pin' => $this->fixturePin, 'acknowledged' => true, 'idempotency_key' => $key ?? (string) Str::uuid()];
    }

    /** @return array<string,mixed> */
    private function transferData(int $amount = 70000, ?string $key = null): array
    {
        return ['community_id' => $this->community->id, 'recipient_identity_id' => MemberIdentity::forUser($this->recipient)->id, 'recipient_community_id' => $this->community->id, 'amount' => $amount, 'pin' => $this->fixturePin, 'idempotency_key' => $key ?? (string) Str::uuid()];
    }

    private function payout(int $amount = 70000): Payout
    {
        return $this->movement->createPayout($this->sender, $this->payoutData($amount));
    }

    private function part(Payout $payout): PayoutPart
    {
        return PayoutPart::query()->where('payout_id', $payout->id)->firstOrFail();
    }

    private function fragment(PayoutPart $part, int $amount, ?User $reviewer = null): PayoutFragment
    {
        $reviewer ??= $this->founder;
        if ($part->fresh()->status === 'requested') {
            $this->movement->transition($reviewer, $part->fresh(), 'process', ['expected_version' => $part->fresh()->version, 'note' => 'Mulai proses fixture sintetis.', 'idempotency_key' => (string) Str::uuid()]);
        }
        $proof = $this->proof($reviewer);
        $this->movement->submitProof($reviewer, $part->fresh(), ['expected_version' => $part->fresh()->version, 'proof_asset_id' => $proof->id, 'amount' => $amount, 'bank_reference' => 'OUTGOING-'.Str::uuid(), 'account_number' => $this->fixtureBankNumber, 'account_name' => 'Pihak Ketiga Fixture', 'note' => 'Bukti sintetis untuk pengujian, bukan transfer bank.', 'idempotency_key' => (string) Str::uuid()]);

        return PayoutFragment::query()->where('proof_asset_id', $proof->id)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function moneyState(): array
    {
        return [
            'accounts' => DB::table('ledger_accounts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'journals' => DB::table('ledger_journals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'entries' => DB::table('ledger_entries')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'holdings' => DB::table('ledger_lot_holdings')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'reservations' => DB::table('ledger_reservations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'payouts' => DB::table('payouts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'parts' => DB::table('payout_parts')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'fragments' => DB::table('payout_fragments')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
