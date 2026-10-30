<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  foreach(['phone','site_user_id','user_id'] as $column) {
   if(DB::table('wp_sync_links')->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->exists())throw new RuntimeException('Conflicting account links require manual reconciliation before migration.');
  }
  Schema::table('wp_sync_links',function(Blueprint $t){$t->unique('phone');$t->unique('site_user_id');$t->unique('user_id');});
 }
 public function down(): void {Schema::table('wp_sync_links',function(Blueprint $t){$t->dropUnique(['phone']);$t->dropUnique(['site_user_id']);$t->dropUnique(['user_id']);});}
};
