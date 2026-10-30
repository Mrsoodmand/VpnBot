<?php
namespace Tests\Feature;
use App\Http\Controllers\Api\TelegramBotController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class TelegramUpdateIngressTest extends TestCase {
 public function test_unsupported_updates_acknowledge_without_database_or_telegram_work(): void {
  foreach ([[], ['update_id'=>1,'channel_post'=>['chat'=>['id'=>-99]]], ['callback_query'=>['id'=>'inline','from'=>['id'=>123]]]] as $payload) {
   $controller=new TelegramBotController(Request::create('/api/telegram-webhook','POST',$payload));
   $this->assertSame(200,$controller->index()->getStatusCode());
  }
 }
 public function test_unblocking_is_processed_even_when_account_was_marked_blocked_and_ids_bind_as_strings(): void {
  $_ENV['TELEGRAM_BOT_TOKEN']='test-token';$_SERVER['TELEGRAM_BOT_TOKEN']='test-token';putenv('TELEGRAM_BOT_TOKEN=test-token');
  Schema::create('telegram_data',function(Blueprint $t){$t->id();$t->text('data');$t->string('path');$t->timestamps();});
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('tel_id');$t->integer('status');$t->string('path');$t->integer('is_admin')->default(0);$t->integer('is_seller')->default(0);$t->timestamps();});
  DB::table('users')->insert(['tel_id'=>'8582277471','status'=>-2,'path'=>'start']);
  $bindings=[];DB::listen(function($q) use (&$bindings){if(str_contains($q->sql,'"tel_id" ='))$bindings[]=$q->bindings;});
  $controller=new TelegramBotController(Request::create('/api/telegram-webhook','POST',['update_id'=>2,'my_chat_member'=>['chat'=>['id'=>8582277471],'old_chat_member'=>['status'=>'kicked'],'new_chat_member'=>['status'=>'member']]]));
  $controller->index();
  $this->assertSame(1,(int)DB::table('users')->value('status'));
  $this->assertNotEmpty($bindings);
  foreach($bindings as $b)$this->assertContains('8582277471',$b,true);
 }
}
