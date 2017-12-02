//Rev0 - Soil temperature, moisture, and battery level reporting.  OTA update support.
//       HTTP post packet destination configurable via WiFi SoilTempAP.
//Rev1 - Deep Sleep supported on ESP12f.
//Rev2 - Retry effectively forever on failed data push attempts - wifi reconfig requires code change
// Credits to these include file/code providers:
#include <FS.h>                   //this needs to be first, or it all crashes and burns...

#include <ESP8266WiFi.h>          //https://github.com/esp8266/Arduino
#include <ESP8266mDNS.h>
#include <WiFiUdp.h>
#include <ArduinoOTA.h>

//needed for library
#include <DNSServer.h>
#include <ESP8266WebServer.h>
#include <WiFiManager.h>          //https://github.com/tzapu/WiFiManager

#include <ArduinoJson.h>          //https://github.com/bblanchon/ArduinoJson
#include <OneWire.h>
#include <DallasTemperature.h>

// Enable debugging monitoring
//#define DEBUG

#ifdef DEBUG
  #define DBG(message)    Serial.print(message)
  #define DBGL(message)    Serial.println(message)
  #define DBGW(message)    Serial.write(message)
  #define WRITE_LED
  #define TOGGLE_LED
  #define SET_LED(x)
#else
  #define DBG(message)
  #define DBGL(message)
  #define DBGW(message)
  #define LED_OUTPUT_LEVEL (led_on == true ? LOW : HIGH)
  #define WRITE_LED digitalWrite(LED_BUILTIN, LED_OUTPUT_LEVEL)
  #define TOGGLE_LED (led_on = !led_on)
  #define SET_LED(x) (led_on = x)
  bool led_on = true;
#endif        


//json defaults. If there are different values in config.json, they are overwritten.
const int host_max_len = 80;
char host[host_max_len] = {0};
const int url_max_len = 80;
char url[url_max_len] = {0};
const int send_fail_max_len = 3;
char send_fail[send_fail_max_len] = "0";
bool shouldSaveConfig = false;
bool update = false;

float vdd = 0.0;
int moisture = 0;
float temperature = -55.0;

const unsigned max_hours_for_AP_config = 1;  // after this time device idles and waits for pwr reset
const unsigned max_minutes_for_update = 20;
const unsigned int max_send_fail_cnt = 999;
unsigned int send_fail_cnt = 0;
bool send_data_success = false;
unsigned long update_start_time = 0;
const unsigned long sleep_time_in_ms = 30 * 60 * 1000;  // poll rate of temperature - 30 minutes
//const unsigned long sleep_time_in_ms = 2 * 60 * 1000;  // poll rate of temperature - test

#define VDD_MON_PIN 12  // Vdd read pin - connected via res divider to ADC input
#define MOISTURE_SENSOR_PWR_PIN 5
#define ONE_WIRE_BUS_PIN 2  // DS18B20 pin

OneWire oneWire(ONE_WIRE_BUS_PIN);
DallasTemperature DS18B20(&oneWire);

WiFiClient client;
const int httpPort = 8080;

const char * AP_pwd = "yourpwd";


//callback notifying us of the need to save config
void saveConfigCallback () {
  DBGL("Should save config");
  shouldSaveConfig = true;
}

void sleepCallback() {
#ifdef DEBUG
  DBGL();
  Serial.flush();
#endif
}

void connect(bool abort) {
  unsigned wait_cnt;
  const unsigned connect_seconds_max = 30;
    
  if(WiFi.status() != WL_CONNECTED) {
    DBGL("connecting WiFi...");
    //wifi_fpm_do_wakeup();
    WiFi.forceSleepWake();
    delay(100);
    wifi_fpm_close;
    wifi_set_sleep_type(MODEM_SLEEP_T);
    wifi_set_opmode(STATION_MODE);
    wifi_station_connect();

    wait_cnt = 0;
    while(wait_cnt++ < connect_seconds_max && WiFi.status() != WL_CONNECTED) {
      delay(1000);
      DBG(wait_cnt);
    }
    if(WiFi.status() != WL_CONNECTED) {
      DBGL("unable to connect - reset");
      if(abort) {
        ESP.reset();
        delay(3000);
      }
    }
  }
}

void disconnect() {
  DBGL("disconnecting WiFi...");
  wifi_set_opmode(NULL_MODE);
  wifi_fpm_open(); 
  wifi_fpm_set_wakeup_cb(sleepCallback);
  WiFi.forceSleepBegin();
  delay(100);
}

void get_vdd() {
  int adcraw;
  
  // configure to read vdd
  pinMode(VDD_MON_PIN, OUTPUT);
  delay(1000); // settle time
  adcraw = analogRead(A0);
  // documentation says 1V across 1023; I observe 1v across 1000 ??
  //float vdd = (adcraw * 16.7) / (4.7 * 1023); // 12K & 4.7K resistor divider
  vdd = (adcraw * 16.7) / (4.7 * 1000); // 12K & 4.7K resistor divider 

  pinMode(VDD_MON_PIN, INPUT); 
}

void get_moisture() {
  // configure to read vdd
  pinMode(MOISTURE_SENSOR_PWR_PIN, OUTPUT);
  delay(2000); // settle time
  moisture = analogRead(A0);
  pinMode(MOISTURE_SENSOR_PWR_PIN, INPUT); 
}

void get_temperature() {
  do {
    DS18B20.requestTemperatures(); 
    temperature = DS18B20.getTempCByIndex(0);
    DBG("Temperature: ");
    DBGL(temperature);
  } while (temperature == 85.0 || temperature == (-127.0));
}

bool send_data() {
  bool success = false;

  String senddata = "temperature=" + String(temperature) + "&moisture=" + String(moisture) +
                    "&batt=" + String(vdd) + "&fail=" + String(send_fail_cnt);

  DBG("Requesting URL: ");
  DBGL(url);
  DBGL(senddata);

  if (client.connect(host, httpPort)) {
    client.print(String("POST ") + url + " HTTP/1.1\r\n" +
                 "Host: " + host + "\r\n" + 
                 "Content-Type: application/x-www-form-urlencoded\r\n" + 
                 "Content-Length: " + senddata.length() + "\r\n\r\n" +
                 senddata  + "\r\n");
    while (client.connected()) {
      if (client.available()) {
        String line = client.readStringUntil('\n');
        DBGL(line);
        if (line.startsWith("success")) {
          success = true;
        } else if (line.startsWith("update")) {
          update = true;
          success = true;
          update_start_time = millis();
        }
      }
    }
    client.stop();
  }
  return success;
}

void get_config() {
  //clean FS, for testing
  //SPIFFS.format();

  //read configuration from FS json
  DBGL("mounting FS...");

  if (SPIFFS.begin()) {
    DBGL("mounted file system");
    if (SPIFFS.exists("/config.json")) {
      //file exists, reading and loading
      DBGL("reading config file");
      File configFile = SPIFFS.open("/config.json", "r");
      if (configFile) {
        DBGL("opened config file");
        size_t size = configFile.size();
        // Allocate a buffer to store contents of the file.
        std::unique_ptr<char[]> buf(new char[size]);

        configFile.readBytes(buf.get(), size);
        DynamicJsonBuffer jsonBuffer;
        JsonObject& json = jsonBuffer.parseObject(buf.get());
        json.printTo(Serial);
        if (json.success()) {
          DBGL("\nparsed json");
          strcpy(host, json["host"]);
          DBGL(host);
          strcpy(url, json["url"]);
          DBGL(url);
          strcpy(send_fail, json["send_fail"]);
          DBGL(send_fail);
        } else {
          DBGL("failed to load json config");
        }
      }
    }
  } else {
    DBGL("failed to mount FS");
    SPIFFS.format();
    DBGL("SPIFFS fortmatted !");
  }
}

void setup() {

WiFiManager wifiManager;
int hour_cnt = 0;
int send_fail_cnt;

#ifdef DEBUG
  Serial.begin(115200);
  // Wait for serial to initialize.
  while (!Serial) { }
  DBGL();
#else
  pinMode(LED_BUILTIN, OUTPUT);
  digitalWrite(LED_BUILTIN, LED_OUTPUT_LEVEL);
#endif

  // Cfg output logic state for eventual ADC reads; enable later
  pinMode(VDD_MON_PIN, INPUT);
  pinMode(MOISTURE_SENSOR_PWR_PIN, INPUT);
  digitalWrite(VDD_MON_PIN, HIGH);
  digitalWrite(MOISTURE_SENSOR_PWR_PIN, LOW);

  // Set modem sleep for accurate ADC reads
  disconnect();
  get_vdd();
  get_temperature();
  get_moisture();

  get_config();
  send_fail_cnt = atoi(send_fail);
  DBG("Send Fail Count ");
  DBGL(send_fail_cnt);
  
  connect(false); // Connect, no reset on fail
  
  // The extra parameters to be configured (can be either global or just in the setup)
  // After connecting, parameter.getValue() will get you the configured value
  // id/name placeholder/prompt default length
  WiFiManagerParameter custom_host("host", "Host Website ie. maker.ifttt.com", host, host_max_len);
  WiFiManagerParameter custom_url("url", "url", url, url_max_len);

  
#ifdef DEBUG
  wifiManager.setDebugOutput(true);
#else
  wifiManager.setDebugOutput(false);
#endif

  //set config save notify callback
  wifiManager.setSaveConfigCallback(saveConfigCallback);

  //set static ip
  //wifiManager.setSTAStaticIPConfig(IPAddress(10,0,1,99), IPAddress(10,0,1,1), IPAddress(255,255,255,0));
  
  //add all your parameters here
  wifiManager.addParameter(&custom_host);
  wifiManager.addParameter(&custom_url);

  //reset settings - for testing
  //wifiManager.resetSettings();

  //set minimu quality of signal so it ignores AP's under that quality
  //defaults to 8%
  //wifiManager.setMinimumSignalQuality();
  
  //sets timeout until configuration portal gets turned off
  //useful to make it all retry or go to sleep
  //in seconds
  
  wifiManager.setTimeout(10*60);  // 10 minutes

  //fetches ssid and pass and tries to send temperature
  //if it does not connect it starts an access point with the specified name
  //here  "AutoConnectAP"
  //and goes into a blocking loop awaiting configuration
  while(hour_cnt++ < max_hours_for_AP_config) {
    //if (wifiManager.autoConnect("SoilTempAP")) {
    if (wifiManager.autoConnect("SoilTempAP", AP_pwd)) {
      strcpy(host, custom_host.getValue());
      strcpy(url, custom_url.getValue());
    } 
  }

  if (WiFi.status() == WL_CONNECTED) {
    send_data_success = send_data();
    ArduinoOTA.begin();
  }
  // flag json update if needed
  if (WiFi.status() != WL_CONNECTED || send_data_success == false) {
    send_fail_cnt++;
    shouldSaveConfig = true;
    if(send_fail_cnt >= max_send_fail_cnt) {
      send_fail_cnt = 0;
    }
  }

  if (shouldSaveConfig) {
    DBGL("saving config");
    DynamicJsonBuffer jsonBuffer;
    JsonObject& json = jsonBuffer.createObject();
    json["host"] = host;
    json["url"] = url;
    itoa(send_fail_cnt, send_fail, 10);
    json["send_fail"] = send_fail;

    File configFile = SPIFFS.open("/config.json", "w");
    if (!configFile) {
      DBGL("failed to open config file for writing");
    }

    json.printTo(Serial);
    json.printTo(configFile);
    configFile.close();
    //end save
  }
  if(!update) {
    if (WiFi.status() == WL_CONNECTED && send_data_success == false) {
      delay(20000); // Keep WiFi active for possible update
    }
    disconnect();
    ESP.deepSleep(sleep_time_in_ms * 1000);
    // no code path here - resets after timeout
  }
}

void loop() {
  if( millis() - update_start_time < max_minutes_for_update*60*1000 ) {
    ArduinoOTA.handle();
  } else {
    ESP.reset(); // jump out of OTA mode - todo: danger while active
    delay(3000);
  }
}





