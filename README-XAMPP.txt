UAEP SMART ACCESS & SAFETY — XAMPP + FIRESTORE

PROJECT FILES
   index.html                     Dashboard page
   assets/style.css               Page design and responsive layout
   assets/app.js                  Browser behavior and API requests
   api.php                        PHP Firestore backend and ESP32 proxy
   migrate_mysql_to_firestore.php One-time importer for an existing MySQL DB

INSTALL / RUN
1. Copy the project into C:\xampp\htdocs\uaep-xampp\.
2. Install PHP 8.1+ and Composer, then run `composer install` in the project
   folder. XAMPP's PHP executable is usually C:\xampp\php\php.exe.
3. Create a Firebase project and a Cloud Firestore database in Native mode.
4. Create a Firebase service-account key in Firebase project settings and give
   the service account Firestore access. Store its JSON file outside htdocs,
   for example C:\xampp\firebase\uaep-service.json.
   Never put this key in the project, expose it to browser JavaScript, or commit
   it to source control.
5. Set the Apache environment variable FIREBASE_CREDENTIALS to the full path of
   that JSON key. For XAMPP, add `SetEnv FIREBASE_CREDENTIALS "C:/xampp/firebase/uaep-service.json"`
   to Apache configuration and restart Apache. The Admin SDK accesses Firestore
   on the server.
6. Open http://localhost/uaep-xampp/ and add each ESP32 using its IP address.
   Select Access control for the RFID/fingerprint board.

EXISTING MYSQL DATA
If the existing MySQL database contains data to keep, configure its connection
with UAEP_DB_HOST, UAEP_DB_NAME, UAEP_DB_USER, and UAEP_DB_PASS if they differ
from the XAMPP defaults. In PowerShell, change to the project folder and run
the importer once before switching users to the dashboard:

   cd C:\xampp\htdocs\uaep-xampp
  C:\xampp\php\php.exe migrate_mysql_to_firestore.php

The importer preserves MySQL document IDs and device references. It can be run
again to overwrite the same documents. Verify the Firestore collections before
retiring MySQL. The importer does not change or delete the MySQL database.
If a device-filtered readings query reports a missing index, follow the link
in the Firestore error to create an index on device_id and sampled_at.

FIRESTORE COLLECTIONS
  devices          ESP32 configuration and connection state
  events           Dashboard audit events
  sensor_readings  Sensor history and complete ESP32 JSON payloads

The dashboard polls each device every five seconds while open. Use Download all
readings (CSV) to export sensor history. Firestore quotas and billing apply;
consider retention rules if the sensor history grows significantly.

ESP32 FIRMWARE ENDPOINTS
Every board needs GET /api/test and GET /api/status returning JSON.
The selected access-control board also needs the endpoints below:
  GET /api/register?id=1
  GET /api/check
  GET /api/delete?id=1
  GET /api/rfid/add?uid=4110DD0A&name=Owner
  GET /api/rfid/list
  GET /api/rfid/delete?uid=4110DD0A

The PHP backend requests the ESP32 APIs, so browser CORS is not needed. Keep the
XAMPP computer and ESP32 boards on a network that can reach each other. This
project does not include user login; do not expose its PHP API to the public
internet without adding authentication and authorization.

SECOND ESP32 SENSOR BOARD
The starter Arduino sketch is firmware/esp32_sensor_controller.ino. Select a
classic ESP32 Dev Module, install the Adafruit DHT sensor library (and its
Adafruit Unified Sensor dependency), set the Wi-Fi name/password placeholders,
and upload it. It uses the same /api/test and /api/status routes as the
dashboard expects. Wiring, thresholds, and voltage notes are in
firmware/WIRING.txt. The dashboard displays sensor data returned by /api/status.
