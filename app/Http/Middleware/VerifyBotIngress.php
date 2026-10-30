<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
final class VerifyBotIngress {
 public function handle(Request $request,Closure $next) {
  if($request->is('api/telegram-webhook')) {
   $secret=(string)config('services.telegram.webhook_secret','');
   $provided=(string)$request->header('X-Telegram-Bot-Api-Secret-Token','');
   if($secret===''||!hash_equals($secret,$provided))abort(403,'unauthorized');
  }
  if($request->is('api/runDefaultValueForNewProject','api/setWebhook','api/deleteWebhook','api/export','api/send-bulk-message','api/importUsers')) {
   $secret=(string)config('services.wp_sync.secret','');
   if(!$request->isMethod('POST')||$secret===''||!hash_equals($secret,(string)$request->header('X-IPSABET-API-KEY','')))abort(403,'unauthorized');
  }
  return $next($request);
 }
}
