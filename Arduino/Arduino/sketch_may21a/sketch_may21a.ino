#include <TinyGPSPlus.h>

#define GPS_RX 26
#define GPS_TX 27
#define GPS_BAUD 9600

TinyGPSPlus gps;
HardwareSerial gpsSerial(2);

void setup() {
  Serial.begin(115200);
  gpsSerial.begin(GPS_BAUD, SERIAL_8N1, GPS_RX, GPS_TX);

  Serial.println("GPS LOCATION TEST");
  Serial.println("Waiting for GPS signal...");
  Serial.println("Put the GPS near window or outside.");
  Serial.println();
}

void loop() {
  while (gpsSerial.available() > 0) {
    gps.encode(gpsSerial.read());
  }

  if (gps.location.isValid()) {
    Serial.println("===== GPS LOCATION FOUND =====");

    Serial.print("Latitude: ");
    Serial.println(gps.location.lat(), 6);

    Serial.print("Longitude: ");
    Serial.println(gps.location.lng(), 6);

    Serial.print("Satellites: ");
    Serial.println(gps.satellites.value());

    Serial.print("Google Maps Link: ");
    Serial.print("https://www.google.com/maps?q=");
    Serial.print(gps.location.lat(), 6);
    Serial.print(",");
    Serial.println(gps.location.lng(), 6);

    Serial.println("==============================");
    Serial.println();

    delay(3000);
  }
  else {
    Serial.println("No valid GPS location yet...");
    Serial.print("Satellites: ");
    Serial.println(gps.satellites.value());
    Serial.println("Waiting...");
    Serial.println();

    delay(2000);
  }
}
