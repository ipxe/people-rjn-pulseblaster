This is a "real-world" example. It does compile, and is actually useful for physics experiments.

Compile with:
pb_parse -xqX -i pixel_characterise.pbsrc -DQ0_X=0 -DQ0_Y=1 -DQ1_X=2 -DQ1_Y=3 -DQ2_X=255 -DQ2_Y=256  -DQ3_X=510 -DQ3_Y=511 -DT_INIT=0.1s -DT_RENABLED=0.1s -DT_RESET=0.5s -DT_EXPOSE=1s 

Explanation:
It's intended for a Rockwell Hawaii Focal Plane Array IR CCD sensor. This has 4 quadrants, each 512x512 pixels.
The sensor has various DC power supplies, and each quadrant has 6 clocks:  Fsync, Line, Lsync Pixel, Read, Reset.
 - The (vertical)   row counter is reset by FrameSync, and advanced by Line.
 - The (horizontal) column counter is reset by LineSync, and advanced by Pixel.
 - Read enables the output source follower.
 - Reset resets all pixels on the currently addressed line.
In this specific application, all 4 quadrants have Fsync/Line/Read/Reset commoned, because there are insufficient channels to drive them separately.

The code is intended to allow for a single (chosen) pixel in each array to be addressed, then reset, and exposed to light, while doing ADC conversions on the output.

pixel_characterise.pbsrc is the main code, using hawaii.h as the header, and macros.pbsrc as a "function library". 
clock_calc.php is used to do some calculations to simplify the procss of stepping to each pixel in 4 quadrants simultaneously (necessary because all 4 Fsync and Lsync clocks are commoned).

