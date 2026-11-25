<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MemberIdentity;
use App\Models\PayoutFragment;
use App\Models\PayoutPart;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Support\PayoutFixture;
use Tests\TestCase;

class MoneyMovementApiTest extends TestCase
{
    use PayoutFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    public function test_primary_third_party_leading_zero_snapshot_and_private_lists(): void
    {
        Passport::actingAs($this->sender);
        $this->getJson('/api/v1/bank-accounts/primary')->assertOk()->assertJsonPath('data.account.account_name', 'Pihak Ketiga Fixture')->assertJsonPath('data.account.account_number', $this->fixtureBankNumber);
        $response = $this->postJson('/api/v1/payouts', $this->payoutData())->assertCreated();
        $id = $response->json('data.id');
        $number = '000'.random_int(1000000, 9999999);
        $this->putJson('/api/v1/bank-accounts/primary', [...$this->bankData(1), 'account_number' => $number])->assertOk()->assertJsonPath('data.account.version', 2);
        $this->getJson('/api/v1/payouts/'.$id)->assertOk()->assertJsonPath('data.destination.account_number', $this->fixtureBankNumber);
        $this->getJson('/api/v1/payouts')->assertOk()->assertJsonMissingPath('data.items.0.destination.account_number');
        Passport::actingAs($this->recipient);
        $this->getJson('/api/v1/payouts/'.$id)->assertForbidden();
        $this->getJson('/api/v1/bank-accounts/primary')->assertOk()->assertJsonPath('data.account', null);
    }

    public function test_http_upload_input_partial_verify_full_and_retry_are_exactly_once(): void
    {
        $payout = $this->payout(70000);
        $part = $this->part($payout);
        Passport::actingAs($this->founder);
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/process', $this->action($part))->assertOk();
        $proof = $this->postJson('/api/v1/assets/upload', ['type' => 'payout-proof', 'file' => UploadedFile::fake()->image('synthetic.png', 24, 24)])->assertCreated()->json('data.id');
        $this->assertNotNull($proof);
        $payload = [...$this->action($part->fresh()), 'proof_asset_id' => $proof, 'amount' => 20000, 'bank_reference' => 'BANK-'.Str::uuid(), 'account_number' => $this->fixtureBankNumber, 'account_name' => 'Pihak Ketiga Fixture'];
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/proofs', $payload)->assertOk()->assertJsonPath('data.verified_amount', 0);
        $this->assertSame(70000, $this->ledger->wallet($this->sender)['held']);
        $fragment = PayoutFragment::query()->where('proof_asset_id', $proof)->firstOrFail();
        $verify = ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()];
        $url = '/api/v1/payout-parts/'.$part->id.'/fragments/'.$fragment->id.'/verify';
        $this->postJson($url, $verify)->assertOk()->assertJsonPath('data.status', 'uncertain')->assertJsonPath('data.verified_amount', 20000);
        $this->postJson($url, $verify)->assertOk()->assertJsonPath('data.verified_amount', 20000);
        $last = $this->fragment($part->fresh(), 50000);
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/fragments/'.$last->id.'/verify', ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(0, $this->ledger->wallet($this->sender)['held']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $this->getJson('/api/v1/communities/'.$this->community->id.'/payout-reconciliation')->assertOk()->assertJsonPath('data.balanced', true)->assertJsonPath('data.verified_amount', 70000);
    }

    public function test_pending_input_can_be_corrected_or_confirmed_unsent_failure_without_money_loss(): void
    {
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 40000);
        Passport::actingAs($this->founder);
        $this->putJson('/api/v1/payout-parts/'.$part->id.'/fragments/'.$fragment->id, [...$this->action($part->fresh()), 'amount' => 30000, 'bank_reference' => 'CORRECT-'.Str::uuid(), 'account_number' => $this->fixtureBankNumber, 'account_name' => 'Pihak Ketiga Fixture'])->assertOk()->assertJsonPath('data.parts.0.fragments.0.amount', 30000);
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/fail', [...$this->action($part->fresh()), 'confirmed_unsent' => true])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('rejected', $fragment->fresh()->status);
        $this->assertSame(100000, $this->ledger->wallet($this->sender)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_destination_over_amount_duplicate_proof_and_wrong_roles_do_not_mutate_money(): void
    {
        $payout = $this->payout();
        $part = $this->part($payout);
        $outsider = User::factory()->create();
        $foreign = Community::factory()->approved()->for($outsider, 'owner')->create();
        foreach ([$this->recipient, $outsider] as $user) {
            Passport::actingAs($user);
            $this->postJson('/api/v1/payout-parts/'.$part->id.'/process', $this->action($part))->assertForbidden();
            $this->getJson('/api/v1/payouts?scope=manage&community_id='.$this->community->id)->assertForbidden();
        }
        Passport::actingAs($this->founder);
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/process', $this->action($part))->assertOk();
        $proof = $this->proof($this->founder);
        $data = [...$this->action($part->fresh()), 'proof_asset_id' => $proof->id, 'amount' => 70001, 'bank_reference' => 'DUP-'.Str::uuid(), 'account_number' => $this->fixtureBankNumber, 'account_name' => 'Pihak Ketiga Fixture'];
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/proofs', $data)->assertUnprocessable();
        $data['amount'] = 30000;
        $data['account_name'] = 'Tujuan Salah';
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/proofs', $data)->assertUnprocessable();
        $data['account_name'] = 'Pihak Ketiga Fixture';
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/proofs', $data)->assertOk();
        $data['idempotency_key'] = (string) Str::uuid();
        $data['expected_version'] = $part->fresh()->version;
        $this->postJson('/api/v1/payout-parts/'.$part->id.'/proofs', $data)->assertUnprocessable();
        $this->assertSame(70000, $this->ledger->wallet($this->sender)['held']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_transfer_pin_validation_self_and_replay_history_both_sides(): void
    {
        Passport::actingAs($this->sender);
        $data = $this->transferData(40000);
        $this->postJson('/api/v1/transfers', [...$data, 'pin' => str_repeat('0', 6)])->assertUnprocessable();
        $this->postJson('/api/v1/transfers', [...$data, 'recipient_identity_id' => MemberIdentity::forUser($this->sender)->id])->assertUnprocessable();
        $response = $this->postJson('/api/v1/transfers', $data)->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $this->postJson('/api/v1/transfers', $data)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
        $this->postJson('/api/v1/transfers', [...$data, 'amount' => 40001])->assertConflict();
        $this->getJson('/api/v1/transfers')->assertOk()->assertJsonPath('data.items.0.direction', 'out');
        Passport::actingAs($this->recipient);
        $this->getJson('/api/v1/transfers')->assertOk()->assertJsonPath('data.items.0.direction', 'in');
        $this->assertSame(60000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(40000, $this->ledger->wallet($this->recipient)['available']);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        $this->assertStringNotContainsString($this->fixturePin, json_encode(DB::table('member_transfers')->first()));
    }

    public function test_inactive_transfer_rejected_and_own_payout_stays_available(): void
    {
        CommunityMember::query()->where('user_id', $this->sender->id)->update(['status' => 'archived']);
        Passport::actingAs($this->sender);
        $this->postJson('/api/v1/transfers', $this->transferData())->assertStatus(402);
        $this->postJson('/api/v1/payouts', $this->payoutData())->assertCreated();
        $this->getJson('/api/v1/bank-accounts/primary')->assertOk();
    }

    public function test_proof_upload_rejects_non_raster_and_oversize_without_public_asset(): void
    {
        Passport::actingAs($this->founder);
        $this->postJson('/api/v1/assets/upload', ['type' => 'payout-proof', 'file' => UploadedFile::fake()->create('bad.pdf', 20, 'application/pdf')])->assertUnprocessable();
        $this->postJson('/api/v1/assets/upload', ['type' => 'payout-proof', 'file' => UploadedFile::fake()->image('too-large.png')->size(5121)])->assertUnprocessable();
    }

    public function test_pin_limit_is_actor_scoped_across_idempotency_keys(): void
    {
        Passport::actingAs($this->sender);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/transfers', [...$this->transferData(), 'pin' => str_repeat('0', 6)])->assertUnprocessable();
        }
        $this->postJson('/api/v1/transfers', $this->transferData())->assertStatus(429);
    }

    public function test_status_history_whatsapp_contacts_and_revoked_proof_access(): void
    {
        $this->founder->update(['phone' => '081'.random_int(100000000, 999999999)]);
        $this->sender->update(['phone' => '082'.random_int(100000000, 999999999)]);
        $payout = $this->payout();
        $part = $this->part($payout);
        $fragment = $this->fragment($part, 20000, $this->admin);
        Passport::actingAs($this->sender);
        $detail = $this->getJson('/api/v1/payouts/'.$payout->id)->assertOk()->assertJsonPath('data.member_contact.available', true)->assertJsonPath('data.parts.0.founder_contact.available', true)->assertJsonPath('data.parts.0.source_account.account_number', null);
        $this->assertNotNull($detail->json('data.parts.0.fragments.0.proof_url'));
        $this->assertNotSame($detail->json('data.member_contact.phone'), $detail->json('data.parts.0.founder_contact.phone'));
        Passport::actingAs($this->admin);
        $this->getJson('/api/v1/payouts/'.$payout->id)->assertOk();
        CommunityMember::query()->where('user_id', $this->admin->id)->update(['role' => CommunityMemberRole::Member->value]);
        $this->getJson('/api/v1/payouts/'.$payout->id)->assertForbidden();
    }

    public function test_routes_require_auth_and_migration_replay_preserves_existing_money(): void
    {
        $this->getJson('/api/v1/bank-accounts/primary')->assertUnauthorized();
        $this->getJson('/api/v1/payouts')->assertUnauthorized();
        $this->getJson('/api/v1/transfers')->assertUnauthorized();
        $payout = $this->payout();
        $before = $this->moneyState();
        $migration = require database_path('migrations/2026_11_26_060001_create_money_movements.php');
        $migration->up();
        $migration->up();
        $this->assertSame($before, $this->moneyState());
        $this->getJson('/api/v1/finance/options')->assertOk()->assertJsonPath('data.payout_ready', true)->assertJsonPath('data.payout_mode', 'manual')->assertJsonPath('data.payment_gateway', false);
        DB::table('migrations')->where('migration', '2026_11_26_060001_create_money_movements')->delete();
        $this->getJson('/api/v1/finance/options')->assertOk()->assertJsonPath('data.payout_ready', false);
    }

    public function test_manager_queue_and_detail_only_expose_assigned_source_status_and_amounts(): void
    {
        $otherFounder = User::factory()->create();
        $other = Community::factory()->approved()->for($otherFounder, 'owner')->create(['dues_bank_name' => 'Bank Lain Fixture', 'dues_account_number' => '000'.random_int(1000000, 9999999), 'dues_account_name' => 'Kas Lain Fixture']);
        CommunityMember::factory()->for($other)->for($this->sender)->create();
        $this->fund($this->sender, $other, $otherFounder, 50000);
        $payout = $this->payout(120000);
        $part = PayoutPart::query()->where('payout_id', $payout->id)->where('community_id', $this->community->id)->firstOrFail();
        $fragment = $this->fragment($part, 100000);
        $this->movement->verifyFragment($this->founder, $part->fresh(), $fragment, ['expected_version' => $part->fresh()->version, 'idempotency_key' => (string) Str::uuid()]);
        Passport::actingAs($this->founder);
        $this->getJson('/api/v1/payouts?scope=manage&community_id='.$this->community->id.'&status=succeeded')->assertOk()->assertJsonPath('data.items.0.status', 'succeeded')->assertJsonPath('data.items.0.amount', 100000);
        $this->getJson('/api/v1/payouts?scope=manage&community_id='.$this->community->id.'&status=requested')->assertOk()->assertJsonCount(0, 'data.items');
        Passport::actingAs($otherFounder);
        $this->getJson('/api/v1/payouts/'.$payout->id)->assertOk()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.amount', 20000)->assertJsonCount(1, 'data.parts');
        $this->getJson('/api/v1/communities/'.$other->id.'/payout-reconciliation')->assertOk()->assertJsonPath('data.held_amount', 20000)->assertJsonPath('data.balanced', true);
        DB::table('ledger_accounts')->where('account_key', 'identity:'.MemberIdentity::forUser($this->sender)->id.':held')->increment('balance');
        $this->getJson('/api/v1/communities/'.$other->id.'/payout-reconciliation')->assertOk()->assertJsonPath('data.balanced', false);
    }

    /** @return array<string,mixed> */
    private function action(PayoutPart $part): array
    {
        return ['expected_version' => $part->version, 'note' => 'Skenario sintetis tanpa transaksi nyata.', 'idempotency_key' => (string) Str::uuid()];
    }
}
