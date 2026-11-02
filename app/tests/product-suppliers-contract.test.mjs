import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source=()=>fs.readFileSync(new URL('../app/OperationalModules.tsx',import.meta.url),'utf8');

test('purchases prioritize associated products without hiding the rest',()=>{
 const module=source();
 assert.match(module,/selectedSupplier/);
 assert.match(module,/supplier_product/);
 assert.match(module,/associatedProductsFirst/);
 assert.match(module,/Proveedor habitual/);
});

test('product detail manages supplier associations',()=>{
 const module=source();
 assert.match(module,/Proveedores del producto/);
 assert.match(module,/\/suppliers\//);
 assert.match(module,/Proveedor principal/);
 assert.match(module,/minimum_order_quantity/);
});
