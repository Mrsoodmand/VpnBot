<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\WpSyncService;
use App\Services\WalletDisconnectService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
class WpWalletSafetyTest extends TestCase {
 protected function setUp(): void {
  parent::setUp();
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('tel_id');$t->bigInteger('balance')->default(0);$t->timestamps();});
  Schema::create('payments',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->integer('order_id')->default(0);$t->integer('admin_id')->nullable();$t->string('method');$t->string('ref_id')->nullable();$t->string('type');$t->bigInteger('price');$t->integer('status');$t->text('detail')->nullable();$t->timestamp('expired_at')->nullable();$t->timestamps();});
  Schema::create('wp_sync_links',function(Blueprint $t){$t->id();$t->integer('user_id');$t->string('tel_id')->unique();$t->string('phone')->unique();$t->integer('site_user_id')->unique();$t->timestamp('linked_at')->nullable();$t->timestamps();});
  (require database_path('migrations/2026_10_30_210000_create_wallet_disconnects_table.php'))->up();
  User::create(['tel_id'=>'12345','balance'=>150000]);
  DB::table('wp_sync_links')->insert(['user_id'=>1,'tel_id'=>'12345','phone'=>'+989123456789','site_user_id'=>432]);
 }
 public function test_webhook_and_operational_routes_reject_unauthenticated_requests_before_processing(): void {
  config(['services.telegram.webhook_secret'=>'test-secret']);
  $this->postJson('/api/telegram-webhook',['message'=>['text'=>'/connectAccount 123456']])->assertStatus(403);
  $this->withHeader('X-Telegram-Bot-Api-Secret-Token','wrong')->postJson('/api/telegram-webhook',[])->assertStatus(403);
  foreach(['setWebhook','deleteWebhook','export','send-bulk-message','importUsers','runDefaultValueForNewProject'] as $path)$this->postJson('/api/'.$path,[])->assertStatus(403);
 }
 public function test_old_json_only_payment_reference_is_not_debited_again(): void {
  DB::table('payments')->insert(['user_id'=>1,'order_id'=>0,'method'=>'wallet','type'=>'wp_order','price'=>50000,'status'=>1,'detail'=>json_encode(['order_code'=>'old-order'])]);
  $r=(new WpSyncService)->debitWallet(User::find(1),50000,['tel_id'=>'12345','order_code'=>'old-order']);
  $this->assertTrue($r['idempotent']);$this->assertSame(150000,(int)User::find(1)->balance);
 }
 public function test_purchase_before_disconnect_transfers_only_remaining_funds(): void {
  (new WpSyncService)->debitWallet(User::find(1),50000,['tel_id'=>'12345','order_code'=>'purchase']);
  $r=(new WalletDisconnectService)->run($this->payload());$this->assertSame(100000,$r['amount']);$this->assertSame(0,(int)User::find(1)->balance);
 }
 private function payload(string $destination='site'): array {return ['id'=>str_repeat('a',32),'tel_id'=>'12345','phone'=>'+989123456789','site_user_id'=>432,'destination'=>$destination,'phase'=>'prepare'];}
 public function test_debit_order_reference_is_exactly_once(): void {
  $s=new WpSyncService;$u=User::find(1);$p=['tel_id'=>'12345','order_code'=>'IPS-123'];
  $this->assertTrue($s->debitWallet($u,50000,$p)['ok']);$this->assertTrue($s->debitWallet($u,50000,$p)['idempotent']);
  $this->assertSame(100000,(int)$u->fresh()->balance);$this->assertSame(1,DB::table('payments')->count());
  $this->expectException(ValidationException::class);$s->debitWallet($u,40000,$p);
 }
 public function test_credit_receipt_reference_is_exactly_once(): void {
  $s=new WpSyncService;$u=User::find(1);$p=['tel_id'=>'12345','receipt_id'=>17];
  $s->creditWallet($u,50000,$p);$s->creditWallet($u,50000,$p);
  $this->assertSame(200000,(int)$u->fresh()->balance);$this->assertSame(1,DB::table('payments')->count());
 }
 public function test_site_destination_escrows_once_and_replays_lost_response(): void {
  $s=new WalletDisconnectService;$p=$this->payload();$first=$s->run($p);
  $this->assertSame(150000,$first['amount']);$this->assertSame(0,(int)User::find(1)->balance);
  $this->assertSame(0,DB::table('wp_sync_links')->count());$this->assertSame($first,$s->run($p));
  $this->assertSame(1,DB::table('payments')->count());$this->assertTrue($s::pending(1));
  $p['phase']='commit';$this->assertSame('completed',$s->run($p)['state']);$this->assertFalse($s::pending(1));
  $this->assertSame(150000,$s->run($p)['amount']);
 }
 public function test_bot_destination_keeps_only_bot_balance(): void {
  $r=(new WalletDisconnectService)->run($this->payload('bot'));
  $this->assertSame(150000,$r['amount']);$this->assertSame(150000,(int)User::find(1)->balance);$this->assertSame(0,DB::table('payments')->count());
 }
 public function test_conflicting_transfer_replay_is_rejected(): void {
  $s=new WalletDisconnectService;$s->run($this->payload());$this->expectException(ValidationException::class);$s->run($this->payload('bot'));
 }
 public function test_stale_site_debit_after_disconnect_is_rejected(): void {
  $user=User::find(1);(new WalletDisconnectService)->run($this->payload('bot'));
  try{(new WpSyncService)->debitWallet($user,10000,['tel_id'=>'12345','order_code'=>'late']);$this->fail('Stale debit accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
  $this->assertSame(150000,(int)$user->fresh()->balance);
 }
 public function test_conflicting_identity_never_falls_back_to_another_account(): void {
  User::create(['tel_id'=>'99999','balance'=>0]);$s=new WpSyncService;
  $this->assertNull($s->resolveUser(['tel_id'=>'99999','phone'=>'+989123456789']));
  $this->assertNull($s->resolveUser(['tel_id'=>'12345','phone'=>'']));
  $this->assertTrue($s->hasUserScope(['phone'=>'']));
 }
 public function test_unresolved_scoped_orders_and_payments_fail_closed(): void {
  config(['services.wp_sync.secret'=>'test-key']);
  foreach(['orders','payments'] as $resource)$this->withHeader('X-IPSABET-API-KEY','test-key')->postJson('/api/wp-sync/'.$resource.'/list',['phone'=>'invalid'])->assertStatus(404);
 }
 public function test_relink_is_blocked_until_both_sides_commit(): void {
  (new WalletDisconnectService)->run($this->payload());
  $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
  (new WpSyncService)->upsertLink(User::find(1),'+989123456789',432);
 }
}
