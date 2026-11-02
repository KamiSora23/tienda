<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{Schema::table('companies',function(Blueprint$t){$t->longText('logo')->nullable();$t->string('locale',10)->default('es_PE');$t->string('date_format',20)->default('d/m/Y');$t->string('tax_regime')->nullable();});}public function down():void{Schema::table('companies',fn(Blueprint$t)=>$t->dropColumn(['logo','locale','date_format','tax_regime']));}};
