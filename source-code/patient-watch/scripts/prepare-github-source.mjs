// Prepare a separate source-only publication. Never changes working credentials,
// never pushes, and never logs credential values. Run audits before committing.
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const checkout = path.join(root, 'patient-monitor/.runtime/github-source-upload-20261009');
const destination = path.join(checkout, 'source-code/patient-watch');
const mode = process.argv[2];
if (!['stage', 'audit'].includes(mode)) throw new Error('Use stage or audit.');
const secretNames = /password|passwd|secret|token|(?:^|_)keys?(?:$|_)/i;
const guards = new Map();
const maxBytes = 16 * 1024 * 1024;
const textExtensions = new Set(['.php', '.js', '.mjs', '.cjs', '.css', '.html', '.svg',
  '.json', '.xml', '.md', '.txt', '.py', '.ps1', '.sh', '.ino', '.h', '.cpp', '.c',
  '.toml', '.ini', '.conf', '.template', '.service', '.example', '.yml', '.yaml', '.lock']);
const denyDirs = new Set(['.git', '.runtime', '.aws', '.codex', '.agents', '.venv',
  '.pio', 'vendor', 'node_modules', 'storage', 'uploads', 'captures', 'logs',
  '__pycache__', 'build', 'cache', 'dist']);
const denyName = /(?:\.bak(?:\.|$)|\.backup(?:\.|$)|\.old$|\.bin$|\.elf$|\.wasm$|\.vlx$|\.sql$|\.dump$|\.db$|\.sqlite|\.log$|\.zip$|\.gz$|\.pem$|\.key$|\.pfx$|\.p12$|\.jpg$|\.jpeg$|\.png$|\.webp$|\.pdf$|\.docx?$|(?:ADMIN|CLOUD).*LOGIN|credentials)/i;
const allowedFiles = [
  'README.md', 'LARAVEL_MONITOR_SETUP.md', 'platformio.ini', 'wokwi.toml',
  'diagram.json', 'libraries.txt', 'sketch.ino', 'detector.h',
  'camera_verifier.cpp', 'camera_verifier.h', 'camera_settings.h',
  'camera_secrets.example.h', '.env.example',
  'test_detector.cpp', 'test_camera_safety.cpp', 'test_wifi_provisioning.cpp',
  'patient-monitor/.gitignore', 'patient-monitor/.env.example',
  'patient-monitor/artisan', 'patient-monitor/composer.json', 'patient-monitor/composer.lock',
  'patient-monitor/package.json', 'patient-monitor/package-lock.json',
  'patient-monitor/vite.config.js', 'patient-monitor/phpunit.xml',
  'patient-monitor/bootstrap/app.php', 'patient-monitor/bootstrap/providers.php',
  'patient-monitor/scripts/setup-local.ps1', 'patient-monitor/scripts/start-local.ps1',
  'patient-monitor/scripts/start-camera-worker.ps1', 'patient-monitor/scripts/stop-local.ps1',
  'patient-monitor/scripts/start-telegram.ps1',
  'scripts/check_camera_snapshot.py', 'scripts/test_camera_serial.py',
  'scripts/prepare-github-source.mjs',
];
const allowedTrees = ['src', 'velxio_document/main', 'velxio_document/camera', 'backend',
  'patient-monitor/app', 'patient-monitor/config', 'patient-monitor/database/migrations',
  'patient-monitor/database/seeders', 'patient-monitor/database/factories',
  'patient-monitor/public', 'patient-monitor/resources', 'patient-monitor/routes',
  'patient-monitor/tests', 'chips', 'Velxio_Fall_Detection_Package', 'velxio_fall_detection'];

function decode(raw) {
  const value = raw.trim();
  if (value.startsWith('"') && value.endsWith('"')) {
    try { return JSON.parse(value); } catch { return value.slice(1, -1); }
  }
  if (value.startsWith("'") && value.endsWith("'")) return value.slice(1, -1);
  return value.replace(/\s+#.*$/, '').trim();
}
function addGuard(value) {
  if (typeof value !== 'string' || value.length < 8 ||
      /^(?:YOUR_|PASTE_|GENERATE_|CHANGE_|REPLACE_|\$|null$)/i.test(value)) return;
  for (const encoding of ['utf8', 'utf16le']) {
    const bytes = Buffer.from(value, encoding);
    guards.set(bytes.toString('hex'), bytes);
  }
  if (value.startsWith('base64:')) addGuard(value.slice(7));
}
function credentialGuards(bytes) {
  const text = bytes[0] === 0xff && bytes[1] === 0xfe
    ? bytes.subarray(2).toString('utf16le') : bytes.toString('utf8');
  for (const match of text.matchAll(/^\s*(?:export\s+)?([A-Za-z_][\w]*)\s*=\s*(.*?)\s*$/gm)) {
    if (secretNames.test(match[1])) addGuard(decode(match[2]));
  }
  for (const match of text.matchAll(/^\s*#define\s+(\w+)\s+("(?:\\.|[^"\\])*")/gm)) {
    if (secretNames.test(match[1])) addGuard(decode(match[2]));
  }
  for (const match of text.matchAll(/^\s*(?:password|passphrase|(?:[\w -]*\s)?(?:key|token))\s*[:=]\s*(.+)$/gim)) {
    addGuard(decode(match[1]));
  }
  for (const match of text.matchAll(/\bPW-[A-Za-z0-9_!\-]{16,}/g)) addGuard(match[0]);
  if (/^[A-Za-z0-9_!/+\-=.]{8,256}$/.test(text.trim())) addGuard(text.trim());
}
async function read(filename) {
  const info = await fs.lstat(filename);
  if (!info.isFile() || info.isSymbolicLink() || info.size > maxBytes) {
    throw new Error('Unsafe or oversized source: ' + path.relative(root, filename));
  }
  return fs.readFile(filename);
}
async function privateWalk(directory) {
  for (const entry of await fs.readdir(directory, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) continue;
    const filename = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (!['postgres', 'postgres_data', '.git', 'node_modules', 'vendor',
        'github-source-upload-20261009', 'github-source-scanner-20261009',
        'aws-source-staging'].includes(entry.name)) await privateWalk(filename);
    } else if (entry.isFile() && /(?:^\.env(?:\.|$)|secrets?|credentials?|password|LOGIN|local-access)/i.test(entry.name)
        && /(?:\.h(?:\.bak)?$|\.txt$|^\.env(?:\..*)?$|\.json$)/i.test(entry.name)) {
      const info = await fs.stat(filename);
      if (info.size <= maxBytes) credentialGuards(await fs.readFile(filename));
    }
  }
}
for (const filename of ['.env', 'patient-monitor/.env', 'camera_secrets.h',
  'camera_secrets.h.bak', 'velxio_document/camera/camera_node_secrets.h']) {
  try { credentialGuards(await read(path.join(root, filename))); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
}
await privateWalk(path.join(root, 'patient-monitor/.runtime'));
if (!guards.size) throw new Error('No credential guards loaded; refusing publication.');

function scan(bytes, relative) {
  for (const guard of guards.values()) {
    if (bytes.includes(guard)) throw new Error('Private credential detected in: ' + relative);
  }
  const text = bytes.toString('utf8');
  if (/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----|\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{50,}|AKIA[A-Z0-9]{16}|\d{8,12}:[A-Za-z0-9_-]{35})\b/.test(text)) {
    throw new Error('Credential-shaped value detected in: ' + relative);
  }
}
function permitted(relative) {
  const name = path.basename(relative);
  if (relative.split(/[\\/]/).some(part => denyDirs.has(part)) || denyName.test(name)) return false;
  if (name.startsWith('.env') && !name.endsWith('.example')) return false;
  if (/secrets?/i.test(name) && !name.endsWith('.example.h')) return false;
  return name === '.gitignore' || textExtensions.has(path.extname(name));
}
const entries = new Map();
async function collect(relative) {
  const filename = path.join(root, relative);
  const bytes = await read(filename);
  scan(bytes, relative);
  entries.set(relative.replaceAll('\\', '/'), bytes);
}
async function sourceWalk(relative) {
  const info = await fs.lstat(path.join(root, relative));
  if (info.isSymbolicLink() || !info.isDirectory()) throw new Error('Unsafe source tree: ' + relative);
  for (const entry of await fs.readdir(path.join(root, relative), { withFileTypes: true })) {
    const child = relative + '/' + entry.name;
    if (entry.isSymbolicLink()) throw new Error('Source symlink refused: ' + child);
    if (entry.isDirectory()) { if (!denyDirs.has(entry.name)) await sourceWalk(child); }
    else if (entry.isFile() && permitted(child)) await collect(child);
  }
}

if (mode === 'stage') {
  try { await fs.lstat(destination); throw new Error('Destination already exists; preserve it.'); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  for (const relative of allowedFiles) {
    try { await collect(relative); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
  }
  for (const relative of allowedTrees) await sourceWalk(relative);
  // Scan the complete selection before copying anything into the Git checkout.
  for (const [relative, bytes] of entries) {
    const target = path.join(destination, relative);
    await fs.mkdir(path.dirname(target), { recursive: true });
    await fs.writeFile(target, bytes, { flag: 'wx' }); // source-only copy, never overwrite
  }
  console.log(JSON.stringify({ staged_source_files: entries.size, known_secret_scan: 'passed' }));
} else {
  const tracked = execFileSync('git', ['ls-files', '-z', '--cached', '--others', '--exclude-standard'], { cwd: checkout });
  const paths = tracked.toString('utf8').split('\0').filter(Boolean);
  for (const relative of paths) scan(await read(path.join(checkout, relative)), relative);
  // Scan all existing commit objects; never rewrite the user's repository history.
  const objects = execFileSync('git', ['rev-list', '--objects', '--all'], { cwd: checkout, maxBuffer: maxBytes }).toString('utf8');
  let blobs = 0;
  for (const line of objects.split('\n').filter(Boolean)) {
    const [oid, ...rest] = line.split(' ');
    const type = execFileSync('git', ['cat-file', '-t', oid], { cwd: checkout }).toString().trim();
    if (type !== 'blob') continue;
    const bytes = execFileSync('git', ['cat-file', '-p', oid], { cwd: checkout, maxBuffer: maxBytes });
    scan(bytes, 'history/' + (rest.join(' ') || oid));
    ++blobs;
  }
  console.log(JSON.stringify({ checkout_files_scanned: paths.length, history_blobs_scanned: blobs,
    known_secret_and_high_confidence_patterns: 'passed' }));
}
