The Pulseblaster Parser reads in a .pbsrc file which is human-friendly, and outputs a much simpler .vliw file, ready for pb_utils.
It needs php-cli to be installed. It relies upon pb_utils (released separately).

Requires:
	pb_print_config		- part of pb_utils, so that the pulseblaster.h header information is shared with pb_parse.


Contents of this directory:

src/
	pb_parse.php		- The pulseblaster parser program. This is written in PHP. When installed (to /usr/local/bin),
				  it is installed as "pb_parse".
	pb_parport-output.c	- A helper program to write bytes to the physical parallel port(s).

	pb_parse.bashcompletion - bash completion for pb_parse
	pbsrc_kate.xml		- kate/kwrite syntax highlighting rules for pbsrc/vliw.



doc/				- Documentation of file formats
	pbsrc.txt		- Documentation of the .pbsrc file format. This explains how it works.
	pbsim.txt		- Documentation of the .pbsim simulator replay-log file format.
	simulation.txt		- Explanation of how the simulation works. Not strictly necessary to read this!
	configuration.txt	- Short explanation of what is configured where.
	exit-codes.txt 		- Short explanation of exit codes.
	quirks.txt		- Explanation of the pulseblaster's 'quirks'.
	loops.txt		- The pulseblaster internally handles loops in a weird way.
	latencies.txt		- Explanation of the pulseblaster's latencies.
	longdelay.txt		- Explanation of how DWIM handles long delays.
	wait.txt		- Restrictions on the use of WAIT.
	destination.txt		- Explanation of is_destination() and why SAME's behaviour isn't ideal.
	pcre-limit.txt		- Explanation of the limits on PCREs used, and how to work-around.
	parport-output.txt	- Explanation of how to make a (slow) poor-man's pulseblaster with parallel ports.

	[See also: ../pb_utils/doc/vliw.txt]


man/				- Brief man pages for pb_parse, pb_parport-output, the tests, and the pbsrc and pbsim file-formats.

tests/				- Some test scripts, to verify that everything works.
	pb_test-pbsrc.walk5.sh  - 5-way walking LEDs program. If this shell script works, *everything* in the pb_* system is verified to be working correctly!
	pb_test-parport.sh	- Test of the parport output for the "poor-man's pulseblaster".
	walking_5leds_5Hz.pbsrc - Used by the above.
	flash_leds_250Hz.pbsrc	- Used by the above.

pbsrc_examples/			- Some example pulseblaster programs. These may also help to document the .pbsrc format, and the comments are helpful to explain misunderstandings.
	good/			- Good code: correct, compiles ok.
	bad/			- Bad code: compiles ok, but does the wrong thing.
	invalid/		- Syntax errors.
	simulate/		- For simulation. (Only simulate a few files, so that "make examples" doesn't take for ever)
	realworld/		- An actual real-world physics example. This is far longer, and properly uses all the features.

	simple.pbsrc		- A simple "walking LEDs" program

	loop-test.pbsrc		- Tests of the various opcodes (and simulation of them)
	loop-nest-test.pbsrc
	loop-zero.pbsrc
	loop-wrong.pbsrc
	longdelay-test.pbsrc
	call-test.pbsrc
	call-test2.pbsrc
	call-recursive-test.pbsrc
	wait-test.pbsrc
	stop-test.pbsrc
	stop-test2.pbsrc

	bit-test.pbsrc		- Tests of the parser features.
	macro-test.pbsrc
	execinc-test.pbsrc
	same-values-test.pbsrc
	opcode_macro.pbsrc
	32768-words-test.vliw
	comment-test.pbsrc
	ugly.pbsrc
	invalid.pbsrc

	speed-calibrate.pbsrc	- Tests of the hardware.
	pb-model-test.pbsrc
	run-off-end.pbsrc

	example.pbsrc		- Illustrates the various features of the parser and pbsrc language.

	pixel_characterise.pbsrc - Real-world physics experiment on the Hawaii chip, using all the latest features of pb_parse.

	frameread.pbsrc		- Old example frameread
	frame-reset-read.pbsrc	- Simple program to reset an entire Hawaii chip, then read every pixel.

	simulate.pbsrc		- Simple example with diminished Hawaii chip, intended for simulation.


Makefile			- The makefile. Run "make; sudo make install". Also try "make examples".

IDEAS.txt			- Future ideas.
README.txt			- This Readme file.
LICENSE.txt			- GPL Licensed.

SEE ALSO:			- ../../../src/hawaii_sensor/: top level directory containing some actually useful pulseblaster programs.
