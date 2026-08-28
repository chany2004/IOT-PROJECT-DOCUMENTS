"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");

const dashboardRoot = path.join(__dirname, "..");
const html = fs.readFileSync(path.join(dashboardRoot, "index.html"), "utf8");
const app = fs.readFileSync(path.join(dashboardRoot, "app.js"), "utf8");

test("every ID queried by app.js exists in the dashboard markup", () => {
  const queriedIds = Array.from(app.matchAll(/\$\("#([A-Za-z][\w-]*)"\)/g), (match) => match[1]);
  assert.ok(queriedIds.length > 40, "expected the UI controller to query dashboard elements");
  queriedIds.forEach((id) => {
    assert.match(html, new RegExp(`\\bid=["']${id}["']`), `missing #${id} in index.html`);
  });
});

test("dashboard markup has unique element IDs", () => {
  const ids = Array.from(html.matchAll(/\bid=["']([^"']+)["']/g), (match) => match[1]);
  assert.equal(new Set(ids).size, ids.length);
});

test("dashboard loads only the live API controller", () => {
  assert.doesNotMatch(html, /src=["']fall-engine\.js["']/);
  assert.match(html, /src=["']app\.js["']/);
  assert.doesNotMatch(app, /Math\.random/);
});

test("system dashboard stays focused on live monitoring", () => {
  assert.doesNotMatch(html, /Group 7 project/);
  assert.doesNotMatch(html, /Team members/);
  assert.match(html, /Live session summary/);
  assert.match(html, /Signal match/);
  assert.match(html, /Tinkered live connection/);
});

test("dashboard starts neutral and keeps alarm acknowledgement separate from history deletion", () => {
  assert.match(html, /data-state="offline"/);
  assert.match(html, /id="accel-value">—</);
  assert.match(html, /id="gyro-value">—</);
  assert.match(app, /fetch\("\/api\/acknowledge"/);
  assert.match(app, /fetch\("\/api\/events\/clear"/);
  assert.doesNotMatch(app, /fetch\("\/api\/clear"/);
  assert.match(app, /incidentType === "EMERGENCY"/);
});

test("urgent state and prototype limitations are exposed to assistive technology", () => {
  assert.match(html, /role="alert"\s+aria-live="assertive"/);
  assert.match(html, /role="dialog"/);
  assert.match(html, /aria-modal="true"/);
  assert.match(html, /Live Tinkered prototype\./);
  assert.match(html, /No generated samples\./);
  assert.match(html, /not a medical device/);
});
