import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

test('the application shell applies the stored theme',()=>{
 const app=fs.readFileSync(new URL('../app/DeurstoreApp.tsx',import.meta.url),'utf8');
 assert.match(app,/theme-\$\{theme\}/);
 assert.match(app,/(request|load)\("\/admin\/settings"/);
});

test('the product form consumes configured categories instead of a free text field',()=>{
 const module=fs.readFileSync(new URL('../app/OperationalModules.tsx',import.meta.url),'utf8');
 assert.match(module,/api\("\/admin\/categories"/);
 assert.match(module,/name="category"[^>]*>.*categories\.map/s);
});

test('the product form consumes configured brands and supports generic products',()=>{
 const module=fs.readFileSync(new URL('../app/OperationalModules.tsx',import.meta.url),'utf8');
 assert.match(module,/api\("\/admin\/brands"/);
 assert.match(module,/name="brand"[^>]*>.*brands\.map/s);
 assert.match(module,/Sin marca \/ Genérico/);
 assert.match(module,/api\("\/admin\/brands"[^\n]*method:"POST"/);
});

test('inventory loads catalogs and exposes combined product filters',()=>{
 const module=fs.readFileSync(new URL('../app/OperationalModules.tsx',import.meta.url),'utf8');
 assert.match(module,/inventory-search/);
 assert.match(module,/inventory-category/);
 assert.match(module,/inventory-brand/);
 assert.match(module,/inventory-warehouse/);
 assert.match(module,/api\("\/admin\/categories"/);
 assert.match(module,/api\("\/admin\/brands"/);
});
