<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{Schema::create('backup_schedules',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('user_id')->constrained();$t->enum('frequency',['daily','weekly','monthly']);$t->time('run_at')->default('02:00');$t->unsignedInteger('retention_days')->default(30);$t->boolean('active')->default(true);$t->timestamp('last_run_at')->nullable();$t->timestamp('next_run_at')->nullable();$t->timestamps();});}
 public function down():void{Schema::dropIfExists('backup_schedules');}
};
