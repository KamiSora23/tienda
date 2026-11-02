import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("application root renders the ERP client", async () => {
  const page = await readFile(new URL("../app/page.tsx", import.meta.url), "utf8");
  assert.match(page, /DeurstoreApp/);
  assert.doesNotMatch(page, /_sites-preview|SkeletonPreview/);
});

test("root layout exposes production ERP metadata", async () => {
  const layout = await readFile(new URL("../app/layout.tsx", import.meta.url), "utf8");
  assert.match(layout, /Deurstore ERP/);
  assert.match(layout, /ERP comercial/);
  assert.doesNotMatch(layout, /codex-preview|Starter Project|_sites-preview/);
});
