<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void{
        Schema::create('scheduled_reports',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('user_id')->constrained();$t->string('name');$t->string('report_type');$t->json('filters')->nullable();$t->enum('format',['csv','pdf'])->default('csv');$t->string('frequency')->default('monthly');$t->string('recipients')->nullable();$t->timestamp('next_run_at')->nullable();$t->boolean('active')->default(true);$t->timestamps();});
        Schema::create('backup_records',function(Blueprint $t){$t->id();$t->foreignId('company_id')->nullable()->constrained();$t->foreignId('user_id')->nullable()->constrained();$t->string('filename');$t->string('path');$t->unsignedBigInteger('size')->default(0);$t->enum('type',['manual','automatic'])->default('manual');$t->string('status')->default('completed');$t->text('error')->nullable();$t->timestamps();});
    }
    public function down():void{Schema::dropIfExists('backup_records');Schema::dropIfExists('scheduled_reports');}
};
