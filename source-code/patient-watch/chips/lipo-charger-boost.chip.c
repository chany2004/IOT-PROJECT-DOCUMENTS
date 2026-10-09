#include "wokwi-api.h"
#include <stdlib.h>

// Idealized power-path illustration, not a model of a specific commercial
// charger. No current, charging rate, efficiency, runtime, or battery safety
// behavior is simulated. Verify the actual hardware's documented pinout.
typedef struct {
  pin_t battery_positive, battery_negative;
  pin_t charge_positive, charge_negative;
  pin_t output;
} charger_t;

static void update_output(void *user_data) {
  charger_t *charger = user_data;
  float cell = pin_adc_read(charger->battery_positive) - pin_adc_read(charger->battery_negative);
  float usb = pin_adc_read(charger->charge_positive) - pin_adc_read(charger->charge_negative);
  int cell_available = cell >= 3.0f && cell <= 4.25f;
  int usb_available = usb >= 4.75f && usb <= 5.25f;
  pin_dac_write(charger->output, cell_available || usb_available ? 5.0f : 0.0f);
}

void chip_init(void) {
  charger_t *charger = calloc(1, sizeof(*charger));
  charger->battery_positive = pin_init("B+", ANALOG);
  charger->battery_negative = pin_init("B-", ANALOG);
  charger->charge_positive = pin_init("CHARGE_5V", ANALOG);
  charger->charge_negative = pin_init("CHARGE_GND", ANALOG);
  charger->output = pin_init("USB_5V", ANALOG);
  pin_dac_write(pin_init("USB_GND", ANALOG), 0.0f);
  update_output(charger);
  const timer_config_t timer_config = {.callback = update_output, .user_data = charger};
  timer_start(timer_init(&timer_config), 100000, true);
}
