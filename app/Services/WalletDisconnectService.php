<?php
namespace App\Services;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
/** Durable transfer: prepare removes site access atomically with moving funds into escrow.
 * A lost response is recovered with the same ID, never a compensating credit. */
final class WalletDisconnectService {
 public static function pending(int $userId): bool {
  return Schema::hasTable('wallet_disconnects') && DB::table('wallet_disconnects')->where('user_id',$userId)->where('state','prepared')->exists();
 }
 public function run(array $p): array {
  if (!Schema::hasTable('wallet_disconnects')) abort(503,'disconnect_migration_required');
  return DB::transaction(function() use($p) {
   $user=User::where('tel_id',$p['tel_id'])->lockForUpdate()->firstOrFail();
   $old=DB::table('wallet_disconnects')->where('id',$p['id'])->first();
   if($old) {
    foreach(['user_id'=>$user->id,'site_user_id'=>$p['site_user_id'],'phone'=>$p['phone'],'destination'=>$p['destination']] as $k=>$v) {
     if((string)$old->$k !== (string)$v) throw ValidationException::withMessages(['transfer'=>'transfer_conflict']);
    }
    if($p['phase']==='commit' && $old->state==='prepared') {
     DB::table('wallet_disconnects')->where('id',$p['id'])->update(['state'=>'completed','updated_at'=>now()]);
     $old->state='completed';
    }
    return ['ok'=>true,'id'=>$old->id,'amount'=>(int)$old->amount,'destination'=>$old->destination,'state'=>$old->state];
   }
   if($p['phase']!=='prepare' || self::pending($user->id)) abort(409,'transfer_not_ready');
   $link=DB::table('wp_sync_links')->where('user_id',$user->id)->where('tel_id',$p['tel_id'])->where('site_user_id',$p['site_user_id'])->where('phone',$p['phone'])->lockForUpdate()->first();
   if(!$link) abort(409,'account_not_linked');
   $amount=(int)$user->balance;
   if($amount<0) abort(409,'invalid_balance');
   DB::table('wallet_disconnects')->insert(['id'=>$p['id'],'user_id'=>$user->id,'site_user_id'=>$p['site_user_id'],'tel_id'=>$p['tel_id'],'phone'=>$p['phone'],'destination'=>$p['destination'],'amount'=>$amount,'state'=>'prepared','created_at'=>now(),'updated_at'=>now()]);
   if($p['destination']==='site' && $amount>0) {
    $user->balance=0; $user->save();
    Payment::create(['user_id'=>$user->id,'order_id'=>0,'method'=>'wallet','ref_id'=>'DISCONNECT-'.$p['id'],'type'=>'wp_order','price'=>$amount,'status'=>1,'expired_at'=>now(),'detail'=>['purpose'=>'wallet_disconnect','destination'=>'site','wallet_balance_before'=>$amount,'wallet_balance_after'=>0]]);
   }
   DB::table('wp_sync_links')->where('id',$link->id)->delete();
   return ['ok'=>true,'id'=>$p['id'],'amount'=>$amount,'destination'=>$p['destination'],'state'=>'prepared'];
  },3);
 }
}
