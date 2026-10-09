#include "wokwi-api.h"
#include <stdint.h>
#include <stdlib.h>

// Ideal voltage source for the wiring diagram, NOT a battery-life, charge,
// thermal, current-limit, or protection-circuit simulation.
typedef struct {
  pin_t positive;
  uint32_t voltage;
} battery_t;

static void update_voltage(void *user_data) {
  battery_t *battery = user_data;
  float voltage = attr_read_float(battery->voltage);
  pin_dac_write(battery->positive, voltage >= 3.0f && voltage <= 4.25f ? voltage : 0.0f);
}

void chip_init(void) {
  battery_t *battery = calloc(1, sizeof(*battery));
  battery->positive = pin_init("P+", ANALOG);
  pin_dac_write(pin_init("P-", ANALOG), 0.0f);
  battery->voltage = attr_init_float("voltage", 3.7f);
  update_voltage(battery);
  const timer_config_t timer_config = {.callback = update_voltage, .user_data = battery};
  timer_start(timer_init(&timer_config), 100000, true);

  // A simple pouch-cell illustration; ratings are shown in the chip/diagram labels.
  uint32_t width, height;
  buffer_t display = framebuffer_init(&width, &height);
  uint8_t *pixels = calloc(width * height, 4);
  for (uint32_t y = 0; y < height; y++) {
    for (uint32_t x = 0; x < width; x++) {
      uint8_t *pixel = pixels + 4 * (y * width + x);
      if (x < 5 || x >= width - 5 || y < 6 || y >= height - 6) continue;
      const int edge = x < 15;
      pixel[0] = edge ? 226 : 194;
      pixel[1] = edge ? 175 : 198;
      pixel[2] = edge ? 38 : 202;
      pixel[3] = 255;
    }
  }
  buffer_write(display, 0, pixels, width * height * 4);
  free(pixels);
}
