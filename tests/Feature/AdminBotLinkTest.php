<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\{DB,Schema,Http,Cache};
use Illuminate\Database\Schema\Blueprint;
class AdminBotLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wp_sync.secret'=>'test-secret','services.wp_sync.base_url'=>'https://site.example.test','cache.default'=>'array']);
        Schema::create('users',function(Blueprint $t){$t->id();$t->string('tel_id');$t->integer('status')->default(1);$t->bigInteger('balance')->default(0);$t->timestamps();});
        Schema::create('wp_sync_links',function(Blueprint $t){$t->id();$t->integer('user_id');$t->string('tel_id')->unique();$t->string('phone')->unique();$t->integer('site_user_id')->unique();$t->timestamp('linked_at')->nullable();$t->timestamps();});
        (require database_path('migrations/2026_10_30_210000_create_wallet_disconnects_table.php'))->up();
        User::create(['tel_id'=>'12345','balance'=>150000]);
        Http::preventStrayRequests();
    }
    private function payload(): array { return ['code'=>str_repeat('a',20),'tel_id'=>'12345','phone'=>'+989123456789','site_user_id'=>432]; }
    private function send(array $changes=[]) { return $this->withHeader('X-IPSABET-API-KEY','test-secret')->postJson('/api/wp-sync/link/connect',array_merge($this->payload(),$changes)); }
    private function site(array $changes=[]): array { return array_merge(['ok'=>true,'phone'=>'+989123456789','site_user_id'=>432,'orders'=>[]],$changes); }
    public function test_missing_secret_is_rejected_before_network(): void
    { $this->postJson('/api/wp-sync/link/connect',$this->payload())->assertStatus(403); Http::assertNothingSent(); }
    public function test_invalid_identity_and_missing_user_are_rejected(): void
    {
        foreach([['code'=>'bad'],['tel_id'=>'0'],['phone'=>'bad'],['site_user_id'=>0]] as $p)$this->send($p)->assertStatus(422);
        $this->send(['tel_id'=>'999'])->assertStatus(404);Http::assertNothingSent();
    }
    public function test_inactive_account_is_rejected(): void
    { DB::table('users')->update(['status'=>-2]);$this->send()->assertJson(['ok'=>false]);Http::assertNothingSent(); }
    public function test_existing_other_link_is_rejected_before_site_mutation(): void
    {
        DB::table('wp_sync_links')->insert(['user_id'=>1,'tel_id'=>'12345','phone'=>'+989111111111','site_user_id'=>99]);
        $this->send()->assertJson(['ok'=>false]);Http::assertNothingSent();
    }
    public function test_successful_connect_and_retry_preserve_one_link_and_balance(): void
    {
        Http::fake(['site.example.test/*'=>Http::response($this->site())]);
        $this->send()->assertJson(['ok'=>true]);$this->send()->assertJson(['ok'=>true]);
        $this->assertSame(1,DB::table('wp_sync_links')->count());$this->assertSame(150000,(int)User::find(1)->balance);
        Http::assertSent(fn($r)=>$r['expected']['site_user_id']===432 && $r['expected']['phone']==='+989123456789' && ($r->data()['phase']??'')==='finalize');
    }
    public function test_mismatched_response_never_creates_local_link(): void
    {
        Http::fake(['site.example.test/*'=>Http::response($this->site(['site_user_id'=>99]))]);
        $this->send()->assertJson(['ok'=>false]);$this->assertSame(0,DB::table('wp_sync_links')->count());Http::assertSentCount(1);
    }
    public function test_failed_finalize_can_resume_without_duplicate_link(): void
    {
        Http::fake(['site.example.test/*'=>Http::sequence()->push($this->site())->push(['ok'=>false],503)->push($this->site())->push($this->site())]);
        $this->send()->assertJson(['ok'=>false]);$this->assertSame(1,DB::table('wp_sync_links')->count());
        $this->send()->assertJson(['ok'=>true]);$this->assertSame(1,DB::table('wp_sync_links')->count());
    }
    public function test_parallel_confirmation_is_blocked_before_site_request(): void
    {
        $lock=Cache::lock('wp-link-confirm:1',90);$lock->get();
        try{$this->send()->assertJson(['ok'=>false]);Http::assertNothingSent();}finally{$lock->release();}
    }
    public function test_lost_response_releases_lock_and_allows_same_code_retry(): void
    {
        $calls=0;
        Http::fake(['site.example.test/*'=>function() use (&$calls){if(++$calls===1)throw new \Illuminate\Http\Client\ConnectionException('timeout');return Http::response($this->site());}]);
        $this->send()->assertJson(['ok'=>false]);
        $this->send()->assertJson(['ok'=>true]);$this->assertSame(1,DB::table('wp_sync_links')->count());
    }

}
