"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");

const FallDetectionEngine = require("../fall-engine.js");

const RESTING = Object.freeze({
  ax_g: 0,
  ay_g: 0,
  az_g: 1,
  gx_dps: 0,
  gy_dps: 0,
  gz_dps: 0,
});

function axes(accelG, gyroDps) {
  return {
    ax_g: accelG,
    ay_g: 0,
    az_g: 0,
    gx_dps: gyroDps,
    gy_dps: 0,
    gz_dps: 0,
  };
}

function approximately(actual, expected, epsilon = 1e-10) {
  assert.ok(
    Math.abs(actual - expected) <= epsilon,
    `expected ${actual} to be within ${epsilon} of ${expected}`
  );
}

test("exports firmware-matching defaults", () => {
  assert.deepEqual(FallDetectionEngine.DEFAULTS, {
    alpha: 0.3,
    accelThresholdG: 2.2,
    gyroThresholdDps: 220,
    windowMs: 250,
    cooldownMs: 10000,
  });

  const engine = new FallDetectionEngine();
  assert.deepEqual(engine.config, FallDetectionEngine.DEFAULTS);
  assert.ok(Object.isFrozen(engine.config));
});

test("publishes a browser global when CommonJS and AMD are absent", () => {
  const sourcePath = path.join(__dirname, "..", "fall-engine.js");
  const source = fs.readFileSync(sourcePath, "utf8");
  const context = vm.createContext({});

  vm.runInContext(source, context, { filename: "fall-engine.js" });

  assert.equal(typeof context.FallDetectionEngine, "function");
  const engine = new context.FallDetectionEngine();
  assert.equal(engine.config.cooldownMs, 10000);
});

test("returns raw vector magnitudes and initializes the EMA from the first sample", () => {
  const engine = new FallDetectionEngine();
  const result = engine.process(
    {
      ax_g: 1,
      ay_g: 2,
      az_g: 2,
      gx_dps: 3,
      gy_dps: 4,
      gz_dps: 12,
    },
    0
  );

  assert.equal(result.rawAccelMagG, 3);
  assert.equal(result.accelMagG, 3);
  assert.equal(result.rawGyroMagDps, 13);
  assert.equal(result.gyroMagDps, 13);
  assert.equal(result.filteredAccelMagG, 3);
  assert.equal(result.filteredGyroMagDps, 13);
  assert.equal(result.accelSpike, true);
  assert.equal(result.gyroSpike, false);
  assert.equal(result.pending, true);
  assert.equal(result.confirmed, false);
  assert.equal(result.state, "pending");
});

test("applies the default 0.30 EMA and confirms spikes inside the 250 ms window", () => {
  const engine = new FallDetectionEngine();
  let result = engine.process(axes(1, 0), 0);
  assert.equal(result.state, "normal");

  result = engine.process(axes(3, 250), 50);
  approximately(result.filteredAccelMagG, 1.6);
  approximately(result.filteredGyroMagDps, 75);
  assert.equal(result.state, "normal");

  result = engine.process(axes(3, 250), 100);
  approximately(result.filteredAccelMagG, 2.02);
  approximately(result.filteredGyroMagDps, 127.5);

  result = engine.process(axes(3, 250), 150);
  approximately(result.filteredAccelMagG, 2.314);
  approximately(result.filteredGyroMagDps, 164.25);
  assert.equal(result.accelSpike, true);
  assert.equal(result.gyroSpike, false);
  assert.equal(result.pending, true);

  engine.process(axes(3, 250), 200);
  engine.process(axes(3, 250), 250);
  result = engine.process(axes(3, 250), 300);

  approximately(result.filteredGyroMagDps, 220.58775);
  assert.equal(result.accelSpike, true);
  assert.equal(result.gyroSpike, true);
  assert.equal(result.confirmed, true);
  assert.equal(result.pending, false);
  assert.equal(result.state, "confirmed");
  assert.equal(result.cooldownRemainingMs, 10000);
});

test("treats the correlation window boundary as inclusive and expires it afterward", () => {
  const inclusive = new FallDetectionEngine({ alpha: 1 });
  let result = inclusive.process(axes(3, 0), 100);
  assert.equal(result.state, "pending");
  result = inclusive.process(axes(0, 300), 350);
  assert.equal(result.confirmed, true);

  const expired = new FallDetectionEngine({ alpha: 1 });
  expired.process(axes(3, 0), 100);
  result = expired.process(axes(0, 300), 351);
  assert.equal(result.confirmed, false);
  assert.equal(result.pending, true);
  assert.equal(result.state, "pending");
  assert.equal(result.accelRecent, false);
  assert.equal(result.gyroRecent, true);
});

test("starts cooldown on local confirmation without waiting for alert delivery", () => {
  const engine = new FallDetectionEngine({ alpha: 1 });
  let result = engine.process(axes(3, 300), 0);

  assert.equal(result.confirmed, true);
  assert.equal(result.inCooldown, true);
  assert.equal(result.cooldownRemainingMs, 10000);

  result = engine.process(axes(3, 300), 1000);
  assert.equal(result.confirmed, false);
  assert.equal(result.state, "cooldown");
  assert.equal(result.cooldownRemainingMs, 9000);

  result = engine.process(axes(3, 300), 9999);
  assert.equal(result.confirmed, false);
  assert.equal(result.cooldownRemainingMs, 1);

  result = engine.process(axes(3, 300), 10000);
  assert.equal(result.confirmed, true);
  assert.equal(result.cooldownRemainingMs, 10000);
});

test("counts cooldown down even when later samples are normal", () => {
  const engine = new FallDetectionEngine({ alpha: 1 });
  engine.process(axes(3, 300), 500);

  const result = engine.process(RESTING, 3000);
  assert.equal(result.accelSpike, false);
  assert.equal(result.gyroSpike, false);
  assert.equal(result.pending, false);
  assert.equal(result.confirmed, false);
  assert.equal(result.inCooldown, true);
  assert.equal(result.cooldownRemainingMs, 7500);
  assert.equal(result.state, "cooldown");
});

test("accepts webhook-style magnitude-only samples", () => {
  const engine = new FallDetectionEngine({ alpha: 1 });
  const result = engine.process(
    { accel_mag_g: 2.2, gyro_mag_dps: 220 },
    42
  );

  assert.equal(result.rawAccelMagG, 2.2);
  assert.equal(result.rawGyroMagDps, 220);
  assert.equal(result.accelSpike, true);
  assert.equal(result.gyroSpike, true);
  assert.equal(result.confirmed, true);
});

test("reset clears filter, pending spikes, cooldown, and the monotonic clock", () => {
  const engine = new FallDetectionEngine({ alpha: 1 });
  engine.process(axes(3, 300), 5000);
  engine.reset();

  let result = engine.process(RESTING, 0);
  assert.equal(result.filteredAccelMagG, 1);
  assert.equal(result.filteredGyroMagDps, 0);
  assert.equal(result.cooldownRemainingMs, 0);
  assert.equal(result.state, "normal");

  result = engine.process(axes(3, 0), 1);
  assert.equal(result.pending, true);
  assert.equal(result.confirmed, false);
});

test("supports zero-length windows and cooldowns", () => {
  const engine = new FallDetectionEngine({
    alpha: 1,
    windowMs: 0,
    cooldownMs: 0,
  });

  let result = engine.process(axes(3, 0), 0);
  assert.equal(result.pending, true);
  result = engine.process(axes(0, 300), 1);
  assert.equal(result.confirmed, false);

  result = engine.process(axes(3, 300), 2);
  assert.equal(result.confirmed, true);
  assert.equal(result.inCooldown, false);
  assert.equal(result.cooldownRemainingMs, 0);
});

test("rejects invalid configuration", () => {
  assert.throws(() => new FallDetectionEngine(null), /options must be an object/);
  assert.throws(() => new FallDetectionEngine({ alpha: 0 }), /alpha/);
  assert.throws(() => new FallDetectionEngine({ alpha: 1.1 }), /alpha/);
  assert.throws(
    () => new FallDetectionEngine({ accelThresholdG: 0 }),
    /accelThresholdG/
  );
  assert.throws(
    () => new FallDetectionEngine({ gyroThresholdDps: NaN }),
    /gyroThresholdDps/
  );
  assert.throws(() => new FallDetectionEngine({ windowMs: -1 }), /windowMs/);
  assert.throws(() => new FallDetectionEngine({ cooldownMs: -1 }), /cooldownMs/);
});

test("rejects malformed samples and non-monotonic timestamps", () => {
  const engine = new FallDetectionEngine();

  assert.throws(() => engine.process(null, 0), /sample must be an object/);
  assert.throws(
    () => engine.process({ ax_g: 1, ay_g: 0, gx_dps: 0, gy_dps: 0, gz_dps: 0 }, 0),
    /sample\.az_g/
  );
  assert.throws(
    () => engine.process({ accel_mag_g: -1, gyro_mag_dps: 0 }, 0),
    /accel_mag_g/
  );
  assert.throws(() => engine.process(RESTING, -1), /nowMs/);

  // Rejected samples do not advance the engine clock.
  assert.throws(() => engine.process({ accel_mag_g: 1 }, 1000), /rotational velocity/);
  assert.equal(engine.process(RESTING, 0).timestampMs, 0);

  engine.process(RESTING, 100);
  assert.throws(() => engine.process(RESTING, 99), /nowMs must be monotonic/);
});
