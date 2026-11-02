<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{Schema::create('product_relations',function(Blueprint$t){$t->foreignId('product_id')->constrained()->cascadeOnDelete();$t->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();$t->enum('type',['related','similar']);$t->timestamps();$t->primary(['product_id','related_product_id','type']);});}public function down():void{Schema::dropIfExists('product_relations');}};
