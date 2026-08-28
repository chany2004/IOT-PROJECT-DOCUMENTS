(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
  } else if (typeof define === "function" && define.amd) {
    define([], factory);
  } else {
    root.FallDetectionEngine = factory();
  }
})(typeof globalThis !== "undefined" ? globalThis : this, function () {
  "use strict";

  var DEFAULTS = Object.freeze({
    alpha: 0.3,
    accelThresholdG: 2.2,
    gyroThresholdDps: 220,
    windowMs: 250,
    cooldownMs: 10000,
  });

  var hasOwn = Object.prototype.hasOwnProperty;

  function requireFiniteNumber(value, label) {
    if (typeof value !== "number" || !Number.isFinite(value)) {
      throw new TypeError(label + " must be a finite number");
    }
    return value;
  }

  function requirePositive(value, label) {
    value = requireFiniteNumber(value, label);
    if (value <= 0) {
      throw new RangeError(label + " must be greater than zero");
    }
    return value;
  }

  function requireNonNegative(value, label) {
    value = requireFiniteNumber(value, label);
    if (value < 0) {
      throw new RangeError(label + " must be zero or greater");
    }
    return value;
  }

  function resolveMagnitude(sample, axisKeys, magnitudeKeys, label) {
    var hasAxis = axisKeys.some(function (key) {
      return hasOwn.call(sample, key);
    });

    if (hasAxis) {
      var axes = axisKeys.map(function (key) {
        return requireFiniteNumber(sample[key], "sample." + key);
      });
      return Math.hypot(axes[0], axes[1], axes[2]);
    }

    for (var i = 0; i < magnitudeKeys.length; i += 1) {
      var key = magnitudeKeys[i];
      if (hasOwn.call(sample, key)) {
        return requireNonNegative(sample[key], "sample." + key);
      }
    }

    throw new TypeError(
      "sample must provide " + axisKeys.join(", ") + " or a " + label + " magnitude"
    );
  }

  function FallDetectionEngine(options) {
    if (options === undefined) {
      options = {};
    }
    if (options === null || typeof options !== "object" || Array.isArray(options)) {
      throw new TypeError("options must be an object");
    }

    var alpha = options.alpha === undefined ? DEFAULTS.alpha : options.alpha;
    alpha = requireFiniteNumber(alpha, "alpha");
    if (alpha <= 0 || alpha > 1) {
      throw new RangeError("alpha must be greater than zero and at most one");
    }

    this._config = Object.freeze({
      alpha: alpha,
      accelThresholdG: requirePositive(
        options.accelThresholdG === undefined
          ? DEFAULTS.accelThresholdG
          : options.accelThresholdG,
        "accelThresholdG"
      ),
      gyroThresholdDps: requirePositive(
        options.gyroThresholdDps === undefined
          ? DEFAULTS.gyroThresholdDps
          : options.gyroThresholdDps,
        "gyroThresholdDps"
      ),
      windowMs: requireNonNegative(
        options.windowMs === undefined ? DEFAULTS.windowMs : options.windowMs,
        "windowMs"
      ),
      cooldownMs: requireNonNegative(
        options.cooldownMs === undefined ? DEFAULTS.cooldownMs : options.cooldownMs,
        "cooldownMs"
      ),
    });

    this.reset();
  }

  FallDetectionEngine.prototype.reset = function () {
    this._filterInitialized = false;
    this._filteredAccelMagG = 0;
    this._filteredGyroMagDps = 0;
    this._lastAccelSpikeMs = null;
    this._lastGyroSpikeMs = null;
    this._cooldownUntilMs = 0;
    this._lastNowMs = null;
  };

  FallDetectionEngine.prototype.process = function (sample, nowMs) {
    if (!sample || typeof sample !== "object" || Array.isArray(sample)) {
      throw new TypeError("sample must be an object");
    }

    nowMs = requireNonNegative(nowMs, "nowMs");
    if (this._lastNowMs !== null && nowMs < this._lastNowMs) {
      throw new RangeError("nowMs must be monotonic; call reset() before restarting time");
    }

    var accelMagG = resolveMagnitude(
      sample,
      ["ax_g", "ay_g", "az_g"],
      ["accelMagG", "accelMag_g", "accel_mag_g"],
      "acceleration"
    );
    var gyroMagDps = resolveMagnitude(
      sample,
      ["gx_dps", "gy_dps", "gz_dps"],
      ["gyroMagDps", "gyroMag_dps", "gyro_mag_dps"],
      "rotational velocity"
    );
    this._lastNowMs = nowMs;

    if (!this._filterInitialized) {
      this._filteredAccelMagG = accelMagG;
      this._filteredGyroMagDps = gyroMagDps;
      this._filterInitialized = true;
    } else {
      var alpha = this._config.alpha;
      this._filteredAccelMagG =
        alpha * accelMagG + (1 - alpha) * this._filteredAccelMagG;
      this._filteredGyroMagDps =
        alpha * gyroMagDps + (1 - alpha) * this._filteredGyroMagDps;
    }

    var accelSpike = this._filteredAccelMagG >= this._config.accelThresholdG;
    var gyroSpike = this._filteredGyroMagDps >= this._config.gyroThresholdDps;

    if (accelSpike) {
      this._lastAccelSpikeMs = nowMs;
    }
    if (gyroSpike) {
      this._lastGyroSpikeMs = nowMs;
    }

    var accelRecent =
      this._lastAccelSpikeMs !== null &&
      nowMs - this._lastAccelSpikeMs <= this._config.windowMs;
    var gyroRecent =
      this._lastGyroSpikeMs !== null &&
      nowMs - this._lastGyroSpikeMs <= this._config.windowMs;

    var cooldownRemainingMs = Math.max(0, this._cooldownUntilMs - nowMs);
    var inCooldown = cooldownRemainingMs > 0;
    var confirmed = false;

    if (accelRecent && gyroRecent && !inCooldown) {
      confirmed = true;
      // Cooldown is a property of local detection, not webhook delivery.
      this._cooldownUntilMs = nowMs + this._config.cooldownMs;
      cooldownRemainingMs = this._config.cooldownMs;
      inCooldown = cooldownRemainingMs > 0;
    }

    var pending =
      !confirmed && !inCooldown && (accelRecent || gyroRecent);
    var state = confirmed
      ? "confirmed"
      : inCooldown
        ? "cooldown"
        : pending
          ? "pending"
          : "normal";

    return {
      timestampMs: nowMs,
      rawAccelMagG: accelMagG,
      rawGyroMagDps: gyroMagDps,
      // Short aliases are convenient when adapting the existing webhook payload.
      accelMagG: accelMagG,
      gyroMagDps: gyroMagDps,
      filteredAccelMagG: this._filteredAccelMagG,
      filteredGyroMagDps: this._filteredGyroMagDps,
      accelSpike: accelSpike,
      gyroSpike: gyroSpike,
      accelRecent: accelRecent,
      gyroRecent: gyroRecent,
      pending: pending,
      confirmed: confirmed,
      inCooldown: inCooldown,
      cooldownRemainingMs: cooldownRemainingMs,
      state: state,
    };
  };

  Object.defineProperty(FallDetectionEngine.prototype, "config", {
    get: function () {
      return this._config;
    },
  });

  FallDetectionEngine.DEFAULTS = DEFAULTS;

  return FallDetectionEngine;
});
