<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('wallet_disconnects', function(Blueprint $t) {
  $t->string('id',64)->primary(); $t->unsignedBigInteger('user_id')->index();
  $t->unsignedBigInteger('site_user_id'); $t->string('tel_id',64); $t->string('phone',32);
  $t->string('destination',8); $t->unsignedBigInteger('amount');
  $t->string('state',16)->default('prepared'); $t->timestamps();
 }); }
 public function down(): void { Schema::dropIfExists('wallet_disconnects'); }
};
