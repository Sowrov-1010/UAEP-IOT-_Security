/*
  UAEP-HEA-SC — ESP32 #2 Sensor and Safety Controller

  Provides GET /api/test and GET /api/status for the XAMPP dashboard.
  Sensors: PIR, HC-SR04-compatible ultrasonic, analog gas module, digital
  flame sensor, DHT22, analog LDR divider and LED. Active buzzer alerts for
  fire, gas threshold, and nearby obstacle.

  Install library: "DHT sensor library" by Adafruit (Library Manager).
  WiFi.h and WebServer.h are included with the Espressif ESP32 board package.
*/

#include <WiFi.h>
#include <WebServer.h>
#include <DHT.h>



// ==========================================
// 1. WIFI CONFIGURATION
// ==========================================

// Enter the same 2.4 GHz Wi-Fi network used by the XAMPP computer.
const char* ssid = "Rimon Bhowmik";
const char* password = "rimon@2020";

WebServer server(80);

// ==========================================
// 2. SENSOR PIN CONFIGURATION
// ==========================================

#define PIR_PIN       27

#define TRIG_PIN      5
#define ECHO_PIN      18

#define GAS_PIN       34

#define FIRE_PIN      26

#define DHT_PIN       4
#define DHT_TYPE      DHT22

#define LDR_PIN       35

#define LED_PIN       25

#define BUZZER_PIN    14

DHT dht(DHT_PIN, DHT_TYPE);

// ==========================================
// 3. SENSOR SETTINGS
// ==========================================

#define GAS_THRESHOLD       1800
#define LDR_DARK_THRESHOLD  1500
#define DISTANCE_THRESHOLD  50
#define MIN_VALID_DISTANCE  2.0f
#define MAX_VALID_DISTANCE  400.0f

// Fire module DO is assumed active LOW.
#define FIRE_ACTIVE LOW

// ==========================================
// 4. SENSOR VARIABLES
// ==========================================

int pirStatus = 0;
int gasValue = 0;
int fireStatus = 0;
int ldrValue = 0;

float temperature = 0;
float humidity = 0;
float distance = 0;

bool motionDetected = false;
bool gasDetected = false;
bool fireDetected = false;
bool lowLight = false;
bool objectNear = false;

bool dhtValid = false;

// ==========================================
// 5. TIMING VARIABLES
// ==========================================

unsigned long previousLEDTime = 0;
unsigned long previousBuzzerTime = 0;
unsigned long previousDHTTime = 0;

bool ledState = false;
bool buzzerState = false;

const unsigned long LED_INTERVAL = 500;
const unsigned long BUZZER_INTERVAL = 250;
const unsigned long DHT_INTERVAL = 2000;

// ==========================================
// 6. ULTRASONIC SENSOR FUNCTION
// ==========================================

float readDistance() {

  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);

  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);

  digitalWrite(TRIG_PIN, LOW);

  long duration = pulseIn(ECHO_PIN, HIGH, 30000);

  if (duration == 0) {
    return -1;
  }

  float cm = duration * 0.0343f / 2.0f;
  // Ignore crosstalk/stuck echo; those values must not trigger the buzzer.
  if (cm < MIN_VALID_DISTANCE || cm > MAX_VALID_DISTANCE) return -1.0f;
  return cm;
}

// ==========================================
// 7. READ ALL SENSORS
// ==========================================

void readSensors() {

  // PIR
  pirStatus = digitalRead(PIR_PIN);
  motionDetected = (pirStatus == HIGH);

  // Gas
  gasValue = analogRead(GAS_PIN);
  gasDetected = (gasValue >= GAS_THRESHOLD);

  // Fire
  fireStatus = digitalRead(FIRE_PIN);
  fireDetected = (fireStatus == FIRE_ACTIVE);

  // Ultrasonic
  distance = readDistance();

  objectNear = (distance >= MIN_VALID_DISTANCE &&
                distance <= DISTANCE_THRESHOLD);

  // LDR
  ldrValue = analogRead(LDR_PIN);
  lowLight = (ldrValue < LDR_DARK_THRESHOLD);

}

// ==========================================
// 8. DHT22 READING
// ==========================================

void readDHT() {

  if (millis() - previousDHTTime >= DHT_INTERVAL) {

    previousDHTTime = millis();

    float h = dht.readHumidity();
    float t = dht.readTemperature();

    if (!isnan(h) && !isnan(t)) {

      humidity = h;
      temperature = t;
      dhtValid = true;

    } else {

      dhtValid = false;

      Serial.println("DHT22 reading failed!");

    }
  }
}

// ==========================================
// 9. AUTOMATIC LED CONTROL
// ==========================================

void controlLED() {

  if (lowLight) {

    if (millis() - previousLEDTime >= LED_INTERVAL) {

      previousLEDTime = millis();

      ledState = !ledState;

      digitalWrite(LED_PIN, ledState);

    }

  } else {

    ledState = false;

    digitalWrite(LED_PIN, LOW);

  }
}

// ==========================================
// 10. BUZZER CONTROL
// ==========================================

void controlBuzzer() {

  // Fire or dangerous gas:
  // Continuous alarm

  if (fireDetected || gasDetected) {

    buzzerState = true;

    digitalWrite(BUZZER_PIN, HIGH);

  }

  // Object detected within 50 cm:
  // Intermittent alarm

  else if (objectNear) {

    if (millis() - previousBuzzerTime >= BUZZER_INTERVAL) {

      previousBuzzerTime = millis();

      buzzerState = !buzzerState;

      digitalWrite(BUZZER_PIN, buzzerState);

    }

  }

  // No emergency

  else {

    buzzerState = false;

    digitalWrite(BUZZER_PIN, LOW);

  }
}

// ==========================================
// 11. SERIAL MONITOR
// ==========================================

void printStatus() {

  static unsigned long previousPrint = 0;

  if (millis() - previousPrint >= 2000) {

    previousPrint = millis();

    Serial.println("\n========== SENSOR STATUS ==========");

    Serial.print("Motion: ");
    Serial.println(motionDetected ? "DETECTED" : "CLEAR");

    Serial.print("Gas Value: ");
    Serial.println(gasValue);

    Serial.print("Gas Status: ");
    Serial.println(gasDetected ? "DANGER" : "NORMAL");

    Serial.print("Fire Status: ");
    Serial.println(fireDetected ? "FIRE DETECTED" : "SAFE");

    Serial.print("Distance: ");
    Serial.print(distance);
    Serial.println(" cm");

    Serial.print("Temperature: ");
    if (dhtValid) Serial.print(temperature);
    else Serial.print("N/A");
    Serial.println(" C");

    Serial.print("Humidity: ");
    if (dhtValid) Serial.print(humidity);
    else Serial.print("N/A");
    Serial.println(" %");

    Serial.print("LDR Value: ");
    Serial.println(ldrValue);

    Serial.print("Light: ");
    Serial.println(lowLight ? "DARK" : "BRIGHT");

    Serial.println("===================================");
  }
}

// ==========================================
// 12. WEB DASHBOARD HTML
// ==========================================

const char webpage[] PROGMEM = R"rawliteral(

<!DOCTYPE html>
<html>
<head>

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>ESP32 IoT Monitoring System</title>

<style>

* {
  box-sizing: border-box;
}

body {
  font-family: Arial;
  background: #eef2f7;
  margin: 0;
  padding: 15px;
  color: #1f2937;
}

header {
  background: #172554;
  color: white;
  text-align: center;
  padding: 22px;
  border-radius: 12px;
}

h1 {
  font-size: 23px;
}

.container {
  max-width: 1000px;
  margin: auto;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 15px;
  margin-top: 20px;
}

.card {
  background: white;
  padding: 20px;
  border-radius: 12px;
  box-shadow: 0 3px 10px rgba(0,0,0,0.08);
}

.card h3 {
  margin-top: 0;
  color: #475569;
}

.value {
  font-size: 25px;
  font-weight: bold;
  margin: 15px 0;
}

.status {
  font-size: 14px;
  font-weight: bold;
}

.safe {
  color: green;
}

.danger {
  color: red;
}

footer {
  text-align: center;
  margin-top: 25px;
  color: #64748b;
}

</style>

</head>

<body>

<div class="container">

<header>
  <h1>ESP32 IoT Monitoring System</h1>
  <p>Real-Time Smart Environment Monitoring</p>
  <p>Wi-Fi Sensor Dashboard</p>
</header>

<div class="grid">

<div class="card">
<h3>Motion Detection</h3>
<div class="value" id="pir">--</div>
<div class="status" id="pirStatus">Loading...</div>
</div>

<div class="card">
<h3>Ultrasonic Distance</h3>
<div class="value" id="distance">--</div>
<div class="status" id="distanceStatus">Loading...</div>
</div>

<div class="card">
<h3>Gas Sensor</h3>
<div class="value" id="gas">--</div>
<div class="status" id="gasStatus">Loading...</div>
</div>

<div class="card">
<h3>Fire Detection</h3>
<div class="value" id="fire">--</div>
<div class="status" id="fireStatus">Loading...</div>
</div>

<div class="card">
<h3>Temperature</h3>
<div class="value" id="temperature">--</div>
<div class="status">DHT22 Sensor</div>
</div>

<div class="card">
<h3>Humidity</h3>
<div class="value" id="humidity">--</div>
<div class="status">DHT22 Sensor</div>
</div>

<div class="card">
<h3>Light Level (LDR)</h3>
<div class="value" id="ldr">--</div>
<div class="status" id="ldrStatus">Loading...</div>
</div>

<div class="card">
<h3>LED Status</h3>
<div class="value" id="led">--</div>
<div class="status">Automatic Light Control</div>
</div>

<div class="card">
<h3>Buzzer</h3>
<div class="value" id="buzzer">--</div>
<div class="status" id="buzzerStatus">Loading...</div>
</div>

</div>

<footer>
  ESP32 Second Sensor Node | Live Monitoring
  <p id="connection">Connecting...</p>
</footer>

</div>

<script>

function setStatus(id, text, danger) {

  const element = document.getElementById(id);

  element.innerText = text;

  element.className = "status " +
      (danger ? "danger" : "safe");

}

async function updateDashboard() {

  try {

    const response = await fetch('/api/data');

    const d = await response.json();

    document.getElementById('pir').innerText =
      d.motion ? "DETECTED" : "CLEAR";

    setStatus('pirStatus',
      d.motion ? "Movement detected" : "No movement",
      d.motion);

    document.getElementById('distance').innerText =
      d.distance < 0 ? "No Echo" : d.distance.toFixed(1) + " cm";

    setStatus('distanceStatus',
      d.near ? "OBJECT TOO CLOSE" : "NORMAL",
      d.near);

    document.getElementById('gas').innerText = d.gas;

    setStatus('gasStatus',
      d.gasDetected ? "GAS ALERT" : "NORMAL",
      d.gasDetected);

    document.getElementById('fire').innerText =
      d.fire ? "FIRE!" : "SAFE";

    setStatus('fireStatus',
      d.fire ? "FIRE DETECTED" : "NO FIRE",
      d.fire);

    document.getElementById('temperature').innerText =
      d.dhtValid ? d.temperature.toFixed(1) + " °C" : "N/A";

    document.getElementById('humidity').innerText =
      d.dhtValid ? d.humidity.toFixed(1) + " %" : "N/A";

    document.getElementById('ldr').innerText = d.ldr;

    setStatus('ldrStatus',
      d.dark ? "LOW LIGHT" : "BRIGHT",
      d.dark);

    document.getElementById('led').innerText =
      d.led ? "ON" : "OFF";

    document.getElementById('buzzer').innerText =
      d.buzzer ? "ON" : "OFF";

    setStatus('buzzerStatus',
      d.fire || d.gasDetected ? "EMERGENCY ALARM" :
      d.near ? "DISTANCE ALERT" : "NORMAL",
      d.buzzer);

    document.getElementById('connection').innerText =
      "Connected | Live data updating";

  } catch (error) {

    document.getElementById('connection').innerText =
      "Connection Lost!";

  }

}

setInterval(updateDashboard, 1000);

updateDashboard();

</script>

</body>
</html>

)rawliteral";

// ==========================================
// 13. JSON API
// ==========================================

void handleData() {

  String json = "{";

  json += "\"motion\":" + String(motionDetected ? "true" : "false") + ",";
  json += "\"distance\":" + String(distance, static_cast<unsigned int>(1)) + ",";
  json += "\"near\":" + String(objectNear ? "true" : "false") + ",";
  json += "\"gas\":" + String(gasValue) + ",";
  json += "\"gasDetected\":" + String(gasDetected ? "true" : "false") + ",";
  json += "\"fire\":" + String(fireDetected ? "true" : "false") + ",";
  json += "\"temperature\":" + String(temperature, static_cast<unsigned int>(1)) + ",";
  json += "\"humidity\":" + String(humidity, static_cast<unsigned int>(1)) + ",";
  json += "\"dhtValid\":" + String(dhtValid ? "true" : "false") + ",";
  json += "\"ldr\":" + String(ldrValue) + ",";
  json += "\"dark\":" + String(lowLight ? "true" : "false") + ",";
  json += "\"led\":" + String(ledState ? "true" : "false") + ",";
  json += "\"buzzer\":" + String(buzzerState ? "true" : "false");

  json += "}";

  server.sendHeader("Access-Control-Allow-Origin", "*");

  server.send(200, "application/json", json);
}

// XAMPP dashboard integration endpoint. Keep this JSON API separate from the
// standalone /api/data endpoint used by the webpage stored on the ESP32.
void handleStatus() {
  String json = "{";
  json += "\"success\":true";
  json += ",\"device\":\"UAEP Sensor Safety ESP32\"";
  json += ",\"wifi\":" + String(WiFi.status() == WL_CONNECTED ? "true" : "false");
  json += ",\"ip\":\"" + WiFi.localIP().toString() + "\"";
  json += ",\"pir\":" + String(motionDetected ? "true" : "false");
  json += ",\"pirMotion\":" + String(motionDetected ? "true" : "false");
  json += ",\"distanceCm\":" + String(distance, static_cast<unsigned int>(1));
  json += ",\"ultrasonicOk\":" + String(distance >= MIN_VALID_DISTANCE ? "true" : "false");
  json += ",\"obstacle\":" + String(objectNear ? "true" : "false");
  json += ",\"gasRaw\":" + String(gasValue);
  json += ",\"gasAlarm\":" + String(gasDetected ? "true" : "false");
  json += ",\"fire\":" + String(fireDetected ? "true" : "false");
  json += ",\"fireDetected\":" + String(fireDetected ? "true" : "false");
  json += ",\"fireLevel\":" + String(fireStatus);
  json += ",\"temperatureC\":";
  json += dhtValid ? String(temperature, static_cast<unsigned int>(1)) : "null";
  json += ",\"humidityPct\":";
  json += dhtValid ? String(humidity, static_cast<unsigned int>(1)) : "null";
  json += ",\"dhtOk\":" + String(dhtValid ? "true" : "false");
  json += ",\"lightRaw\":" + String(ldrValue);
  json += ",\"ldrRaw\":" + String(ldrValue);
  json += ",\"dark\":" + String(lowLight ? "true" : "false");
  json += ",\"isDark\":" + String(lowLight ? "true" : "false");
  json += ",\"led\":" + String(ledState ? "true" : "false");
  json += ",\"streetLedOn\":" + String(ledState ? "true" : "false");
  bool alarmActive = fireDetected || gasDetected || objectNear;
  json += ",\"alarm\":" + String(alarmActive ? "true" : "false");
  json += ",\"buzzerOn\":" + String(buzzerState ? "true" : "false");
  String reason = fireDetected ? "fire" : gasDetected ? "gas" : objectNear ? "obstacle" : "none";
  json += ",\"alarmReason\":\"" + reason + "\"}";
  server.sendHeader("Cache-Control", "no-store");
  server.send(200, "application/json", json);
}

// ==========================================
// 14. WIFI AND WEB SERVER
// ==========================================

void setupWiFi() {

  WiFi.mode(WIFI_STA);

  WiFi.begin(ssid, password);

  Serial.print("Connecting to WiFi");

  unsigned long startAttempt = millis();

  while (WiFi.status() != WL_CONNECTED &&
         millis() - startAttempt < 20000) {

    delay(500);

    Serial.print(".");

  }

  if (WiFi.status() == WL_CONNECTED) {

    Serial.println("\nWiFi Connected!");

    Serial.print("ESP32 IP Address: ");

    Serial.println(WiFi.localIP());

  } else {

    Serial.println("\nWiFi connection failed.");

  }
}

// ==========================================
// 15. SETUP
// ==========================================

void setup() {

  Serial.begin(115200);

  pinMode(PIR_PIN, INPUT);

  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);

  pinMode(FIRE_PIN, INPUT_PULLUP);

  pinMode(LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  digitalWrite(TRIG_PIN, LOW);
  digitalWrite(LED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);

  analogReadResolution(12);

  dht.begin();

  Serial.println("\nESP32 IoT Sensor System Starting...");

  // Connect WiFi
  setupWiFi();

  // Web server routes
  server.on("/", HTTP_GET, []() {

    server.send_P(200, "text/html", webpage);

  });

  server.on("/api/data", HTTP_GET, handleData);
  server.on("/api/status", HTTP_GET, handleStatus);
  server.on("/api/test", HTTP_GET, []() {
    String json = "{\"success\":true,\"message\":\"ESP32 IoT System Online\",\"ip\":\"";
    json += WiFi.localIP().toString() + "\"}";
    server.sendHeader("Cache-Control", "no-store");
    server.send(200, "application/json", json);
  });

  server.begin();

  Serial.println("Web Server Started!");

  analogSetPinAttenuation(GAS_PIN, ADC_11db);
  analogSetPinAttenuation(LDR_PIN, ADC_11db);
  readSensors();

  previousDHTTime = millis() - DHT_INTERVAL;

  readDHT();

}

// ==========================================
// 16. MAIN LOOP
// ==========================================

void loop() {

  if (WiFi.status() == WL_CONNECTED) {

    server.handleClient();

  } else {

    static unsigned long lastReconnect = 0;

    if (millis() - lastReconnect >= 10000) {

      lastReconnect = millis();

      WiFi.disconnect();

      WiFi.begin(ssid, password);

    }

  }

  static unsigned long lastSensorSample = 0;
  if (millis() - lastSensorSample >= 250) {
    lastSensorSample = millis();
    readSensors();
  }

  readDHT();

  controlLED();

  controlBuzzer();

  printStatus();

}

