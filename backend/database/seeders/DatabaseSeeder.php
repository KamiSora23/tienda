<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder {
    public function run(): void {
        $now=now();
        $company=DB::table('companies')->insertGetId(['uuid'=>Str::uuid(),'business_name'=>'DEURSTORE SAC','trade_name'=>'Deurstore','ruc'=>'20601234567','email'=>'admin@deurstore.pe','phone'=>'999999999','address'=>'Av. Grau 1500, Piura','currency'=>'PEN','timezone'=>'America/Lima','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        $branch=DB::table('branches')->insertGetId(['company_id'=>$company,'code'=>'PIU-01','name'=>'Piura Centro','address'=>'Av. Grau 1500, Piura','phone'=>'999999999','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        $warehouse=DB::table('warehouses')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'code'=>'ALM-01','name'=>'Almacén Principal','status'=>'active','created_at'=>$now,'updated_at'=>$now]);DB::table('branches')->where('id',$branch)->update(['default_warehouse_id'=>$warehouse]);
        $user=User::create(['name'=>'Kevin Mendoza','email'=>'admin@deurstore.pe','email_verified_at'=>$now,'password'=>'Deurstore2026!','company_id'=>$company,'branch_id'=>$branch,'role'=>'admin','status'=>'active']);
        $items=[['P000001','775000000001','Arroz Costeño','Abarrotes','kg',1,3.50,5.20,4.00,30,250.5],['P000002','775000000002','Aceite Primor 1L','Abarrotes','unit',0,7.10,9.50,8.00,12,48],['P000003','775000000003','Leche Gloria','Lácteos','unit',0,3.35,4.20,3.70,10,8],['P000004','775000000004','Azúcar rubia','Abarrotes','kg',1,3.25,4.80,3.90,20,72.75],['P000005','775000000005','Gaseosa Inca Kola','Bebidas','unit',0,5.80,7.50,6.50,8,5],['P000006','775000000006','Galletas Soda','Snacks','unit',0,1.25,2.20,1.70,15,64]];
        foreach($items as $x){$pid=DB::table('products')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$company,'sku'=>$x[0],'barcode'=>$x[1],'name'=>$x[2],'category'=>$x[3],'unit_type'=>$x[4],'allow_decimal_quantity'=>$x[5],'average_cost'=>$x[6],'sale_price'=>$x[7],'minimum_price'=>$x[8],'minimum_stock'=>$x[9],'status'=>'active','created_at'=>$now,'updated_at'=>$now]); DB::table('stocks')->insert(['company_id'=>$company,'branch_id'=>$branch,'warehouse_id'=>$warehouse,'product_id'=>$pid,'quantity'=>$x[10],'created_at'=>$now,'updated_at'=>$now]);}
        DB::table('customers')->insert([['company_id'=>$company,'document_type'=>'DNI','document_number'=>'45879632','name'=>'Juan Pérez','phone'=>'987654321','status'=>'active','created_at'=>$now,'updated_at'=>$now],['company_id'=>$company,'document_type'=>'DNI','document_number'=>'71245698','name'=>'María Torres','phone'=>'955212418','status'=>'active','created_at'=>$now,'updated_at'=>$now]]);
        DB::table('suppliers')->insert([['company_id'=>$company,'document_type'=>'RUC','document_number'=>'20481234567','name'=>'Distribuidora Norte','contact'=>'Ana Ruiz','phone'=>'973100200','status'=>'active','created_at'=>$now,'updated_at'=>$now],['company_id'=>$company,'document_type'=>'RUC','document_number'=>'20569874123','name'=>'Comercial Piura','contact'=>'Luis Vega','phone'=>'964111222','status'=>'active','created_at'=>$now,'updated_at'=>$now]]);
        $cash=DB::table('cash_sessions')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'user_id'=>$user->id,'opening_amount'=>100,'status'=>'open','opened_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        DB::table('cash_movements')->insert(['cash_session_id'=>$cash,'type'=>'opening','amount'=>100,'description'=>'Apertura inicial','user_id'=>$user->id,'created_at'=>$now,'updated_at'=>$now]);
        \App\Support\PermissionCatalog::ensure();
        $role=DB::table('roles')->insertGetId(['company_id'=>$company,'name'=>'Administrador','slug'=>'admin','description'=>'Control de la empresa','system'=>1,'created_at'=>$now,'updated_at'=>$now]);
        DB::table('role_user')->insert(['role_id'=>$role,'user_id'=>$user->id]);
        foreach(DB::table('permissions')->where('slug','!=','sales.override_price')->pluck('id') as $permission)DB::table('permission_role')->insert(['role_id'=>$role,'permission_id'=>$permission]);
        foreach([['Abarrotes','abarrotes'],['Bebidas','bebidas'],['Lácteos','lacteos'],['Snacks','snacks']] as $i)DB::table('categories')->insert(['company_id'=>$company,'name'=>$i[0],'slug'=>$i[1],'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        (new \App\Services\UnitCatalog())->ensureFor($company);
        foreach([['ticket','T001'],['receipt','B001'],['invoice','F001'],['credit_note','NC01'],['debit_note','ND01'],['purchase','C001']] as $series)DB::table('document_series')->insert(['company_id'=>$company,'branch_id'=>$branch,'document_type'=>$series[0],'series'=>$series[1],'current_number'=>0,'active'=>1,'created_at'=>$now,'updated_at'=>$now]);
        foreach([['Efectivo','cash',0,1],['Yape','yape',1,0],['Tarjeta','card',1,0],['Transferencia','transfer',1,0]] as$m)DB::table('payment_methods')->insert(['company_id'=>$company,'name'=>$m[0],'code'=>$m[1],'requires_reference'=>$m[2],'affects_cash'=>$m[3],'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        DB::table('tax_rates')->insert(['company_id'=>$company,'name'=>'IGV','code'=>'IGV18','rate'=>18,'included'=>1,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        DB::table('cash_registers')->insert(['company_id'=>$company,'branch_id'=>$branch,'code'=>'CAJA-01','name'=>'Caja Principal','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        // El stock sembrado entra al kardex como saldo inicial.
        (new \App\Services\KardexReconciler())->backfillInitialStock();
    }
}
