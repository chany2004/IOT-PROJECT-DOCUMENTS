"use strict";

// Compatibility launcher for anyone who used the dashboard's former static
// preview command. The live Python bridge is required because it owns the
// /ingest and /api/live endpoints used by the Tinkered Chrome extension.
const { spawn } = require("node:child_process");
const fs = require("node:fs");
const path = require("node:path");

const projectRoot = path.resolve(__dirname, "..");
const serverPath = path.join(__dirname, "server.py");
const platformIoPython = process.env.USERPROFILE
  ? path.join(process.env.USERPROFILE, ".platformio", "penv", "Scripts", "python.exe")
  : "";
const python = platformIoPython && fs.existsSync(platformIoPython)
  ? platformIoPython
  : process.platform === "win32" ? "python" : "python3";

console.log("Starting the live Tinkered bridge at http://127.0.0.1:8765/");
const child = spawn(python, [serverPath], {
  cwd: projectRoot,
  stdio: "inherit",
});

child.on("error", error => {
  console.error(`Could not start the live bridge with ${python}: ${error.message}`);
  process.exitCode = 1;
});

child.on("exit", code => {
  process.exitCode = code == null ? 1 : code;
});

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => {
    if (!child.killed) child.kill(signal);
  });
}
