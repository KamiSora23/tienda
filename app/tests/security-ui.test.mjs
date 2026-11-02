import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

test("login does not prefill demo administrative credentials", async () => {
  const source = await readFile(new URL("../app/DeurstoreApp.tsx", import.meta.url), "utf8");
  const login = source.slice(source.indexOf("function Login("), source.indexOf("function Dashboard("));
  assert.doesNotMatch(login, /defaultValue=/);
  assert.doesNotMatch(login, /Deurstore2026!/);
  assert.match(login, /autoComplete="username"/);
  assert.match(login, /autoComplete="current-password"/);
});
