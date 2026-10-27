/*
 * Aquify — ESP32 -> Firebase Realtime Database (REST)
 * Website Aquify menarik data dari path /measurements (geolistrik) dan /water (kualitas air).
 *
 * Alur:
 *   - Geolistrik: tekan tombol BOOT (atau ketik GEO di Serial Monitor) -> ukur pada jarak elektroda
 *     aktif -> kirim -> jarak maju ke nilai berikutnya di SPACINGS.
 *   - Air: dikirim otomatis tiap WATER_INTERVAL_MS.
 *   - Tanpa Wi-Fi/Firebase: data ditahan di antrean RAM (maks QMAX) dan dikirim ulang.
 *
 * Perintah Serial (115200, newline): POINT GL-024 | SPACING 5 | POS 12 (posisi x, m) | POS OFF | GEO | WATER | STATUS
 *
 * PENTING: fungsi readGeo() dan readWater() harus Anda isi sesuai rangkaian sensor Anda.
 * Selama TEST_MODE = 1 sketsa hanya mengirim data UJI berlabel (device "AQ-TEST", titik "TEST-001"),
 * bukan hasil pengukuran. Setel TEST_MODE = 0 setelah sensor siap.
 */
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <time.h>
#include <math.h>

// ====== KONFIGURASI (ISI) ======
#define TEST_MODE 1
const char* WIFI_SSID        = "NAMA_WIFI";
const char* WIFI_PASS        = "PASSWORD_WIFI";
const char* FIREBASE_API_KEY = "ISI_WEB_API_KEY";                         // Project settings > General
const char* FIREBASE_DB_URL  = "https://smart-groundwater-exploration-default-rtdb.asia-southeast1.firebasedatabase.app"; // tanpa "/" di akhir
const char* FIREBASE_EMAIL   = "esp32@aquify.local";                      // akun khusus perangkat (Authentication > Users)
const char* FIREBASE_PASS    = "PASSWORD_AKUN_PERANGKAT";
const char* DEVICE_ID        = "AQ-ESP32-01";
String      pointCode        = "GL-001";                                  // kode titik survey di web (bisa diganti via Serial)
const float SPACINGS[]       = {1, 2, 3, 5, 8, 12, 18, 27};               // jarak elektroda (m) berurutan
const unsigned long WATER_INTERVAL_MS = 30000UL;
const bool  SEND_WATER       = true;
const int   BUTTON_PIN       = 0;                                         // tombol BOOT
const int   BAT_PIN          = -1;                                        // ADC baterai (pembagi tegangan); -1 = tidak dipakai
const float BAT_DIVIDER      = 2.0f;                                      // rasio pembagi tegangan
// =================================

const int N_SP = sizeof(SPACINGS) / sizeof(SPACINGS[0]);
int spIdx = 0;
float posX = NAN;  // posisi pusat susunan di lintasan (m); NAN = tidak dikirim. Isi untuk profiling 2D (pseudosection).
String idToken;
unsigned long tokenAt = 0, lastWater = 0, lastFlush = 0;
const int QMAX = 16;
String qPath[QMAX], qBody[QMAX];
int qn = 0;
bool lastBtn = true;

// ---------- Sensor: ISI SESUAI PERANGKAT KERAS ----------
// Kembalikan true bila pembacaan valid. dv_mv dan i_ma dalam mV dan mA (nilai mutlak).
bool readGeo(float &dv_mv, float &i_ma) {
#if TEST_MODE
  dv_mv = 120.0f / (1.0f + SPACINGS[spIdx]);  // nilai UJI, bukan pengukuran
  i_ma = 10.0f;
  return true;
#else
  // TODO: baca ADC tegangan potensial (mV) dan arus injeksi (mA), rata-ratakan beberapa sampel.
  return false;
#endif
}
struct Water { float ph, tds, ec, temp, turb; };
bool readWater(Water &w) {
#if TEST_MODE
  w.ph = 7.0f; w.tds = 200; w.ec = 320; w.temp = 27.0f; w.turb = 1.5f;  // nilai UJI
  return true;
#else
  // TODO: baca sensor pH, TDS/EC, suhu (mis. DS18B20), kekeruhan; kompensasi suhu; kalibrasi sesuai modul.
  return false;
#endif
}
// ---------------------------------------------------------

float readBatteryPct() {
  if (BAT_PIN < 0) return -1;
  float v = analogReadMilliVolts(BAT_PIN) * BAT_DIVIDER;  // mV
  float pct = (v - 3300.0f) / (4200.0f - 3300.0f) * 100.0f;
  return constrain(pct, 0.0f, 100.0f);
}

void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 20000UL) delay(250);
  if (WiFi.status() == WL_CONNECTED) {
    configTime(0, 0, "pool.ntp.org", "time.google.com");  // epoch UTC; server Aquify mengonversi ke zona lokal
    Serial.printf("WiFi OK, IP %s\n", WiFi.localIP().toString().c_str());
  } else Serial.println("WiFi gagal");
}

bool signIn() {
  WiFiClientSecure c; c.setInsecure();  // untuk produksi: pasang sertifikat root (c.setCACert)
  HTTPClient h;
  String url = String("https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=") + FIREBASE_API_KEY;
  if (!h.begin(c, url)) return false;
  h.addHeader("Content-Type", "application/json");
  int code = h.POST(String("{\"email\":\"") + FIREBASE_EMAIL + "\",\"password\":\"" + FIREBASE_PASS + "\",\"returnSecureToken\":true}");
  String res = h.getString();
  h.end();
  if (code != 200) { Serial.printf("Login Firebase gagal (HTTP %d)\n", code); return false; }
  int i = res.indexOf("\"idToken\"");
  if (i < 0) return false;
  int a = res.indexOf('"', res.indexOf(':', i) + 1) + 1;
  int b = res.indexOf('"', a);
  if (a <= 0 || b <= a) return false;
  idToken = res.substring(a, b);
  tokenAt = millis();
  return true;
}
bool ensureToken() {
  if (idToken.length() && millis() - tokenAt < 50UL * 60UL * 1000UL) return true;
  return signIn();
}

// Kembalikan kode HTTP (200 = berhasil, <=0 = gagal jaringan).
int firebasePost(const String &path, const String &body) {
  if (WiFi.status() != WL_CONNECTED || !ensureToken()) return -1;
  WiFiClientSecure c; c.setInsecure();
  HTTPClient h;
  String url = String(FIREBASE_DB_URL) + "/" + path + ".json?auth=" + idToken;
  if (!h.begin(c, url)) return -1;
  h.addHeader("Content-Type", "application/json");
  int code = h.POST(body);
  h.end();
  if (code == 401) idToken = "";  // token kedaluwarsa -> login ulang pada percobaan berikutnya
  return code;
}
void enqueue(const String &path, const String &body) {
  if (qn == QMAX) { for (int i = 1; i < QMAX; i++) { qPath[i - 1] = qPath[i]; qBody[i - 1] = qBody[i]; } qn--; }  // buang yang tertua
  qPath[qn] = path; qBody[qn] = body; qn++;
}
void flushQueue() {
  while (qn > 0) {
    int code = firebasePost(qPath[0], qBody[0]);
    if (code != 200) { Serial.printf("Kirim tertunda (HTTP %d), antrean %d\n", code, qn); return; }
    for (int i = 1; i < qn; i++) { qPath[i - 1] = qPath[i]; qBody[i - 1] = qBody[i]; }
    qn--;
    Serial.println("Terkirim dari antrean");
  }
}
void send(const String &path, const String &body) {
  int code = (qn == 0) ? firebasePost(path, body) : -1;
  if (code == 200) { Serial.println("Terkirim: " + path); return; }
  Serial.printf("Ditahan di antrean (HTTP %d)\n", code);
  enqueue(path, body);
}

String commonTail() {
  String s = String(",\"device\":\"") + (TEST_MODE ? "AQ-TEST" : DEVICE_ID) + "\"";
  time_t now = time(nullptr);
  if (now > 1700000000) s += String(",\"ts\":") + (unsigned long)now;  // tanpa NTP -> server memakai waktu terima
  if (WiFi.status() == WL_CONNECTED) s += String(",\"rssi\":") + WiFi.RSSI();
  float bat = readBatteryPct();
  if (bat >= 0) s += String(",\"battery\":") + String(bat, 0);
  return s;
}
String pointForSend(const char *testCode) { return TEST_MODE ? String(testCode) : pointCode; }

void sendGeo() {
  float dv, i;
  if (!readGeo(dv, i) || isnan(dv) || isnan(i) || i <= 0) { Serial.println("Pembacaan geolistrik tidak valid, tidak dikirim"); return; }
  float a = SPACINGS[spIdx];
  String body = String("{\"point\":\"") + pointForSend("TEST-001") + "\",\"config\":\"wenner\",\"spacing\":" + String(a, 2) +
                ",\"dv_mv\":" + String(fabsf(dv), 3) + ",\"i_ma\":" + String(fabsf(i), 3) + (isnan(posX) ? "" : String(",\"x\":") + String(posX, 2)) + commonTail() + "}";
  send("measurements", body);
  spIdx = (spIdx + 1) % N_SP;
  Serial.printf("Jarak berikutnya: %.1f m\n", SPACINGS[spIdx]);
}
void sendWater() {
  Water w;
  if (!readWater(w)) { Serial.println("Pembacaan air tidak valid, tidak dikirim"); return; }
  if (isnan(w.ph) || w.ph < 0 || w.ph > 14) { Serial.println("pH di luar 0-14, tidak dikirim"); return; }
  String body = String("{\"point\":\"") + pointForSend("TEST-KA") + "\",\"ph\":" + String(w.ph, 2) + ",\"tds\":" + String(w.tds, 0) +
                ",\"ec\":" + String(w.ec, 0) + ",\"temp\":" + String(w.temp, 1) + ",\"turbidity_ntu\":" + String(w.turb, 2) + commonTail() + "}";
  send("water", body);
}

void handleSerial() {
  if (!Serial.available()) return;
  String line = Serial.readStringUntil('\n'); line.trim();
  if (line.startsWith("POINT ")) { pointCode = line.substring(6); pointCode.trim(); spIdx = 0; Serial.println("Titik: " + pointCode); }
  else if (line.startsWith("SPACING ")) { float v = line.substring(8).toFloat(); for (int k = 0; k < N_SP; k++) if (fabsf(SPACINGS[k] - v) < 0.001f) spIdx = k; Serial.printf("Jarak aktif: %.1f m\n", SPACINGS[spIdx]); }
  else if (line.startsWith("POS ")) { String v = line.substring(4); v.trim(); if (v == "OFF") posX = NAN; else posX = v.toFloat(); Serial.println(isnan(posX) ? "Posisi x: tidak dikirim" : "Posisi x: " + String(posX, 2) + " m"); }
  else if (line == "GEO") sendGeo();
  else if (line == "WATER") sendWater();
  else if (line == "STATUS") Serial.printf("WiFi=%d titik=%s jarak=%.1f antrean=%d\n", WiFi.status() == WL_CONNECTED, pointCode.c_str(), SPACINGS[spIdx], qn);
}

void setup() {
  Serial.begin(115200);
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  Serial.println(TEST_MODE ? "Aquify ESP32 — MODE UJI (data bukan pengukuran)" : "Aquify ESP32");
  connectWiFi();
}
void loop() {
  connectWiFi();
  handleSerial();
  bool btn = digitalRead(BUTTON_PIN);
  if (lastBtn && !btn) { delay(40); if (!digitalRead(BUTTON_PIN)) sendGeo(); }
  lastBtn = btn;
  if (SEND_WATER && millis() - lastWater >= WATER_INTERVAL_MS) { lastWater = millis(); sendWater(); }
  if (qn > 0 && millis() - lastFlush >= 10000UL) { lastFlush = millis(); flushQueue(); }
  delay(10);
}
