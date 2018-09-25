# SoilSensor_ESP12f
Soil temperature and moisture reporting using ESP8266 12f.

Components:
- ESP8266-12F
- DS1820 temperature sensor
- SEN0193 moisture sensor
- (optional) Android phone to run BitWeb Server which runs PHP file to receive data.

Periodic (30 minute) reporting of soil temperature, moisture, and ESP8266 supply
voltage to a configurable host and URL. Configuration is done via connecting to
WiFi Access Point SoilTempAP with "yourpwd".  The 12f will go into access point mode
on initial use or if connect to router fails. Uses deep sleep mode for ~20uA
standby current or 70mA in AP mode.  Supports OTA update if the receiving host php file
is modified to return "update" rather than success on a data http POST.
See schematic for hardware details. Currently using 3 AA batteries for power.  Moisture
sensor is capacitive type SEN0193.  It appears to be only slightly temperature
sensitive - see plot jpg.  Update - at freezing crossover you will see non linear changes.
Calibration is done via soaking in water and noting dry and soaked
A/D points.  Put these points in your rcvsoiltempdata.php.

This is part of my Weather Underground, weathercloud.net, pwsweather.com solution.  The soil
sensor sends http POST packets to Bit Web Server running on my
Cloud Pic cell phone:
https://github.com/mrcodegh/WU-Cloud-Cam-Pics-via-Android.
See php files in this repo (SoilSensor_ESP12f) for how soil data is combined with
WS1400-IP weather station data and sent on to WU. These php files are located on the phone here:
/sdcard/www/weatherstation/updateweatherstation.php
/sdcard/www/weatherstation/wxstationlastupdate.php
/sdcard/www/soiltemperature/rcvsoiltempdata.php
Thanks to Pat for the redirect idea: https://obrienlabs.net/redirecting-weather-station-data-from-observerip/

Hopefully this provides enough pieces if you want to implement or at least provides some ideas.

