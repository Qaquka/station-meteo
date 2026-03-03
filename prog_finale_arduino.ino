#include <Wire.h>
#include <SPI.h>
#include <WiFiNINA.h>
#include "DFRobot_SHT20.h"
#include "DFRobot_RainfallSensor.h"
#include <SoftwareSerial.h>


// ====== WIFI =======
char ssid[] = "totoma";
char pass[] = "azertyuiop";


// ===== CAPTEUR SHT20 ========
DFRobot_SHT20 sht20(&Wire, SHT20_I2C_ADDR);
float humidite = 0.0;
float temperature = 0.0;


// ======= CAPTEUR PLUIE (UART) ========
SoftwareSerial mySerial(10, 11); // RX, TX
DFRobot_RainfallSensor_UART RainSensor(&mySerial);


// variables pluie
float pluie = 0.0;        // cumul total
float Rainfall_1h = 0.0;  // pluie sur 1h


// ===== ENVOI RASPBERRY =======
const char server[] = "192.168.137.175"; // IP Raspberry
const int port = 8080;                   // HTTP
String apiKey = "c473565ff9f8e76a7b5a419f31ee7a0b";


WiFiClient client;


// ===== Timers =====
unsigned long lastSend = 0;
const unsigned long periodSend = 10000UL; // 


unsigned long lastPrint = 0;
const unsigned long periodPrint = 5000UL;  // debug toutes les 5s

void ensureWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;


  Serial.println("WiFi perdu, reconnexion...");
  WiFi.disconnect();
  delay(500);


  while (WiFi.begin(ssid, pass) != WL_CONNECTED) {
    delay(1000);
    Serial.print(".");
  }
  Serial.println("\n WiFi reconnecté");
  Serial.print("IP Arduino: ");
  Serial.println(WiFi.localIP());
}


void setup() {
  Serial.begin(9600);
  delay(1000);


  // ===== INIT I2C + SHT20 =====
  Wire.begin();
  sht20.initSHT20();
  delay(100);
  sht20.checkSHT20();
  Serial.println("SHT20 initialise");


  // ===== INIT UART + CAPTEUR PLUIE =====
  mySerial.begin(9600);


  while (!RainSensor.begin()) {
    Serial.println("Erreur init capteur pluie !");
    delay(1000);
  }


  Serial.println("Capteur pluie initialise");
  Serial.print("VID : "); Serial.println(RainSensor.vid, HEX);
  Serial.print("PID : "); Serial.println(RainSensor.pid, HEX);


  // ===== WIFI =====
  Serial.print("Connexion WiFi: ");
  Serial.println(ssid);


  while (WiFi.begin(ssid, pass) != WL_CONNECTED) {
    delay(1000);
    Serial.print(".");
  }


  Serial.println("\nWiFi OK");
  Serial.print("IP Arduino: ");
  Serial.println(WiFi.localIP());
}


void loop() {
  // ===== LECTURE SHT20 =====
  temperature = sht20.readTemperature();
  humidite    = sht20.readHumidity();


  // ===== LECTURE CAPTEUR PLUIE =====
  pluie        = RainSensor.getRainfall();     
  Rainfall_1h  = RainSensor.getRainfall(1);    


  // ===== DEBUG (pas en spam) =====
  if (millis() - lastPrint >= periodPrint) {
    lastPrint = millis();
    Serial.println("---- DONNEES ----");
    Serial.print("Temperature : "); Serial.println(temperature);
    Serial.print("Humidite    : "); Serial.println(humidite);
    Serial.print("Pluie totale: "); Serial.println(pluie);
    Serial.print("Pluie 1h    : "); Serial.println(Rainfall_1h);
    Serial.println("-----------------");
  }


  // ===== ENVOI toutes les 5 minutes =====
  if (millis() - lastSend >= periodSend) {
    lastSend = millis();

    float pluie_envoyee = Rainfall_1h;

    sendToRaspberry(temperature, humidite, pluie_envoyee);
  }
}


void sendToRaspberry(float t, float h, float r) {
  ensureWiFi();


  client.stop(); // ferme tout socket précédent


  if (!client.connect(server, port)) {
    Serial.println("Connexion serveur échouée");
    return;
  }


  // Convertir floats en texte 
  char ts[16], hs[16], rs[16];
  dtostrf(t, 0, 1, ts);
  dtostrf(h, 0, 1, hs);
  dtostrf(r, 0, 2, rs);


  // Construire l'URL dans un buffer fixe
  char url[220];
  snprintf(url, sizeof(url),
           "/api_insert.php?key=%s&temperature=%s&humidite=%s&pluie=%s",
           apiKey.c_str(), ts, hs, rs);


  // Requête HTTP
  client.print("GET ");
  client.print(url);
  client.print(" HTTP/1.1\r\nHost: ");
  client.print(server);
  client.print("\r\nConnection: close\r\n\r\n");


  // Lire la réponse (timeout court)
  unsigned long t0 = millis();
  while (client.connected() && millis() - t0 < 3000) {
    while (client.available()) {
      Serial.write(client.read());
      t0 = millis();
    }
  }


  client.stop();
  Serial.println("\nEnvoi terminé.\n");
}