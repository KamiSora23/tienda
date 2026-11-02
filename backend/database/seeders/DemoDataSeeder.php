<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $company = DB::table('companies')->where('status', 'active')->orderBy('id')->first();
        if (!$company) {
            $this->command?->warn('No existe una empresa activa. Ejecuta primero DatabaseSeeder.');
            return;
        }

        if (DB::table('sales')->where('company_id', $company->id)->where('number', 'like', 'DEMO-V-%')->exists()) {
            $this->command?->info('Los datos de demostración ya estaban cargados.');
            return;
        }

        DB::transaction(function () use ($company) {
            $now = now();
            $branch = DB::table('branches')->where(['company_id' => $company->id, 'status' => 'active'])->first();
            $warehouse = DB::table('warehouses')->where(['company_id' => $company->id, 'status' => 'active'])->first();
            $user = DB::table('users')->where(['company_id' => $company->id, 'status' => 'active'])->first();
            $products = DB::table('products')->where(['company_id' => $company->id, 'status' => 'active'])->orderBy('id')->get();

            foreach ([
                ['DNI', '73451290', 'Carlos Ramírez', '968741230', 'carlos@example.test', 'Urb. Miraflores, Piura'],
                ['DNI', '60789421', 'Rosa Castillo', '947520186', 'rosa@example.test', 'Castilla, Piura'],
                ['RUC', '10458796321', 'Bodega San Miguel', '932147850', 'ventas@sanmiguel.test', 'Av. Sánchez Cerro, Piura'],
                ['DNI', '48123697', 'José Silva', '975318642', 'jose@example.test', 'Veintiséis de Octubre, Piura'],
                ['RUC', '20607894512', 'Servicios del Norte SAC', '916284735', 'contacto@norte.test', 'Zona Industrial, Piura'],
            ] as $customer) {
                DB::table('customers')->updateOrInsert(
                    ['company_id' => $company->id, 'document_number' => $customer[1]],
                    ['document_type' => $customer[0], 'name' => $customer[2], 'phone' => $customer[3], 'email' => $customer[4], 'address' => $customer[5], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
                );
            }

            foreach ([
                ['20604567891', 'Mayorista Grau SAC', 'Lucía Flores', '946111222'],
                ['20501478963', 'Alimentos del Pacífico', 'Marco León', '973444555'],
                ['20405632147', 'Bebidas Regionales SRL', 'Andrea Paz', '989333444'],
            ] as $supplier) {
                DB::table('suppliers')->updateOrInsert(
                    ['company_id' => $company->id, 'document_number' => $supplier[0]],
                    ['document_type' => 'RUC', 'name' => $supplier[1], 'contact' => $supplier[2], 'phone' => $supplier[3], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
                );
            }

            foreach (['Costeño', 'Primor', 'Gloria', 'Inca Kola', 'San Jorge'] as $brand) {
                DB::table('brands')->updateOrInsert(['company_id' => $company->id, 'name' => $brand], ['logo' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            }

            $customers = DB::table('customers')->where('company_id', $company->id)->orderBy('id')->get();
            $suppliers = DB::table('suppliers')->where('company_id', $company->id)->orderBy('id')->get();
            foreach ($products->take(4) as $productIndex => $product) {
                foreach ($suppliers->take($productIndex < 2 ? 2 : 1) as $supplierIndex => $supplier) {
                    DB::table('product_suppliers')->updateOrInsert(
                        ['company_id'=>$company->id,'product_id'=>$product->id,'supplier_id'=>$supplier->id],
                        ['supplier_sku'=>'PRV-'.$supplier->id.'-'.$product->sku,'last_cost'=>$product->average_cost,'last_purchase_at'=>$now->copy()->subDays(5+$supplierIndex),'minimum_order_quantity'=>$product->unit_type==='kg'?10:6,'lead_time_days'=>2+$supplierIndex,'is_primary'=>$supplierIndex===0,'status'=>'active','created_at'=>$now,'updated_at'=>$now]
                    );
                }
            }
            $cash = DB::table('cash_sessions')->where(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'open'])->first();

            for ($day = 34; $day >= 0; $day--) {
                $salesToday = 1 + ($day % 3);
                for ($turn = 0; $turn < $salesToday; $turn++) {
                    $index = ($day + $turn) % max(1, $products->count());
                    $product = $products[$index];
                    $quantity = $product->unit_type === 'kg' ? 1.5 + (($day % 4) * .25) : 1 + (($day + $turn) % 4);
                    $subtotal = round($quantity * $product->sale_price, 2);
                    $discount = $day % 9 === 0 ? round($subtotal * .05, 2) : 0;
                    $total = $subtotal - $discount;
                    $date = $now->copy()->subDays($day)->setTime(9 + ($turn * 3), 15);
                    $number = 'DEMO-V-' . str_pad((string)(1000 + (($day * 3) + $turn)), 6, '0', STR_PAD_LEFT);
                    $saleId = DB::table('sales')->insertGetId([
                        'uuid' => Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
                        'customer_id' => $customers[($day + $turn) % $customers->count()]->id, 'user_id' => $user->id,
                        'number' => $number, 'document_type' => $day % 5 === 0 ? 'receipt' : 'ticket',
                        'subtotal' => $subtotal, 'discount' => $discount, 'tax' => round($total * 18 / 118, 2),
                        'total' => $total, 'status' => 'paid', 'created_at' => $date, 'updated_at' => $date,
                    ]);
                    DB::table('sale_details')->insert([
                        'sale_id' => $saleId, 'product_id' => $product->id, 'product_name' => $product->name,
                        'quantity' => $quantity, 'unit_price' => $product->sale_price, 'historical_unit_cost' => $product->average_cost,
                        'discount' => $discount, 'total' => $total, 'created_at' => $date, 'updated_at' => $date,
                    ]);
                    $method = ['cash', 'yape', 'card', 'transfer'][($day + $turn) % 4];
                    DB::table('sale_payments')->insert(['sale_id' => $saleId, 'method' => $method, 'amount' => $total, 'reference' => $method === 'cash' ? null : 'OP-' . (80000 + $saleId), 'created_at' => $date, 'updated_at' => $date]);
                    if ($cash && $method === 'cash' && $day < 7) {
                        DB::table('cash_movements')->insert(['cash_session_id' => $cash->id, 'type' => 'sale', 'amount' => $total, 'description' => "Venta demo {$number}", 'reference' => $number, 'user_id' => $user->id, 'created_at' => $date, 'updated_at' => $date]);
                    }
                }
            }

            for ($i = 1; $i <= 8; $i++) {
                $product = $products[$i % $products->count()];
                $quantity = 20 + ($i * 3);
                $subtotal = round($quantity * $product->average_cost, 2);
                $tax = round($subtotal * .18, 2);
                $date = $now->copy()->subDays($i * 5);
                $purchaseId = DB::table('purchases')->insertGetId([
                    'uuid' => Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
                    'supplier_id' => $suppliers[$i % $suppliers->count()]->id, 'user_id' => $user->id,
                    'number' => 'DEMO-C-' . str_pad((string)$i, 5, '0', STR_PAD_LEFT), 'invoice_number' => 'FDEM-' . str_pad((string)$i, 6, '0', STR_PAD_LEFT),
                    'document_type' => 'invoice', 'subtotal' => $subtotal, 'tax' => $tax, 'freight' => $i % 2 ? 12 : 0,
                    'perception' => 0, 'other_costs' => 0, 'total' => $subtotal + $tax + ($i % 2 ? 12 : 0),
                    'status' => $i === 8 ? 'pending' : 'confirmed', 'created_at' => $date, 'updated_at' => $date,
                ]);
                DB::table('purchase_details')->insert(['purchase_id' => $purchaseId, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $product->average_cost, 'effective_unit_cost' => $product->average_cost, 'total' => $subtotal, 'created_at' => $date, 'updated_at' => $date]);
                if ($i <= 6) DB::table('purchase_payments')->insert(['purchase_id' => $purchaseId, 'method' => $i % 2 ? 'transfer' : 'cash', 'amount' => $subtotal + $tax, 'reference' => "PAGO-DEMO-{$i}", 'paid_at' => $date->toDateString(), 'created_at' => $date, 'updated_at' => $date]);
            }

            foreach ([
                ['Servicios', 'Internet y telefonía', 189.90], ['Alquiler', 'Alquiler del local comercial', 1800],
                ['Transporte', 'Movilidad y reparto', 245.50], ['Servicios', 'Energía eléctrica', 410.30],
                ['Mantenimiento', 'Mantenimiento de equipos POS', 160], ['Marketing', 'Publicidad local y redes', 320],
                ['Limpieza', 'Materiales de limpieza', 96.40], ['Administración', 'Útiles de oficina', 128.70],
            ] as $i => $expense) {
                $date = $now->copy()->subDays($i * 3);
                DB::table('expenses')->insert(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'category' => $expense[0], 'description' => $expense[1], 'amount' => $expense[2], 'expense_date' => $date->toDateString(), 'status' => 'active', 'created_at' => $date, 'updated_at' => $date]);
            }

            $promotionId = DB::table('promotions')->insertGetId(['company_id' => $company->id, 'name' => 'Semana Deurstore 10%', 'type' => 'percentage', 'value' => 10, 'rules' => json_encode(['minimum_total' => 30, 'max_uses_per_customer' => 3]), 'starts_at' => $now->copy()->subDays(3), 'ends_at' => $now->copy()->addDays(14), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            foreach ($products->take(3) as $product) DB::table('promotion_product')->insertOrIgnore(['promotion_id' => $promotionId, 'product_id' => $product->id]);

            foreach (['pending', 'preparing', 'packed', 'ready', 'delivered'] as $i => $status) {
                $product = $products[$i % $products->count()];
                $customer = $customers[$i % $customers->count()];
                $date = $now->copy()->subDays(5 - $i);
                $total = round($product->sale_price * ($i + 1), 2);
                $orderId = DB::table('web_orders')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $company->id, 'customer_id' => $customer->id, 'number' => 'DEMO-WEB-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT), 'customer_name' => $customer->name, 'customer_phone' => $customer->phone, 'items' => json_encode([['product_id' => $product->id, 'name' => $product->name, 'quantity' => $i + 1, 'unit_price' => (float)$product->sale_price, 'total' => $total]]), 'total' => $total, 'payment_method' => 'cash_on_delivery', 'payment_status' => $status === 'delivered' ? 'paid' : 'pending', 'status' => $status, 'delivery_address' => $customer->address ?: 'Piura Centro', 'created_at' => $date, 'updated_at' => $date]);
                DB::table('web_order_status_history')->insert(['web_order_id' => $orderId, 'new_status' => $status, 'notes' => 'Estado de demostración', 'created_at' => $date, 'updated_at' => $date]);
            }

            foreach ([
                ['stock', 'Stock crítico detectado', 'Hay productos por debajo del mínimo configurado.', '/'],
                ['orders', 'Nuevo pedido web', 'Tienes pedidos web pendientes de preparación.', '/'],
                ['cash', 'Caja abierta', 'La caja principal continúa abierta para las operaciones del día.', '/'],
                ['security', 'Inicio de sesión administrativo', 'Se registró un acceso correcto al panel.', '/'],
            ] as $i => $notification) {
                DB::table('notifications')->insert(['company_id' => $company->id, 'user_id' => $i === 0 ? null : $user->id, 'type' => $notification[0], 'title' => $notification[1], 'message' => $notification[2], 'link' => $notification[3], 'read_at' => $i === 3 ? $now : null, 'created_at' => $now->copy()->subHours($i + 1), 'updated_at' => $now]);
            }

            foreach (['demo.seeded', 'product.reviewed', 'cash.opened', 'portal.configured', 'promotion.created'] as $i => $event) {
                DB::table('audit_events')->insert(['uuid' => Str::uuid(), 'company_id' => $company->id, 'user_id' => $user->id, 'event' => $event, 'entity' => $i === 4 ? 'promotion' : 'system', 'entity_id' => $i === 4 ? $promotionId : null, 'before' => null, 'after' => json_encode(['demo' => true, 'description' => $event]), 'ip' => '127.0.0.1', 'created_at' => $now->copy()->subHours($i * 4), 'updated_at' => $now]);
            }

            foreach ($products as $i => $product) {
                $stock = DB::table('stocks')->where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->value('quantity') ?? 0;
                DB::table('inventory_movements')->insert(['uuid' => Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'user_id' => $user->id, 'type' => 'demo_opening', 'quantity' => $stock, 'historical_unit_cost' => $product->average_cost, 'previous_stock' => 0, 'new_stock' => $stock, 'document_type' => 'demo', 'document_number' => 'DEMO-STOCK-' . ($i + 1), 'created_at' => $now->copy()->subDays(40), 'updated_at' => $now->copy()->subDays(40)]);
            }

            foreach (['provider' => 'gemini', 'model' => config('services.gemini.model')] as $key => $value) {
                DB::table('settings')->updateOrInsert(['company_id' => $company->id, 'group' => 'ai', 'key' => $key], ['value' => $value, 'type' => 'string', 'created_at' => $now, 'updated_at' => $now]);
            }
        });

        $this->command?->info('Datos de demostración cargados correctamente.');
    }
}
