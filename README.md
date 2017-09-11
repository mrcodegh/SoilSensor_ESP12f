# SoilSensor_ESP12f
Soil temperature and moisture reporting using ESP8266 12f.

Periodic (30 minute) reporting of soil temperature, moisture, and ESP8266 supply
voltage to a configurable host and URL. Configuration is done via connecting to
WiFi Access Point SoilTempAP.  The 12f will go into access point mode on initial use
or if 4 consecutive data sends fail. Uses deep sleep mode for ~20uA
standby current or 70mA in AP mode.  Supports OTA update if the receiving host php file
is modified to return "update" rather than success on a data http POST.
See schematic for hardware details. In my application I am using the
Starry Light Solar light string for power.  This is way overkill (solar cell covered most of the time) 
providing 100s of mAh where only 10s mAh are needed.  3 AA or AAA
non rechargeable cells should provide many months via ESP deep sleep. Moisture
sensor is capacitive type SEN0193 (not fully implemented yet).

This is part of my Weather Underground reporting solution.  The soil
sensor sends http POST packets to Bit Web Server running on my
Cloud Pic cell phone:
https://github.com/mrcodegh/WU-Cloud-Cam-Pics-via-Android.
See php files in this repo (SoilSensor_ESP12f) for how soil data is combined with
WS1400-IP weather station data and sent on to WU. These php files are located on the phone here:
/sdcard/www/weatherstation/updateweatherstation.php
/sdcard/www/soiltemperature/rcvsoiltempdata.php
Thanks to Pat for the redirect idea: https://obrienlabs.net/redirecting-weather-station-data-from-observerip/

Hopefully this provides enough pieces if you want to implement or at least provides some ideas.

