const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');

// Run the compiled WASM with mocked Wokwi imports. These tests check the ideal
// component logic and diagram connectivity, not the ESP32 or real battery safety.
function runtime(name, initialPins = {}, initialAttributes = {}) {
  const definition = JSON.parse(fs.readFileSync(path.join(__dirname, `${name}.chip.json`), 'utf8'));
  const module = new WebAssembly.Module(fs.readFileSync(path.join(__dirname, `${name}.chip.wasm`)));
  const memory = new WebAssembly.Memory({ initial: 32 });
  const pins = new Map();
  const pinNames = new Map();
  const attributes = new Map();
  const timers = [];
  let frame;
  const string = (pointer) => {
    const bytes = new Uint8Array(memory.buffer);
    let end = pointer;
    while (bytes[end]) end++;
    return Buffer.from(bytes.subarray(pointer, end)).toString('utf8');
  };
  const env = {
    memory,
    pinInit(pointer) {
      const pinName = string(pointer);
      assert(definition.pins.includes(pinName));
      const id = pins.size + 1;
      pins.set(id, initialPins[pinName] ?? 0);
      pinNames.set(pinName, id);
      return id;
    },
    pinADCRead(id) { return pins.get(id); },
    pinDACWrite(id, value) { pins.set(id, value); return value; },
    attrInitFloat(pointer, fallback) {
      const key = string(pointer);
      attributes.set(key, initialAttributes[key] ?? fallback);
      return [...attributes.keys()].indexOf(key) + 1;
    },
    attrReadFloat(id) { return [...attributes.values()][id - 1]; },
    timerInit(pointer) {
      const view = new DataView(memory.buffer);
      timers.push({ userData: view.getUint32(pointer, true), callback: view.getUint32(pointer + 4, true) });
      return timers.length;
    },
    timerStart(id, interval, repeat) {
      assert.equal(interval, 100000);
      assert.equal(repeat, 1);
      assert(timers[id - 1]);
    },
    framebufferInit(widthPointer, heightPointer) {
      const view = new DataView(memory.buffer);
      view.setUint32(widthPointer, definition.display.width, true);
      view.setUint32(heightPointer, definition.display.height, true);
      return 1;
    },
    bufferWrite(id, offset, pointer, length) {
      assert.equal(id, 1);
      assert.equal(offset, 0);
      assert.equal(length, definition.display.width * definition.display.height * 4);
      frame = Buffer.from(new Uint8Array(memory.buffer, pointer, length));
    },
  };
  for (const imported of WebAssembly.Module.imports(module)) {
    assert.equal(imported.module, 'env', `Unexpected import module: ${imported.module}`);
    assert(imported.name in env, `Unmocked API: ${imported.name}`);
  }
  const instance = new WebAssembly.Instance(module, { env });
  instance.exports.chipInit();
  return {
    value: (pin) => pins.get(pinNames.get(pin)),
    setPin: (pin, value) => pins.set(pinNames.get(pin), value),
    setAttribute: (key, value) => attributes.set(key, value),
    tick() {
      for (const timer of timers) instance.exports.__indirect_function_table.get(timer.callback)(timer.userData);
    },
    frame,
  };
}

test('battery provides nominal 3.7V and a visible pouch-cell illustration', () => {
  const battery = runtime('lipo-battery');
  assert(Math.abs(battery.value('P+') - 3.7) < 0.001);
  assert.equal(battery.value('P-'), 0);
  assert(battery.frame.some((byte, index) => index % 4 === 3 && byte === 255));
});

test('battery voltage control updates without simulating charge/discharge over time', () => {
  const battery = runtime('lipo-battery');
  for (const [voltage, expected] of [[4.2, 4.2], [3.0, 3.0], [2.9, 0], [4.3, 0]]) {
    battery.setAttribute('voltage', voltage);
    battery.tick();
    assert(Math.abs(battery.value('P+') - expected) < 0.001);
  }
});

test('boost produces 5V only with a valid cell or valid charging-input voltage', () => {
  for (const [cell, usb, expected] of [[3.7, 0, 5], [4.2, 0, 5], [2.9, 0, 0], [4.3, 0, 0], [0, 5, 5], [0, 4, 0], [0, 6, 0], [0, 0, 0]]) {
    const charger = runtime('lipo-charger-boost', { 'B+': cell, CHARGE_5V: usb });
    assert.equal(charger.value('USB_5V'), expected);
    assert.equal(charger.value('USB_GND'), 0);
  }
});

test('boost periodically rechecks input voltages', () => {
  const charger = runtime('lipo-charger-boost', { 'B+': 3.7 });
  charger.setPin('B+', 0);
  charger.tick();
  assert.equal(charger.value('USB_5V'), 0);
  charger.setPin('CHARGE_5V', 5);
  charger.tick();
  assert.equal(charger.value('USB_5V'), 5);
});

test('active diagram keeps USB-only wiring without optional battery components', () => {
  const diagram = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'diagram.json'), 'utf8'));
  assert.equal(new Set(diagram.parts.map((part) => part.id)).size, diagram.parts.length);
  const removedIds = new Set(['battery', 'charger', 'labelBattery', 'labelCharger', 'labelCharging', 'labelUsbPower', 'labelPowerModel', 'labelBatterySafety']);
  assert(!diagram.parts.some((part) => removedIds.has(part.id) || part.type.startsWith('chip-lipo-')));
  const partIds = new Set(diagram.parts.map((part) => part.id));
  const connections = diagram.connections.map(([from, to]) => [from, to]);
  for (const ends of connections) {
    for (const end of ends) {
      const id = end.split(':')[0];
      assert(partIds.has(id) || id === '$serialMonitor', `Unknown connection endpoint: ${end}`);
      assert(!removedIds.has(id));
    }
  }
  for (const required of [['esp:3V3', 'imu:VCC'], ['esp:GND.1', 'imu:GND'], ['esp:D21', 'imu:SDA'], ['esp:D22', 'imu:SCL'], ['esp:D18', 'bb1:5t.c'], ['esp:D19', 'bb1:12t.c'], ['esp:D23', 'bb1:19t.c'], ['esp:D27', 'bb1:24t.d'], ['esp:D25', 'bz:2']]) {
    assert(connections.some((connection) => JSON.stringify(connection) === JSON.stringify(required)));
  }
  assert(!connections.some((ends) => ends.includes('esp:VIN')));
  const config = fs.readFileSync(path.join(__dirname, '..', 'wokwi.toml'), 'utf8');
  for (const name of ['lipo-battery', 'lipo-charger-boost']) {
    assert(!config.includes(`name = "${name}"`));
    assert(!config.includes(`binary = "chips/${name}.chip.wasm"`));
  }
});
