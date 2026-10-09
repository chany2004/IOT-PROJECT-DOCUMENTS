# Optional rechargeable battery power components

The active root `diagram.json` uses USB power only: it does not include a
battery, charger, their wiring, or their labels. The battery chip registrations
have also been removed from `wokwi.toml`. These optional component files are
retained for future use, not connected to the current circuit.

The components represent a **protected single-cell 3.7V 2000mAh LiPo**
and a **LiPo charger + regulated 5V boost module with power-path/load-sharing**.
Use hardware rated for at least **1A continuous output**, with a charge current
appropriate for the actual battery's datasheet.

These are local custom components using
the [Wokwi Chips API](https://docs.wokwi.com/chips-api/getting-started). Their
compiled `.chip.wasm` files are paired with `.chip.json` definitions.

## Connections

- Battery `P+` (red) -> module `B+`.
- Battery `P-` (black) -> module `B-`.
- Real hardware: module **regulated 5V USB OUTPUT** -> ESP32 **USB port**.
- Charge the battery through the **module's charging USB INPUT**, not through
  the ESP32 USB port. Only use a module explicitly supporting load-sharing if
  the device must run while its battery charges.

The Wokwi ESP32 DevKit V1 has no connectable USB-port power pins, so a diagram
using these optional components can use its documented `VIN` (5V) and `GND.2`
power rails to represent this USB
supply. This is a schematic representation, **not instructions to connect both
VIN power and USB power on the physical board**. Use one board power source at
a time; disconnect the battery-powered USB cable before plugging in laptop USB.
Never connect a raw LiPo directly to `3V3` or a GPIO. Confirm battery polarity
and the actual charger module's documented input/output connectors before use.

`CHARGE_5V` / `CHARGE_GND` indicate the charging module's separate USB input and
are intentionally unwired: an external charging adapter is not included in the
diagram. They are not connections to the ESP32 USB port or battery terminals.

## Simulator limits

The custom components supply idealized voltages only. They do **not** simulate
charging, discharge over time, mAh capacity, current limits, temperature,
protection electronics, regulator efficiency, or runtime. The ESP32 simulator
also does not emulate actual power failures. Real battery and power behavior
must be measured and verified on the physical circuit.

## Rebuild the custom components

The precompiled `.chip.wasm` files are included, so rebuilding is optional.

With the [official Wokwi CLI](https://docs.wokwi.com/guides/custom-chips-to-wasm):

```powershell
wokwi-cli chip compile chips/lipo-battery.chip.c -o chips/lipo-battery.chip.wasm
wokwi-cli chip compile chips/lipo-charger-boost.chip.c -o chips/lipo-charger-boost.chip.wasm
node --test chips/power-model.test.cjs
```

To enable these components again, the diagram and simulator configuration must
be updated explicitly. For Wokwi.com, add the two `.chip.c` and `.chip.json`
pairs as custom chips alongside the diagram. For VS Code, register their WASM
binaries in `wokwi.toml`. No changes to ESP32 firmware or its sensor pin
assignments are needed for the idealized power components.
