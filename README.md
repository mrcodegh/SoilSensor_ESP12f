# SoilSensor_ESP12f
Soil temperature and moisture reporting using ESP8266 12f.

Periodic reporting of soil temperature, moisture, and ESP8266 supply
voltage to a configurable host and URL. Uses deep sleep mode for ~20uA
standby current.  Supports OTA update if the receiving host php file
is modified to return "update" rather than success on a data http POST.
See schematic for hardware details. In my application I am using the
Starry Light Solar light string for power.  This is way overkill (solar cell covered most of the time) 
providing 100s of mAh where only 10s mAh are needed.  3 AA or AAA
non rechargeable cells should provide many months via ESP deep sleep.

This is part of my Weather Underground reporting solution.  The soil
sensor sends http POST packets to Bit Web Server running on my
Cloud Pic cell phone:
https://github.com/mrcodegh/WU-Cloud-Cam-Pics-via-Android.
See php files in this repo (SoilSensor_ESP12f) for how soil data is combined with
WS1400-IP weather station data and sent on to WU.
