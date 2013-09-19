/* Hawaii connection and timing definitions for the Pulseblaster. (c.f. hawaiisim/src/hawaiisim.h ). */

/* Pulseblaster: check that it ticks at 10 ns, as expected */
#hwassert PB_TICK_NS  10

/* Hawaii physical layout */
#define NUM_ROWS 	512
#define NUM_COLS 	512
#define QUADS	 	4

/* Pulseblaster lines, as we have them connected. (Words, with this bit set) */
#define PIXEL0		0x1	//Bit 0
#define PIXEL1		0x2	//Bit 1
#define PIXEL2		0x4	//Bit 2
#define PIXEL3		0x8	//Bit 3

#define LINE0  		0x10	//Bit 4
#define LINE1  		0x20	//Bit 5
#define LINE2		0x40	//Bit 6
#define LINE3	  	0x80	//Bit 7

#define READ    	0x100	//Bit 8   (common for all quadrants)
			//200   //Bit 9,  unused
			//400	//Bit 10, unused
			//800	//Bit 11, unused
#define RESETB  	0x1000	//Bit 12  (common for all quadrants)
#define LSYNC   	0x2000	//Bit 13  (common for all quadrants)
#define FSYNC   	0x4000	//Bit 14  (common for all quadrants)
#define ADC		0x8000	//Bit 15  (common for all quadrants)

#define FAKEADC		0x800000 //Bit 23, not actually implemented in our hardware, used by hawaiisim.
#define LOOPBACK3	LINE3	 //Line 3, in loopback mode, connects straight to output.


#define PIXELS  	(PIXEL0|PIXEL1|PIXEL2|PIXEL3)	//grouped bits (nested define)
#define LINES   	(LINE0|LINE1|LINE2|LINE3)
#define POSN_CLOCKS	(PIXELS|LINES|LSYNC|FSYNC)	//the lines used for positioning


#define START_STATE	RESETB|LSYNC|FSYNC|ADC|FAKEADC	//"Safe/Sensible" start point: set exactly these bits.

/* Line direction: we could re-define directions, so we can treate everything as active-high/rising-edge-triggered. */
/* If so, would use "#set OUTPUT_BIT_INVERT  RESETB|LSYNC|FSYNC|ADC", but it's perhaps clearer to NOT do this. */


/* Label the bits for VCD analysis with GtkWave */
#vcdlabels  		FakeADC,-,-,-,-,-,-,-, ADC, FSync, LSync, ResetB, -, -, -, Read, Line_3, Line_2, Line_1, Line_0, Pixel_3, Pixel_2, Pixel_1, Pixel_0


/* Clock periods (default, can be overridden with -D) */
#define T_DIG			#default:100ns	//Digital clock period. (FSync.LSync,Pixels,Lines)
#define T_RESET_DEFAULT		#default:1us	//Reset length. (ResetB)
#define T_READSETUP_DEFAULT	#default:10us	//Setup time on READ clock.
#define T_INTEGRATE_DEFAULT	#default:10s	//Integration time.
#define T_ADC_DEFAULT		#default:100ns	//Time for an ADC trigger pulse. Note that this starts acquisition(n_samples), not just one sample.
#define T_FAKEADC_DEFAULT	#default:5us	//Fake ADC takes this long per conversion.
