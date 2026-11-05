#pragma once

// false: GPIO -> 330 ohm resistor -> LED anode (+), LED cathode (-) -> GND.
// true: 3.3 V -> 330 ohm resistor -> LED anode (+), LED cathode (-) -> GPIO.
// Every LED needs its own resistor. Preserve this board's setting on regeneration.
// NODE_9: user confirmed GPIO -> LED positive, LED negative -> common GND.
static const bool STATUS_LED_ACTIVE_LOW = false;
