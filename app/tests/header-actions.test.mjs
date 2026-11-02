import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

test("top bar wires search, help and notifications to interactive state", async () => {
  const source = await readFile(new URL("../app/DeurstoreApp.tsx", import.meta.url), "utf8");
  assert.match(source, /globalSearch/);
  assert.match(source, /\/global-search\?q=/);
  assert.match(source, /setHelpOpen\(true\)/);
  assert.match(source, /setActive\("Notificaciones"\)/);
  assert.match(source, /aria-label="Búsqueda global"/);
});
