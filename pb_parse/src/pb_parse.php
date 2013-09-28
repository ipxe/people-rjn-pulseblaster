#!/usr/bin/php -ddisplay_errors=E_ALL
<?

/* This parser converts a PulseBlaster command file to a series of VLIW opcodes.		*
 * The input .pbsrc file is written in a flexible, human-readable way.				*
 * The output .vliw file is ready to be used by pb_asm/pb_prog.					*
 * A PulseBlaster is a digital timing card, manufactured by Spincore, www.spincore.com 		*
 * Download from http://www.richardneill.org/source   Feedback/bug reports very welcome.	*/

/* Copyright (C) Richard Neill 2014-2013, <pulseblaster at REMOVE.ME.richardneill.org>. This program is Free Software. You can
 * redistribute and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation,
 * either version 3 of the License, or (at your option) any later version. There is NO WARRANTY, neither express nor implied.
 * For the details, please see: http://www.gnu.org/licenses/gpl.html  */

$VERSION="2.85";
$RELEASE_DATE="2013-09-17";
$AUTHOR="Richard Neill";
$EMAIL="<pulseblaster at richardneill.org>";
$COPYRIGHT_DATES="2004-2013";
$URL="http://www.richardneill.org/source";
$LICENSE="This is Free Software, licensed under the GNU GPL version 3 or later.";

/*
PERFORMANCE: pretty good; see Usage() for details.
Note that Konsole is much better than Gnome-terminal in 2 important respects: it's much quicker at rendering ANSI colours, and it handles the input stream (Q,Enter) better under heavy load.

PHP VERSION: developed and tested mostly with 5.3.19. Version 5.x required (see $PHP_VERSION_REQUIRED below)

TODO:
  - Implement some more #sets For example, SLOWDOWN=1000, would multiply all lengths by 1000, to allow easier hardware debugging. [This would affect the vliw file, not just the simulation (-z).]
  - Allow endloop (and __endloop) to infer the "obvious" startloop position [the zeroloop code has an example of backtracking], thereby not requiring a label in the endloop (or maybe "__endloop auto"). Note, we MUST still enforce
    that the loop instruction is itself labelled, else is_destination() fails, and then we wouldn't be able to warn on misuse of bit/same at the start if a loop.
  - Allow a double-overloaded stop, perhaps as the macro "__stop" to do: "same cont - 100 ; stop - - - " so it doesn't wail about prev length.
  - The NOP and Overloaded STOPs might be better dealt with in the DWIM section?
  - Would be nice to have 2 passes through the simulator. So ALL programs get simulated once (in fast mode), quietly. This check is then used before building any program. It also means that a
      full simulation of a program that loops lots can be quit without needing to error-out: we could prove that the code is correct, and go on to output the resulting code.
  - Find all remaining BUGs, NOTEs and TODOs (and FIXMEs)

WISHLIST:
  - DWIM can auto-promote overlong cont to longdelay. Should also be able to promote other overlong opcodes (would become TWO instructions). Also demote a short longdelay (useful with the __opcodes).
  - Write a better Kwrite syntax highlighting mode for .pbsrc (and .vliw) files. The current one is based on C-mode, but not very complete.
  - Implement some string functions, notably, chr() to allow an ASCII character to be converted to a byte. Warning quoted strings can contain '* /' (without the space), and cause trouble: see perlfaq6.
  - Make repeated warnings of the same type able to be squelched. (Currently, this is done in a few specific cases only, or using @). We should also supress "Bad style" warnings after the first instance.
  - Add syntax for a macro to denote that it leaves the outputs unchanged (eg by toggling a bit twice). This would suppress that bit-warnings for the macro, AND for the line after. Better: do it automatically!
  - Would be nice if fopen($fifo, 'w') didn't block (until the other end is opened), so we could use stream_select() to print error-msg if it *would* block. Also (could) warn if write_output_fifo() is about to block / has blocked for some time.

IDEAS:
  - Change tokenisation to use commas rather than significant whitespace?
  - We do a lot of work with comments, cutting, inserting, modifying. Maybe better to just get rid of them?
  - Do we want any sort of preprocessor blocks (#if/#ifdef). What about (perhaps) allowing the parser to implement (pretend) variables within the .pbsrc file? This would also allow pseudo 'FOR($i=0;$i<100;$i++)' loops to be implemented. Sort-of done by macros with #if($x)
  - If using both -w and -k, consider printing the prompt after the wait has happened, rather than mid-opcode? (more "correct", but might just be confusing?.)
  - Exit values should distinguish between parser (internal) errors and syntax errors in the pbsrc program. Currently just 0=OK;1=FAIL. Is this worth it?
  - Implement the "COME FROM" instruction (but only on April 1st).

IF-ONLY:
  - Make same/bitwise wrt to most-recently executed instruction, not prev_address (as now). Easy for RETURN; ambiguous,undefinable for ENDLOOP; CALL/GOTO might work, but would only be unambiguous if the subroutine were only
      called from one point - i.e. there were no point having a subroutine!   (this is highly desirable, but impossible, given that same/bit* are constructed in the parser, not the pulseblaster).
*/

//--------------------------------------------------------------------------------------------------------------
// **** BEGIN CONFIGURATION SECTION: INTERNAL DEFINITIONS:  ****

$DEBUG=false;						//print internal values if true (or if -d is given on the command line).
$VERBOSE_DEBUG=false;					//make debugging even more verbose. (or use -v).
$SCREAM=false;						//break the 'silence' operator '@'.
$FATAL_ERROR_DEBUG_IMMORTAL=false;			//make fatal errors non-fatal. only use this for development.
$HEADER_BINARY="pb_print_config";			//Many definitions for the hardware are in pulseblaster.h (part of pb_utils). This information is transferred via pb_print_config.
$HEADER_BINARY_DEVEL="../../pb_utils/src/$HEADER_BINARY"; //In development tree. 
$ASSEMBLER="pb_asm";					//Assembler.
$PROGRAMMER="pb_prog";					//Programmer/Assembler.
$SOURCE_EXTN="pbsrc";					//Extension of input file (PulseBlasterSouRCe). We don't really need to require this, but insist for tidiness and error-proofing.
$OUTPUT_EXTN="vliw";					//Extension of output file (VeryLongInstructionWord).
$BINARY_EXTN="bin";					//Extension for binary file (for pb_asm)
$PBSIM_EXTN="pbsim";					//Extension for log file (for target-device simulation)
$VCD_EXTN="vcd";					//Extension for vcd file (for waveform viewer)
$PB_PARPORT_OUT="pb_parport-output";			//Program to read from fifo and output bytes to parports. (needs to be in C to use PPWDATA ioctl).
$PBSIM_SIMULATOR="hawaiisim";				//Simulator that uses pbsim files.
$WAVE_VIEWER="gtkwave";					//Wavefile viewer program (vcd files)
$PARSER_CMT='PARSER:';					//Comments prepended with this string are modified by this parser. - This is just for the benefit of humans; it's not "magic".
$PARSER_IBS='PARSER_MAGIC_IBS:';			//IBS = in-band signal! This string is "Magic". It's read later, eg to track source-line numbers.
$MARK_PREFIX='MARK:';					//Prefix for Mark (the mark instruction).
$NO_CLOBBER=true;					//Don't overwrite existing output file if true (unless -x is given on command line)
$NO_CLOBBER_DEV=true;					//Don't overwrite existing output device during simulation (-j), unless -x is specified.
$SIMULATION_VERBOSE_REGISTERS=false;			//print register details if true (or if -r is given on the command line).
$USE_DWIM_FIX=true;					//Enable "Do what I mean" fixes. This removes some of the restrictions on Spincore's opcodes. Should normally be true.
$ENABLE_EXECINC=true;					//Enable "#execinc". This can cause the parser to execute external code: possible risk unless you trust what you compile.
$SIMULATION_USE_LOOPCHEAT=true;   			//Should (almost) always be true. 'Cheat' when simulating loops - don't actually do all the cycles when only one will do.
$MAX_MACRO_INLINE_PASSES=3;				//Maximum number of passes to make when inlining macros. (minimum = 1). Recommended: 1 or 3. See below for details.
$MAX_EXECINC_PASSES=3;					//Maximum number of passes for #execinc. (1 means no nested execincs).
$SIMULATION_DELAY_SYNC_QUANTUM_US=10000;		//Minimum accumulated error (in us) before we care that our realtime simulation is running too slowly and it sulks. Suggest 10ms.
$DEV_NULL="/dev/null";					//dev/null
$DEV_STDOUT="/dev/stdout";				//dev/stdout
$DEV_STDIN="/dev/stdin";				//dev/stdin
$ASCII_BACKSPACE="\x08";				//Ascii backspace character
$ASCII_BELL="\x07";					//Ascii Bell, since PHP doesn't support "\a".
$PCRE_BACKTRACK_LIMIT=32000000;				//The default limit is tiny (100k); increase to 32M. (>100M is practical). //DOCREFERENCE: PCRE-LIMIT
$PROMPT_DEFAULT_VALUE_BS=true;				//When writing prompt with $SIMULATION_USE_KEYPRESSES  (i.e. -k), echo a value, and then backspace over it, to offer user an editable default.
$EXIT_SUCCESS=0;					//Exit status for success.
$EXIT_FAILURE=1;					//Exit status for failure.
$PHP_VERSION_REQUIRED="5.2.0";				//PHP version required. Currently tested on 5.2.14 (32-bit) and 5.3.2 (64-bit)

//If we add to these, also add to the PRINT_CONFIG section.
//Length definitions.					//For a Length such as "20us", whose suffix is KEY, multiply by VALUE to get the number of TICKS. (must divide by $HEADER["PB_TICK_NS"] below).
$SUFFIX_NS['tick']	= -1;				//(Special case - length measured in ticks, or there is no suffix - see later)
$SUFFIX_NS['ticks']	= $SUFFIX_NS['tick'];
$SUFFIX_NS['week']	= 604800E9;			//3600*24*7  sec/week. (Don't support years or months since they aren't unambiguous)
$SUFFIX_NS['weeks']	= $SUFFIX_NS['week'];
$SUFFIX_NS['day']	= 86400E9;			//3600*24 sec/day
$SUFFIX_NS['days']	= $SUFFIX_NS['day'];
$SUFFIX_NS['hr']	= 3600E9;			//3600 sec/hr
$SUFFIX_NS['hrs']	= $SUFFIX_NS['hr'];
$SUFFIX_NS['hour']	= $SUFFIX_NS['hr'];
$SUFFIX_NS['hours']	= $SUFFIX_NS['hr'];
$SUFFIX_NS['min']	= 60E9;				//60 sec, in ns.
$SUFFIX_NS['mins']	= $SUFFIX_NS['min'];
$SUFFIX_NS['second']	= 1E9;				//We expect "s", but allow common abbreviations: no need to error on them.
$SUFFIX_NS['seconds']	= $SUFFIX_NS['second'];
$SUFFIX_NS['sec']	= $SUFFIX_NS['second'];
$SUFFIX_NS['secs']	= $SUFFIX_NS['second'];
$SUFFIX_NS['ps']	= 1E-3;				//picoseconds
$SUFFIX_NS['ns']	= 1;				//nanoseconds
$SUFFIX_NS['us']	= 1E3;				//microseconds
$SUFFIX_NS['ms']	= 1E6;				//milliseconds.
$SUFFIX_NS['ks']	= 1E12;				//kiloseconds (~ 16.7 minutes)
$SUFFIX_NS['Ms']	= 1E15;				//Megaseconds (~ 278 hours)
$SUFFIX_NS['s']		= 1E9;				//NB ordering: "s" must be defined last, as 's' is a substring of 'us' etc

//Regular expressions, used below.
$NA="-";						//An argument which is to be ignored (i.e. N/A) is defined to $NA. (This makes it explicit, rather than using "-" or "0")
$SAME="same";						//WARNING: must *ALWAYS* test N/A with === rather than ==, since ZERO is a perfectly valid number, and (0 == '-')  in PHP.
$AUTO="auto";						//As with $NA, define this as a constant so it's easy to grep for.
$SHORT="short";						//Likewise.
$SEMI_COLON=";";					//We don't use them, but it's rather a C-programmer habit. Be helpful.
$RE_KEYWORDS_PP='assert|hwassert|endhere|define|what|default|if|ifnot|include|execinc|set|vcdlabels|macro|echo'; //Keywords used only for preprocessing (prefixed with '#').
$RE_KEYWORDS=$RE_KEYWORDS_PP.'|same|short|auto';	//Keywords, including things which may be used as values.
$RE_SETTINGS='OUTPUT_BIT_MASK|OUTPUT_BIT_SET|OUTPUT_BIT_INVERT'; //The things that may be #set
$RE_BITWISE='bit_?(or|set|clear|clr|mask|and|flip|xor|xnor|xnr|nand|nor|add|sub|bus|rlf|rrf|slc|src|sls|srs)';  //Bitwise changes. Count 'same' as a keyword for simplicity.
$RE_OPCODES='cont|continue|longdelay|long_delay|ld|loop|endloop|end_loop|test_end_loop|testendloop|tel|goto|branch|call|jsr|return|rts|rtn|wait|stop|nop|debug|mark|never'; //Opcodes
$RE_FUTURE='inline|function|else|elif|endif|ifdef|ifndef|redefine';  //Keywords for possible future use, reserved for now.
$RE_TYPOS='noop|vcdlabel'; 				//Typos ('noop', 'vcdlabel' rather than 'nop', 'vcdlabels').
$RE_OPCMACRO_PREV="goto|call|return|endloop";		//opcode macros (without the __). These ones merge into previous cont.
$RE_OPCMACRO_POST="loop";				//merge into following cont.
$RE_OPCMACRO_BAD="cont|longdelay|stop|wait";		//The ones we don't support.
$RE_OPCMACRO_ALL="__($RE_OPCMACRO_PREV|$RE_OPCMACRO_POST|$RE_OPCMACRO_BAD)";  //with the "__"
$RE_RESERVED_WORDS="($RE_KEYWORDS|$RE_OPCODES|$RE_BITWISE|$RE_FUTURE|$RE_TYPOS|$RE_OPCMACRO_ALL)"; //Reserved words, which may not be used as names in other contexts, nor re-defined. (Might work, but almost certainly indicates an error)
$RE_DEC='(0|[1-9][0-9]*)';				//Decimal integer (or zero, but not anything that could be misinterpreted as octal)
$RE_HEX='0x[0-9a-fA-F]+';				//Hex integer.
$RE_BIN='0b[01]+';					//Binary integer.
$RE_OCTAL_UGH='0[0-9]+';				//Illegal thing, which could be octal, or could be meant as decimal. Exit if we catch one. (historical 'bug' in strtol() says that eg 073===59)
$RE_DEC_FRAC='[0-9]+\.[0-9]+';                 		//Decimal fraction eg 3.54
$RE_INT="($RE_DEC|$RE_HEX|$RE_BIN)";			//Integer (after stripping out '_' ).
$RE_INT_NA="($RE_DEC|$RE_HEX|$RE_BIN|$NA)";		//Integer, but also allowing $NA (i.e. '-').
$RE_INT_FRAC="($RE_DEC|$RE_HEX|$RE_BIN|$RE_DEC_FRAC)";	//Integer, or decimal fraction.
$RE_OPERATORS_MATH="([-+*\/%()])";			//Mathematical Operators: ()  +, -  * /  %   (Note that / will do PHP-type division, returning float)
$RE_OPERATORS_BITWISE="([()&|^~]|<<|>>)";		//Bitwise Operators: ()  | & ~ ^ << >>
$RE_OPERATORS_COMPARE="([?:]|==|!=|\<=|\>=|\<|\>)"; 	//Comparison operators:  ?  :  ==  !=  <=  >=  <  >      (NB must define '<' and '>' after '<=' and '>=', or the preg_split will convert <=  to  <,=   ).
$RE_OPERATORS_LOGICAL="(!|&&|\|\|)";			//Logical operators:  !   &&  ||     (note that the Bitwise operators RE will capture && and || anyway.)
$RE_OPERATORS="$RE_OPERATORS_MATH|$RE_OPERATORS_BITWISE|$RE_OPERATORS_COMPARE|$RE_OPERATORS_LOGICAL";   //All operators.
$RE_WORDCHAR='[a-zA-Z0-9_]';				//A single word-character
$RE_WORDCHAR_DOLLAR='[$a-zA-Z0-9_]';			//A single word-character, OR a literal '$' sign.
$RE_WORD='[a-zA-Z][a-zA-Z0-9_]*';                    	//Word, begining with letter, then allow 0-9,_
$RE_TS="[\t ]";						//A tab or space. (\s includes newlines)
$RE_TOKEN="[^\t ]+";  					//A token: at least one non(tab/space) char.
$RE_COMMENT="(\/\/[^\n]*)";		                //A comment, including the leading // but not the trailing newline
$RE_COMMENT_ENTIRE="(\/\/[^\n]*\n)";		 	//A comment, including the leading // and the trailing newline
$RE_EXPRESSION='(([a-zA-Z0-9._+*\/%()&|^~?:-]|==|!=|\<=|\>=|<<|>>|\<|\>)*)';	//Legal expression - eg opcodes, strings, expressions, or numbers, or blank. Useful for macroarg or bitwise changes. This is slightly too lax.
$RE_UNITS=implode('|',(array_keys($SUFFIX_NS)));	//Match suffix units: 'ticks|tick|week|,,,|s'
$SQUELCH_PREFIX='@';					//Supress warnings for bitwise/same when prefixed with '@'.
//For more, search for 'preg_'

//Colours
$RED="\033[1;31m";	//Errors, warnings		//For example, echo "${RED}text$NORM" will show text in red.
$GREEN="\033[1;32m";	//Success			//For more, http://en.wikipedia.org/wiki/ANSI_escape_code
$BLUE="\033[1;34m";	//Headings, highlights		//we could even have inverted colour, blinking, or underlines
$DBLUE="\033[34m";	//Source code.			//Best viewed on a terminal with black background.
$MAGENTA="\033[35m";	//Output code
$AMBER="\033[33m";	//Notices
$CYAN="\033[36m";	//Line numbers
$DRED="\033[0;31m";	//Op codes
$BMAGENTA="\033[1;35m";	//Registers
$GREY="\033[1;30m";	//Greyed-out
$NORM="\033[0m";	//Back to default.
$COLOURS=array('RED','GREEN','BLUE','DBLUE','MAGENTA','AMBER','CYAN','DRED','BMAGENTA','GREY','NORM');

// **** END CONFIGURATION SECTION ****
$fatal_error_may_delete = false;  //altered later when appropriate
//--------------------------------------------------------------------------------------------------------------
//ERROR REPORTING, and PHP CONFIGURATION: (can use #!/usr/bin/php -dfoo=bar" to set up to 1 parameter).

$number_of_warnings = $number_of_notices = $number_of_silences = 0;  //Count how many warnings/notices we generate.

if (ini_get('display_errors')){	//Display errors should to be on; else parse-errrors in this script will exit silently and unhelpfully.  Can't just enable it here, because the parse-error (if there is one) will get us first!
	debug_print_msg("Warning: 'display_errors' = OFF in php.ini; this would make this die silently on parse errors. [Note: 'display_errors' *should* be off for webservers ('/etc/php.ini'), but cli has separate config ('/etc/php-cli.ini').]");
}
if (ini_get("safe_mode")){	//Check for no safe mode. 
	print_warning ("Warning: safe-mode is on (in php.ini). This will prevent things like 'fopen(\"dev/null\")' and generally break things weirdly. Please turn it off in php.ini.\n");
}

error_reporting (E_ALL); //All errors on. Alternative: (E_ALL&~E_NOTICE). Can only reduce the error-reporting here, not increase it. So use  -ddisplay_errors=E_ALL in the shebang line.

if (php_sapi_name()!= "cli"){	//Check we're using the PHP-CLI sapi (should always be true, but someone might try to run this in a webserver!)
	fatal_error("for some reason, this is not using the PHP-CLI SAPI, but the '".php_sapi_name()."' SAPI. Please use PHP-CLI.");
}

date_default_timezone_set("GMT"); //Set default TZ to GMT, else PHP wails.

$memory_limit=ini_get('memory_limit');  //Check the amount of allocated RAM. Calculate it in MB.
if ($memory_limit != "-1"){		//If set to -1, this means it's unlimited.
	$suffix=strtolower($memory_limit{strlen($memory_limit)-1});  //May have a trailing 'k', 'M' or 'G'. Deal with suffix. (Note: PHP usually parses "32M" as 32.)
	if ($suffix=='k'){ $memory_limit *=1024; }else if ($suffix=='m'){ $memory_limit *=(1024*1024); }else if ($suffix=='g'){ $memory_limit *=(1024*1024*1024); }
	$memory_limit = $memory_limit / (1024*1024);  //in MB.
	if ($memory_limit < 32 ){
		print_warning("memory limit is only $memory_limit MB, however this script may use up to 32 MB (maybe more!) for a large input file. Please modify 'memory_limit' in '/etc/php-cli.ini'.");
	}
}

$pcre_backtrack_limit = ini_get('pcre.backtrack_limit');	//PCRE has a limit on backtrack which is very small, only 100k by default. This means that an ungreedy RE, such as '.*?'
if ($pcre_backtrack_limit < $PCRE_BACKTRACK_LIMIT){		//cannot work on strings over 100k chars. So, for example, the multi-line comment remover can fail!
	ini_set( 'pcre.backtrack_limit', $PCRE_BACKTRACK_LIMIT);//There is no reason why this shouldn't be larger, at least for PHP-CLI. Performance is still fine with even 100M.
}								//For long (32k instructions) .pbsrc files, we slightly exceed the 100k limit, so we have to increase it.
$pcre_backtrack_limit2 = ini_get('pcre.backtrack_limit');	//DOCREFERENCE: PCRE-LIMIT
if ($pcre_backtrack_limit2 < $PCRE_BACKTRACK_LIMIT){
	print_warning("pcre.backtrack_limit is only $pcre_backtrack_limit2 characters; this is probably too small. Recommend 32M or so. ini_set() failed to change it.");
}else{
	debug_print_msg("Increased pcre.backtrack_limit from $pcre_backtrack_limit to $pcre_backtrack_limit2");
}

if (version_compare(phpversion(), $PHP_VERSION_REQUIRED, "<")){ //Check PHP Version. It should work with any recent PHP (not v4); currently tested/developed with PHP 5.3.2.
 	fatal_error("this requires at least PHP version $PHP_VERSION_REQUIRED. However, the installed version is only version '".phpversion()."'.");
}

if (PHP_INT_SIZE == 4){ 		//What is the size of PHP's int?  (see documentation for intval(). ).  Not really relevant now - we don't need GMP.
	$ARCH='32BIT';			//Signed 32-bit ints. (on a 32-bit architecture).
	debug_print_msg("Running on a 32-bit machine. This is what we expect to see. [Since we can't have uint 32, LENGTHS are stored as integers within doubles.]");
}elseif (PHP_INT_SIZE == 8){
	$ARCH='64BIT';			//A 64 bit architecture. We could (if we wanted) re-write the script to remove the 'misuse' of doubles to store large integers (could also fix get_s_us_rel() ).
	debug_print_msg("Running on a 64-bit machine. If desired, this parser could be re-written (for 64-bit only) to avoid the use of integer_floats for LENGTHs.");
}else{
	$ARCH='UNKNOWN';		//Unknown. Maybe we are running on a z80 ??!
	fatal_error("this script really won't run on a less than 32-bit machine.");
}

if ($FATAL_ERROR_DEBUG_IMMORTAL){
	print_warning ("Configured with \$FATAL_ERROR_DEBUG_IMMORTAL=true; fatal errors won't die(). For DEVELOPMENT ONLY!");
}

//--------------------------------------------------------------------------------------------------------------
//USAGE:
$binary_name="pb_parse"; 	//clearer than using basename($argv[0]), which changes from pb_parse.php to pb_parse when installed.
function usage(){
	global $binary_name, $argv, $SOURCE_EXTN, $OUTPUT_EXTN, $PBSIM_EXTN, $BINARY_EXTN, $VCD_EXTN, $MARK_PREFIX;
	global $DEV_NULL, $NA, $ASSEMBLER, $PROGRAMMER, $PB_PARPORT_OUT, $PBSIM_SIMULATOR, $WAVE_VIEWER, $EXIT_SUCCESS, $EXIT_FAILURE;
	global $AUTHOR, $EMAIL, $COPYRIGHT_DATES, $URL, $LICENSE, $VERSION, $RELEASE_DATE;
	$parser_num_lines = substr_count(file_get_contents($argv[0]),"\n");	//how big are we...
	$parser_num_preg = substr_count(file_get_contents($argv[0]),"preg_") - 1;
	$parser_num_kb = round(filesize($argv[0])/1024);

	return <<<EOT
USAGE:
	$binary_name - convert Pulseblaster program to opcodes.

SYNOPSIS:
	$binary_name -i SOURCE_FILE.$SOURCE_EXTN [ -o OUTPUT_FILE.$OUTPUT_EXTN ]

DESCRIPTION:
	$binary_name reads in a human-readable (.$SOURCE_EXTN) input file containing a program for
	the Spincore PulseBlaster and outputs a pseudo-machine-code (.$OUTPUT_EXTN) file suitable
	for loading into the PulseBlaster card.

	The parser checks for most errors, and will exit with failure if they are serious.
	Non-fatal errors will emit a warning. Please read the warnings!

FEATURES:
	* Outputs and arguments may be specified in binary, hex, or base-10
	* Lengths may be specified in ticks, or in units of ns,us,ms,s,ks,min...weeks
	* Where an argument is irrelevant, a '-' is used to make this explicit.
	* Permits labels, instead of numeric addresses. Comments are: '//', '/* ... */'.
	* Support for #include, #define [#what, #default:, #if, #ifnot].
	* Scientific/experimental parameters can be easily varied with -D (and #if/#ifnot).
	* Executable includes (#execinc) allow dynamic code generation and complex calculations.
	* Outputs can be specified as bitwise changes to the previous values (or SAME).
	* Inlined #macros (with parameter substitution), almost acting like functions.
	* Use of #set simplifies use of active-low logic on the peripheral.
	* Debugging features: #assert, #hwassert, #echo, #endhere.
	* "Do what I mean" fixes for infelicities in the instruction set:
	    * calculates ARG for longdelay if specified as 'auto'
	    * promotes cont or demotes longdelay if length is out of range.
	    * zeroloop: loop (0) converted to goto (addr_of(endloop)+1).
	    * opcode macros: __call/goto/return/loop/endloop can appear instantaneous.
	* Case-sensitive (except opcode-names). Alternate opcode mnemonics (eg GOTO vs BRANCH).
	* Allows STOP to be overloaded (set outputs). Adds NOP.
	* VLIW-reordering: opcode,arg may be written before/inside/after out...len, for clarity.
	* Mathematical operators: *,-,+,/,%,(,)    Bitwise operators: |,&,~,^,<<,>>
	* Comparison operators: ==,!=,<,>,<=,>=    Logical: &&,||,!,?,:    Error-control:  @
	* Detailed error checking, with helpful messages. Ensures that all instructions are valid.
	* Simulation of the hardware, to verify the program. Options:
	     * Optimised simulation (full proof of program correctness),
	     * Output on virtual LEDs, parallel ports, VCD file, and target-simulation logfile.
	     * Real-time, measurement, single-step, or manual triggering.
	* Output Formats: pulseblaster (.$OUTPUT_EXTN, .$BINARY_EXTN), simulation (.$PBSIM_EXTN, .$VCD_EXTN), byte-stream.

OPTIONS:
	-i  source_file
		input from source_file. The extension .$SOURCE_EXTN is required, unless the filename
		is '-' (meaning STDIN).

	-o  output_file
		output to output_file. The extension .$OUTPUT_EXTN is required, unless file is $DEV_NULL
		or '-' (meaning STDOUT). If '-o output_file' is not specified, then source_filename
		will be used, but renamed with the extension .$OUTPUT_EXTN, (and in the same directory as
		as source_file; not in \$PWD).

	-a	Assemble the result (using `$ASSEMBLER`) to generate a .$BINARY_EXTN file as well.
		The name pattern for the .$BINARY_EXTN file will be the same as output_file.

	-x	OK to overwrite an existing output file. This is prevented by default.
		(If output_file is $DEV_NULL, or a named pipe, -x is irrelevant.)

	-X	enables #execinc. Compilation of the .$SOURCE_EXTN file can invoke any external program:
		a security hole unless you read it first: be careful! If $binary_name finds
		#execinc without -X, it will print what would have happened.

	-D  const1=value1  -Dconst2=value2 -Dconst3=value3  -Dconst -Dnoconst ...
		equivalent to '#define const value', but at compile-time, rather than in source.
		conflicts with #define, required by #define/#what, optional with #define/#default:.
		-Dconst and -DNoconst are equivalent to -Dconst=1 and -Dconst="", and are used to
		conditionally enable/disable source lines prefixed by #if(const)/#ifnot(const).

	-d	print *lots* of verbose debug information. (also overrides '@').

	-v	make debugging even more verbose, useful for debugging $binary_name itself.

	-q	quieter: supress Notices and repeated Warnings. Not everything is supressed.
		(Note: -d overrides -q. To hide warnings, use '2>$DEV_NULL')

	-Q	quieter than -q: also suppress #echo, and #execinc's stderr. (overridden by -d).
	
	-S      scream: break the silence operator '@', forcing warnings on. (-d also does this).

	-h	display this help message (to STDOUT).

	-e	write an example .$SOURCE_EXTN file to STDOUT. Then exit. This can be used as a template
		for writing your own programs.

	-m	monochrome: disable colour (ANSI escapes) in output messages. Useful when
		processing the output further, piping to less, or with fast -t. (Also, see -y.)

	-c	print the configuration used by this program, and the data about the PulseBlaster
		model for which we are compiling. Then exit.

	-n	print out the .$SOURCE_EXTN source, as parsed, and expanded with numbered (instruction)
		lines, and the corresponding source filenames and line numbers. Useful to view the generated
		code and to debug 'error at line \$i' messages. This goes to STDOUT, not STDERR.

	-V 	print version information (to STDOUT)

SIMULATION OPTIONS:
	-s 	simulate an entire run. This is normally an *optimised*, *quiet* simulation. It
		checks for termination, repetition, max stack/loop depth etc. This *is* possible
		(despite The Halting Problem!), and it proves whether the code contains any
		program-flow or stack-depth bugs. Because of optimisation, the simulation runs very
		quickly, and always finishes: it need only encounter each instruction line *once*.
		Output is terse. For more information, see the file pb_parse/doc/simulation.txt .
		(If the simulation detects an error, but you want to generate a .$OUTPUT_EXTN file anyway,
		re-invoke the parser without the -s option.)

	-f	full simulation, not just the optimised simulation. Each instruction is faithfully
		executed. For example, a loop always receives n passes, not just 1. This is useful
		if you want to trace the program in full (-s suffices to prove correctness). Unless
		the program contains a STOP opcode (or a bug), this simulation will not terminate,
		and may need to be stopped with Q,[Enter] or Ctrl-C.
		(Quitting simulation with Q,[Enter] generates a .$OUTPUT_EXTN file; Ctrl-C doesn't.)

	-r	verbosely show registers and the corresponding source-lines during simulation. For
		each line of the program, the source, instruction, and PC/LD/SD registers are
		printed. Explanation of the instruction, and program-flow is shown too.
		(This is compatible with either -s or -f, defaulting to -s)

FULL SIMULATION OPTIONS:
	These options imply -f, and modify/enhance the simulation. Note that a full simulation will
	not terminate unless the program contains a STOP, therefore an output file will only be
	generated when the user presses "Q" (but not Ctrl-C).

	-l	show a single status line with all registers, the full instruction, and simulated
		LEDs, (but omit the source-line, for reasons of space). This uses \\r to produce a
		single, updating status-line, on STDOUT.
		(The full simulation defaults to -l, unless -r or -p are specified).

	-p	piano-roll mode: like -l, but with \\n rather than \\r, also STDOUT.

	-y	'yep'. very terse simulation display. Useful with -g and a slow terminal.

	-t	real-time mode. Simulate the lengths too, as well as is possible. In practice, this
		is only correct for lengths >~ 0.1ms, depending on the computer's speed. The
		simulation tries to constantly re-synchronise with "wall-clock time", by comparing
		ELAPSED_TICKS with the current microtime; it emits a warning if it can't catch up.

	-z  clock_factor
		speed up the simulation by a factor of clock_factor. This is a float; if < 1, it 
		acts to slow down the simulation. (implies -t).

	-k	enable single/multi-step on keypress. Enter 'n' followed by [ENTER] to jump forward
		by n steps. n=1, or just [ENTER] moves to the next instruction. Press Q, then
		[ENTER] to quit. (-k implies -p)

	-u  step_limit
		automatically stop the simulation after max_steps. Useful in scripts to ensure that
		simulation stops, even if the program itself doesn't.

	-b	beep on each new instruction. (only in real-time, or single-step modes).

	-w	when simulating a WAIT instruction, actually wait for manual re-trigger, rather
		than simply continuing automatically.

	-j  parport_fifo
		during simulation, write the bytes to actual devices. This allows for a "poor-man's
		PulseBlaster", using 1-3 parallel ports. Accurately-timed ascii-hex data (eg
		"0xffeedd\\n") is written to a named-pipe (fifo); this is then transferred to the
		physical port(s) by running the separate utility '$PB_PARPORT_OUT' in another
		shell. The fifo, parport_fifo will be created if necessary. (most useful with -t).

	-g  	generate a Simulation Replay Log, (extension .$PBSIM_EXTN). The Simulation Replay Log is
		useful for simulating the target hardware. After simulation, this logfile contains
		the outputs (and lengths) that the PulseBlaster would have output. If the
		PulseBlaster is connected to, for example, a camera controller which itself has a
		simulation program, the camera-simulator ($PBSIM_SIMULATOR) can replay the logfile.
		MARK instructions highlight specific 'breakpoints', (prefaced with '//$MARK_PREFIX').
		If the program loops, this logfile could be infinite: recommend using "-u", or a
		named-pipe. (Not affected by -z; enables -y unless -p/-l; not recommended with -w, 
		nor -k. Filename controlled by -o.) For more details, see:  pb_parse/doc/pbsim.txt

	-G  	generate a Value Change Dump file (extension .$VCD_EXTN). The VCD file contains the
		same information as the simulation replay log, but in the standard format used by
		wavefile viewers, such as $WAVE_VIEWER. For the line labels, use #vcdlabels, or -L.
		(Behaves like -g regarding flags z,y,p,l,w,k. Filename from -o.) For more details,
		see: pb_parse/doc/vcd.txt, or Wikipedia:Value_change_dump

	-L  label_list
		comma-delimited list of bit-labels for the .$VCD_EXTN file (override source #vcdlabels).
		Use '$NA' to skip; <= 24 elements. E.g: 'Reset,Clock,-,Enable' means: show only bits
		3,2,0 in the .$VCD_EXTN file, labelled as 'Reset','Clock','Enable'. (modifies -G).


EXAMPLE:
	$binary_name -i example.$SOURCE_EXTN -o example.$OUTPUT_EXTN          #Parse example.$SOURCE_EXTN
	$binary_name -qasxi example.$SOURCE_EXTN                      #Parse, assemble, check. (quietly)
	$binary_name -xi example.$SOURCE_EXTN -lb -tz 0.1             #Simulate, LEDs, realtime/10, beep.
	$binary_name -i example.$SOURCE_EXTN -gG -u 100               #Generate short replay-log and VCD file.
	mkfifo pbfifo; $PB_PARPORT_OUT pbfifo & $binary_name -j pbfifo -tymqxi example.$SOURCE_EXTN  #Parport.


DOCUMENTATION:
	* The .$SOURCE_EXTN format is thoroughly documented in: /usr/local/share/doc/pb_parse/pbsrc.txt
	* There are some sample programs in: pb_parse/pbsrc_examples/
	* Simulation is described in: pb_parse/doc/simulation.txt
	* The device is documented at http://www.pulseblaster.com
	* See also man pb_parse(1), man pbsrc(5), pb_parse/doc/*

EXIT CODES:
	The parser will exit with status $EXIT_SUCCESS if all is well. In case of any problem, the exit code
	is $EXIT_FAILURE. There will always be a helpful message just before exiting. If the source contains
	a syntax error, any .$OUTPUT_EXTN file will be removed: this protects against inadvertently
	re-using an old .$OUTPUT_EXTN file for $PROGRAMMER.

STDOUT/STDERR/STDIN:
	All error messages (and interactive prompts) are sent to stderr, so may be redirected with
	2>$DEV_NULL. Some outputs (notably -n,-l,-p) are sent to STDOUT. Full simulation uses both
	STDIN and STDOUT, so clashes with '-i -' and '-o -'; optimised simulation doesn't."

PERFORMANCE
	$binary_name is a PHP script, using the PHP 5 SAPI. It can parse a 32k-line .$SOURCE_EXTN file in
	5-15 seconds (depending on debugging and number of warnings) on a 3.5GHz Intel i7 machine.
	The simulation is very much faster than the parser (< 1 second). However, memory usage is
	high for large input files: if PHP complains "Allowed memory size of .... bytes exhausted",
	then increase 'memory_limit' in /etc/php-cli.ini to at least 32 MB (or -1 for unlimited).
	If the computer is marginally too slow when simulating with -t, a significant speedup can
	be obtained by redirecting STDOUT to a log file, rather than directly displaying it on the
	terminal, also use -m or -y. Typically, the simulator can run at 4.9 kips without -j and
	redirecting STDOUT. With -j, performance drops to about 3.4 kips; if output is also viewed
	on the terminal, perfornmance is nearer 1.1 kips. Realtime parallel-port output (-tj, with
	$PB_PARPORT_OUT) is possible at up to 1 kips, without significant jitter.

BUGS:
	* $binary_name can require rather a lot of memory: approx 1kB per line of the .$SOURCE_EXTN file.
	* PHP doesn't have an unsigned-int or long type. Therefore the LENGTHs are parsed as floats
	  instead. This works, because a double has ~52 bits of integer-precision.
	* If either ARG or OUTPUT should ever become > 31 bits long, in future versions of the
	  PulseBlaster, this script will need to be re-written (though it will warn).
	* Some of the source-code and output lines can be rather long. We could make full use of a
	  screen width of 200 characters!
	* By nature, it encourages "pre-processor abuse": for example this line compiles fine:
	      #define JULY  #ifnot(HERRING)  bit_flip(PENGUIN) goto ANTARCTICA ALL_SUMMER
	* It hasn't (yet) expanded to the point where it can read email, so as to fulfil Zawinski's
	  Law! But we're now at $parser_num_lines lines, $parser_num_preg REs, $parser_num_kb kB and counting... Inner Platform Effect?

AUTHOR:
	$AUTHOR $EMAIL $COPYRIGHT_DATES
	Download from: $URL
	$LICENSE

VERSION:
	Version $VERSION, released on $RELEASE_DATE.

EOT;
}

//--------------------------------------------------------------------------------------------------------------
$VERSION_INFO = <<<EOT
pb_parse, version $VERSION, released on $RELEASE_DATE.
AUTHOR: $AUTHOR $EMAIL
URL: $URL
LICENSE: $LICENSE
EOT;

//--------------------------------------------------------------------------------------------------------------
// OUTPUT FUNCTIONS:
$date=date("Y-m-d H:i:s");

function output($msg){				//Output to STDOUT
 	fwrite(STDOUT,"$msg\n");		//Used rarely. Most of the time, we want to output to STDERR.
}
function output_r($msg){			//Output to STDOUT, followed by \r.
 	fwrite(STDOUT,"$msg\r");		//Used for making a continuously-updating status line, which gets overwritten.
}
function print_msg($msg){			//Print message to STDERR
	fwrite(STDERR,"$msg\n");
}
function print_prompt($msg){			//Print message to STDERR with NO trailing \n. Useful in prompts.
	fwrite(STDERR,"$msg");
}
function output_prompt($msg,$prompt){		//Print $msg to STDOUT, with no trailing \n. Then print $prompt to STDERR
	fwrite(STDOUT,$msg);			//[The newline will be supplied by the konsole's echoing of typed input, when the user hits ENTER to submit the prompt.]
	fwrite(STDERR,$prompt);
}
function print_notice($msg, $nl="\n"){		//Print notice to STDERR.  Less important than a warning: use rarely.  Set $nl=false to suppress trailing \n.
	global $QUIET;
	global $AMBER, $NORM;
	global $number_of_notices;
	$number_of_notices++;
	if (!$QUIET){
		fwrite(STDERR,"${AMBER}Notice${NORM}: $msg$nl");
	}
}
function print_warning($msg,$leadingnewline=false){//Print warning to STDERR
	global $RED, $NORM;			//Leadingnewline is a parameter, since the caller can't insert it before the word WARNING.
	global $number_of_warnings;
	$number_of_warnings++;
	if ($leadingnewline){
		$leadingnewline="\n";
	}else{
		$leadingnewline='';
	}
	fwrite(STDERR,"$leadingnewline${RED}WARNING${NORM}: $msg\n\n");
}
function fatal_error($msg,$exit_code=false){	//Print fatal error to STDERR and exit with error-code.
	global $fatal_error_may_delete;		//If $exit_code is not specified, then exit (1).
	global $number_of_notices;
	global $EXIT_FAILURE, $FATAL_ERROR_DEBUG_IMMORTAL, $QUIET;
	global $RED, $AMBER, $NORM;
	global $OUTPUT_FILE, $BINARY_FILE;
	global $SIMULATION_OUTPUT_FIFO, $PBSIM_FILE, $VCD_FILE;
	global $DEV_NULL, $DEV_STDOUT;
	global $fp_out, $fp_pbsim, $fp_sofifo, $fp_vcd;
	if ($exit_code === false){
		$exit_code = $EXIT_FAILURE;
	}
	$hint = ($QUIET and $number_of_notices > 0) ? "Hint: $number_of_notices ${AMBER}notices${NORM} were hidden; retry without -qQ to see them; they might be helpful.\n" : "" ;
 	fwrite(STDERR,"\n${RED}FATAL ERROR${NORM}: $msg\n$hint"); //(The leading \n is in case we need to escape from a line previously written with \r.)
 	if ($FATAL_ERROR_DEBUG_IMMORTAL){			//Don't die; For testing only! 
 		fwrite(STDERR,"...${RED}Immortal${NORM}: continuing after fatal error. [Configured for debugging: \$FATAL_ERROR_DEBUG_IMMORTAL = true].\n");
 		return (false);
 	}
 	clearstatcache();					//If we had already opened the file(s), then closing it will result in an empty file. We want to remove this, to prevent confusion.
 	if (!$fatal_error_may_delete){				//For example: "fatal error, file already exists" won't clobber it (without -X) should NOT then clean up!
		fwrite(STDERR,"\n");
 		exit ($exit_code);
 	}
 	$fp_out && fclose($fp_out);
 	if ( file_exists($OUTPUT_FILE) and ($OUTPUT_FILE) and ($OUTPUT_FILE != $DEV_NULL) and ($OUTPUT_FILE != $DEV_STDOUT) ){ 	//Don't (try to) delete /dev/null though!
 		unlink($OUTPUT_FILE);
 		print_msg("[Output file '$OUTPUT_FILE' was deleted to prevent accidental use of invalid file.]");  //Delete it whether or not it was empty.
 	}
	if ($BINARY_FILE){			//Likewise, delete the BINARY_FILE if present.
		if ( file_exists($BINARY_FILE)){
			unlink($BINARY_FILE);
			print_msg("[Binary file '$BINARY_FILE' was deleted to prevent accidental use of invalid file.]");  //Delete it whether or not it was empty.
		}
	}
	if ($PBSIM_FILE){			//Likewise, delete the PBSIM_FILE if present and regular file
		$fp_pbsim && fclose($fp_pbsim);
		if ( file_exists($PBSIM_FILE)){
			(filetype ($PBSIM_FILE) == 'file') && unlink($PBSIM_FILE);
			print_msg("[Simulation Replay Log file '$PBSIM_FILE' was deleted to prevent accidental use of invalid file.]");  //Delete it whether or not it was empty.
		}
	}
	if ($VCD_FILE){			//Likewise, delete the VCD_FILE if present and regular file
		$fp_vcd && fclose($fp_vcd);
		if ( file_exists($VCD_FILE)){
			(filetype ($VCD_FILE) == 'file') && unlink($VCD_FILE);
			print_msg("[Value Change Dump file '$VCD_FILE' was deleted to prevent accidental use of invalid file.]");  //Delete it whether or not it was empty.
		}
	}
	if ($SIMULATION_OUTPUT_FIFO){
		$fp_sofifo && fclose($fp_sofifo); //Close simulation output fifo too. Don't delete it though, even if it's a regular file.
	}
	fwrite(STDERR,"\n");
	exit ($exit_code);
}
function debug_print_msg($msg){			//Print message to STDERR, IFF DEBUG is true.
	global $DEBUG;				//NB: don't prepend anything.
	if ($DEBUG){
		fwrite(STDERR,"$msg\n");
	}
}
function vdebug_print_msg($msg){
	global $VERBOSE_DEBUG;
	if ($VERBOSE_DEBUG){
		debug_print_msg($msg);
	}
}	
function sim_verbose_msg($msg){  		//Print extra messages during simulation. [Turned on by enabling $SIMULATION_VERBOSE_REGISTERS or DEBUG.]
	global $SIMULATION_VERBOSE_REGISTERS;
	if ($SIMULATION_VERBOSE_REGISTERS){
		print_msg($msg);
	}
}
function sprintflx ($value,$len=0,$line_number=false){	//Equivalent to "printf %lx, i.e. print a string in hex format (beginning 0x). Optionally pad to len characters.  Useful for
	global $NA;					//handling things that won't fit in an int32 (so that PHP's printf() doesn't work).  Eg 14-digit integers, stored as doubles.
	if ($value === $NA){	//Also accept -, as printable.
		return (str_pad($NA,$len));
	}
	if ( (is_float($value)) and (round($value) != $value) ){
		fatal_error("sprintflx(): called with value '$value', which isn't an integer. Error ".at_line($line_number));
	}
	$str='';
	while($value > 0){		//successively extract remainder, by dividing by 16^n.
 		$digit = fmod ($value,16);	//Don't use "%" operator - it's wrong!
 		$value -= $digit;
 		$value /= 16;
		$digit = dechex($digit);
		$str = $digit.$str;
	}
	return (str_pad("0x$str", $len));
}
function reorder_comments($a,$b){	//trivial formatting tweak.
	global $PARSER_CMT;
	return (substr($a,0,strlen($PARSER_CMT)) == $PARSER_CMT);
}
function identify_line($line,$getraw=false){	//Takes a line of code, which may have an identifier magic string at some point in the comment. Returns the original line (without the identifier), then the
	global $CYAN, $BLUE, $NORM;		//then the source-filename and the source-file-linenumber. iff $getraw, then it also reads the raw line back out of the file (only do this for error messages, or we have an O(n^2) algorithm!
	global $PARSER_IBS, $PARSER_CMT;	//We are looking for a magic string: "//$PARSER_IBS source: $filename;$sourceline"  (although the leading // may be missing)
						//NB, this can be called using JUST a comment instead of $line, but MAKE SURE that there is a leading '//'.
	$trunc_max = 500;			//Don't return colour codes in the line itself - they get really messed up when subsequently manipulating the string!
	$magic = "$PARSER_IBS ";
	$magic2 = "$PARSER_IBS source:";

	$info=array();			//RETURN VALUES:
	$info['line']='';			//  The line itself, but with the embedded "$PARSER_IBS source: ..." removed. (Doesn't remove other PARSER_IBS stuff though)
	$info['tidied']='';			//  The line, but after removing PARSER_IBS, squeezing whitespace, replacing // by ' | ' for 2nd and subsequent comments, and trimming "PARSER:" to "PSR:".
	$info['tidied_colour']='';		//  Likewise, with colour (but the comment isn't coloured).
	$info['trunc']='';			//  The line itself, but truncated: squeezed whitespace, trimmed to 100 chars max, (but only trim after start of comment)
	$info['cmt_first']='';			//  The first part (the original bit) of the comment, after tidying and removing PARSER_IBS etc.
	$info['sourcefile']=false;		//  The filename from which this line of source comes.  [If we are missing the PARSER_IBS tag, will remain false)]
	$info['sourcelinenum']=false; 		//  The line number (1-based) within the source-file.
	$info['sourcefile_colour']=false; 	//  The filename (with colour)
	$info['sourcelinenum_colour']=false;	//  The line number with colour.
	$info['rawline_colour']=false;		//  Print the raw line (useful to get it BEFORE #defines have taken effect). 
	$info['ibs']='';			//  Any other IBS messages, other than source.
	if (!$line){
		return ($info);
	}
	$comments = array();
	$source = $sourcefile = $sourcelinenum = false;
	$parts = preg_split ('/\s+\/\//',$line); //Split apart by '\s+//'.  [NB, normally, we'd want to split just by '//', detecting comments not preceeded by space. BUT, sometimes filenames end up with "//" as the directory-separator: don't split here!] 
	$code = $parts[0];			//First one is the code.
	for ($i=1; $i< (count($parts)); $i++){  //In the rest....
		if (substr($parts[$i],0,strlen($magic2)) == $magic2){	//found Magic IBS to show source line.
			$source = substr ($parts[$i],strlen($magic2));
			$sourcefile = trim(substr($source,0,strpos($source,';')));  //filename
			$sourcelinenum = trim(substr($source,strpos($source,';')+1));  //linenum,
			$info['sourcefile'] = $sourcefile;
			$info['sourcelinenum'] = $sourcelinenum;
			$info['sourcefile_colour'] = "${CYAN}$sourcefile${NORM}";
			$info['sourcelinenum_colour'] = "${CYAN}$sourcelinenum${NORM}";
		}elseif (substr($parts[$i],0,strlen($magic)) == $magic){ //found other Magic IBS message.
			$info['ibs'] .= $parts[$i];  //may be useful.
		}else{
			$comments[] = $parts[$i]; //We want this comment.
		}
	}
	usort($comments, "reorder_comments");	//Sort, to move the auto-appended comments to the end.
	$comment_tidied = trim(preg_replace("/\s+/"," ", preg_replace ("/(\/\/ ?)+/", ' * ', str_replace("$PARSER_CMT ", " PSR:", implode("//", $comments) )))," *");  //Replace: "PARSER:" by PSR: for brevity; (multiple) internal "//" by " * " for clarity; squeeze spaces.
	$comment_tidied = preg_replace ("/\(source: (([a-z0-9_\/.-]+\/)*([a-z0-9_.-]+));(\d+)\)/i", "(src:$3;$4)", $comment_tidied);  //turn all source filenames into basenames for brevity.
	$comment_tidied = ($comment_tidied) ? "//$comment_tidied" : ""; //prefix // if non-empty.
	$comment_first = explode(" * ", $comment_tidied); $comment_first = $comment_first[0];  //first part only of the comment. Same " * " as above.  (If the comment is empty except for PSR stuff, leave it empty)
	$comment_first = (substr($comment_first,0,6) == "//PSR:") ?  "//" : $comment_first;
	$code_tidied = ltrim(preg_replace ("/\t+/", "\t", $code));
	$code_trunc = trim(preg_replace ("/\s+/","   ",$code)) . "    "; 	//Spaces (and tabs) squeezed to "    " within code.
	$trunc_max -= strlen ($code_trunc);
	if ($trunc_max > 0){
		if (strlen($comment_tidied) > $trunc_max){
			$comment_trunc = substr($comment_tidied,0,$trunc_max -4) . " ...";
		}else{
			$comment_trunc = $comment_tidied;
		}
	}else{
			$comment_trunc = "// ...";
	}
	$info['line'] = $code . "//" . trim(implode ("//", $comments)); //Original line, but without the PARSER_IBS source:
	$info['tidied'] = $code_tidied . $comment_tidied;		//As above, but with tidied up comments; code has multiple tabs squeezed and any filenames converted to basenames.
	$info['tidied_colour'] = "${BLUE}$code_tidied${NORM}$comment_tidied";  //coloured, with normal comment.	
	$info['cmt_first'] = $comment_first;
	$info['trunc'] = $code_trunc . $comment_trunc;			//As above, but shortened, and with comments truncated.
	if ($getraw){			//Don't do this by default, or we have a horrible O(n^2) algorithm. We only need to read the raw line for error messages.
		if ($sourcefile){	//Get the raw line direct from the source, if we can. Useful to quote it BEFORE define's etc have munged it.
			$raw = file($sourcefile);
			if ($raw === false){		
				$rawline = "[Error reading raw line from source file '$sourcefile'.]";
			}else{
				$rawline = trim($raw[($sourcelinenum-1)]);
			}
		}else{
			$rawline = "[Cannot identify source file to find raw line.]";
		}
		$info['rawline_colour'] = "${CYAN}$rawline${NORM}";
	}
	return $info;
}

function at_line($line){			//returns a string "at line $linenumber (from $filename, line $sourceline):\n\t$lines_array[$linenumber]"
	global $lines_array;			//i.e. print the linenumber of the actual code, then the filename and line whence it came, then the offending line itself
	global $BLUE, $NORM;			//$line may EITHER be a $linenumber, to be looked up in the global $lines_array, OR it may be the actual line (string) containing an embedded PARSER_IBS.
	if (is_int($line)){			//$linenum is fairly uncorrelated with $sourceline! The offending line will be printed as it is stored, i.e. after substitution of #defines etc
		$string=$lines_array[$line];	
		$linenumber=$line;
	}else{
		$string=$line;
		$linenumber='x';	//[fudge, since we don't know the linenumber - and we don't really care!]
	}
	$info=identify_line($string,true);
	if ($info['sourcefile']===false){
		return ("at line $linenumber:\n\t$info[tidied]"); //Return the string, don't print it. Designed to feed fatal_error() etc, which will append a final \n to the message.
	}else{
		return ("at ${BLUE}line $linenumber${NORM} (from $info[sourcefile_colour], line $info[sourcelinenum_colour]):\n\t$info[tidied_colour]\n\t$info[rawline_colour]");
	}
}

declare(ticks = 1);	//Trap Ctrl-C (etc) if possible. Use it to print error messages, set the exit code, and restore the console colour.
function sig_handler($signo) {
	global $simulation_in_process, $binary_name, $OUTPUT_EXTN;
	if ($signo == SIGINT){
		$signal = "SIGINT (Ctrl-C)";
	}elseif ($signo == SIGQUIT){
		$signal = "SIGQUIT (Ctrl-\\)";
	}elseif ($signo == SIGTERM){
		$signal = "SIGTERM (kill -15)";
	}elseif ($signo == SIGPIPE){
		$signal = "SIGPIPE (pipe-reader exited)";
	}else{
		$signal = "Unknown signal ($signo)";
	}
	if ($simulation_in_process){	//If in mid-simulation, explain that there's no VLIW output.
		print_warning("Simulation terminated by $signal. No output (.$OUTPUT_EXTN) file has been generated. To get an output file, re-invoke $binary_name ".
			      "without the simulation, exit the simululation with Q,Enter, or use only the brief, optimised simulation (-s or -r).",true);
	}
	$msg="Killed by $signal.";
	fatal_error($msg);	//Print fatal error to STDERR and exit with error-code.
}
if (function_exists("pcntl_signal")){   	//In very old versions of PHP, the php_pcntl extension doesn't exist, so we can't rely on this to always work.
	pcntl_signal(SIGINT, "sig_handler");	//At the moment, this is just "nice to have", rather than "necessary".
	pcntl_signal(SIGQUIT,"sig_handler");	//Sigint = Ctrl-C.  SigQuit = Ctrl-\,   SigTerm = kill (default, not kill -9),  SigPipe = other end of pipe died.
	pcntl_signal(SIGTERM,"sig_handler");	//[SigKill is the one we can't catch, aka kill -9.]
	pcntl_signal(SIGPIPE,"sig_handler");
}else{
	debug_print_msg("php-pcntl extension is missing; Ctrl-C (etc) will not be trapped.");
}

function de_colour($undo=false){	//Globally unset all the ANSI colours. If $undo, revert it.
	global $COLOURS;
	global $MONOCHROME;
	foreach ($COLOURS as $colour){	//For each colour variable such as $RED (using indirect addressing)
		global $$colour;	//  declare it global
		$save=$colour."_save";	//define shadow variable
		global $$save;
		if ($MONOCHROME){
			$$colour='';	//monochrome mode - no colour at all.
		}elseif (!$undo){
			$$save=$$colour;//save
			$$colour='';	//de-colour
		}else{
			$$colour=$$save; //restore
		}
	}
}

//--------------------------------------------------------------------------------------------------------------
// GET COMMAND-LINE ARGUMENTS. Then process and sanity-check them. Make inconsistent options consistent.
$flags="abcdefgGhklmMnpqQrsStvVwxXy42D:L:i:j:o:u:z:";	//Each letter listed here is a possible flag. Letters followed by colon may take an argument.  [ -y still free! ]
$options_array=getopt($flags);  			// '-h' '--h' '-o output_file' '--o output_file' are all acceptable.

function bug_check($key,$value){	//Annoyingly, "-i -o foo" is parsed as "$i=-o; foo" , NOT as "$i=; $o=foo"
	if ((!is_array($value)) and ($value != '-') and (substr($value,0,1)=='-')){ //So, it is possible that a *missing* option will appear to be the next flag instead!
		fatal_error("option '-$key' cannot take value '$value' which begins with '-'; this looks like an accidentally omitted argument.");  //Check that $value does not begin with '-', (except when $value == '-')
	}
}

//initialise
$INPUT_FILE = $OUTPUT_FILE = $PBSIM_FILE = $MONOCHROME = $VCD_FILE = $VCD_LABELS_LIST = $DO_ASSEMBLY = $PRINT_CONFIG = $PRINT_TEMPLATE = $QUIET = false; $QUIETQUIET = $DO_DUMPLINES = $ALLOW_EXECINC = $DEFINE_OPTS = false;
$DO_SIMULATION= $SIMULATION_BEEP =  $SIMULATION_FULL = $SIMULATION_OUTPUT_FIFO = $SIMULATION_USE_KEYPRESSES = $SIMULATION_VIRTUAL_LEDS = $SIMULATION_PIANOROLL = $SIMULATION_WAIT_MANUAL = $SIMULATION_REALTIME = $SIMULATION_STEP_LIMIT = $SIMULATION_VERY_TERSE = $CLOCK_FACTOR = false;

/* The PHP getopt() implementation isn't very good. For example if a parameter requires a value (but isn't given one), no error can be detected. */
$args_remaining = $argc - 1;	//NB this doesn't always detect all surplus args: if options are combined (eg "-xX"), then $args_remaining will be < 0, not 0 at the end.
foreach ($options_array as $key => $value){
	$args_remaining--;
	if ($value !== false){
		$args_remaining --;
	}
	switch ($key){
		case 'a':
			$DO_ASSEMBLY=true;			//assemble the result.
			break;
		case 'b':					//beep on each instruction during simulation.
			$SIMULATION_BEEP=true;
			break;
		case 'c':					//print configuration information and exit.
			$PRINT_CONFIG=true;
			break;
		case 'd':					//debug
			$DEBUG=true;
			print_msg("Debugging enabled...");
			break;
		case 'D':					//#defines, provided by CLI options: -Dfoo=bar etc.
			if (is_array($value)){			//Always put into array, even if just one pair.
				$DEFINE_OPTS=$value;
				$args_remaining -= (count($value)-1);
			}else{
				$DEFINE_OPTS=array($value);
			}
			bug_check($key,$value);
			break;
		case 'e':					//print template.
			$PRINT_TEMPLATE=true;
			break;
		case 'f':					//simulate in full.
			$SIMULATION_FULL=true;
			break;
		case 'g':					//replay-log
			$PBSIM_FILE=true;
			break;
		case 'G':					//value-change-dump
			$VCD_FILE=true;
			break;
		case 'h':					//help   [Also works with -help, --help]
			output(usage());
			exit ($EXIT_SUCCESS);
			break;
		case 'i':					//input file
			$SOURCE_FILE=$value;
			bug_check($key,$value);
			break;
		case 'j':					//Output simulation bytes to actual hardware (via a proxy: pb_parport-output).
			$SIMULATION_OUTPUT_FIFO=$value;
			bug_check($key,$value);
			break;
		case 'k':					//Listen for keypresses in simulation.
			$SIMULATION_USE_KEYPRESSES=true;
			break;
		case 'l':					//Simulate LEDs
			$SIMULATION_VIRTUAL_LEDS=true;
			break;
		case 'L':					//Labels, for the VCD file
			$VCD_LABELS_LIST=$value;
			bug_check($key,$value);
			break;
		case 'm':					//Make output monochrome: disable all the colours.
			$MONOCHROME=true;
			break;
		case 'n':					//Dump lines (as tokenized) to stdout.
			$DO_DUMPLINES=true;
			break;
		case 'o':					//output file
			$OUTPUT_FILE=$value;
			bug_check($key,$value);
			break;
		case 'p':					//simulation LEDs in "piano-roll" mode.
			$SIMULATION_PIANOROLL=true;
			break;
		case 'q':					//quiet.
			$QUIET=true;
			break;
		case 'Q':					//very quiet.
			$QUIET=true;
			$QUIETQUIET=true;
			break;
		case 'r':					//print registers and program flow during simulation.
			$SIMULATION_VERBOSE_REGISTERS=true;
			break;
		case 's':					//simulate the whole thing, in optimised manner.
			$DO_SIMULATION=true;
			break;
		case 'S':					//enable screaming: break the silence '@' operator.
			$SCREAM=true;
			break;
		case 't':					//try to simulate in real-time.
			$SIMULATION_REALTIME=true;
			break;
		case 'u':					//simulation should perform a maximum of this many steps.
			$SIMULATION_STEP_LIMIT=abs(intval($value));
			bug_check($key,$value);
			break;
		case 'v':
			$VERBOSE_DEBUG=true;			//Make debugging even more verbose.
			$DEBUG=true;
			break;
		case 'V':					//show version information.
			output($VERSION_INFO);
			exit ($EXIT_SUCCESS);
			break;
		case 'w':					//simulation required manual re-trigger after a WAIT.
			$SIMULATION_WAIT_MANUAL=true;
			break;
		case 'x':					//clobber ok?
			$NO_CLOBBER=false;
			$NO_CLOBBER_DEV=false;
			break;
		case 'X':					//allow execinc  (provided $ENABLE_EXECINC is true).
			$ALLOW_EXECINC=true;
			break;
		case 'y':					//Terse simulation output. 'y' was the only letter left unused :-)
			$SIMULATION_VERY_TERSE=true;
			break;
		case 'z':					//clock factor.
			$CLOCK_FACTOR=$value;
			bug_check($key,$value);
			break;
		case 'M':					//reserved for now.
			read_mail();
			break;
		case '4':					//the answer.
		case '2':
			forty_two();
			break;
		default:					//Not as helpful as we'd like: wrong options never get into $options_array.
			print_msg("Unknown option: '$key'. Use -h for help");
			exit ($EXIT_FAILURE);
			break;
	}
}

if ($argc == 1){	//If no args, print help message and exit.
	print_msg("Wrong arguments. Use -h for help.");
	exit ($EXIT_FAILURE);
}elseif ($args_remaining > 0){  //any that getopt didn't pick up?  This doesn't work well when args are combined (eg "-ab"). Is there a better way?
	print_msg("Unexpected surplus arguments ($args_remaining too many). Use -h for help.");
	exit ($EXIT_FAILURE);
}
if ( ($CLOCK_FACTOR!=false) and (! (is_numeric($CLOCK_FACTOR) and $CLOCK_FACTOR > 0) ) ){   //Check that clock_factor, if specified, is valid.
	print_msg("Error: clock-factor (-z) must be a positive number, but you specified '$CLOCK_FACTOR'.\n");
	exit ($EXIT_FAILURE);
}
if ($DEBUG){		//Debug overrides quiet and Quiet, enables SCREAM.
	$QUIET=false;	//Debug forces simulation to be verbose (-r), provided that we have -s and NOT -l.
	$QUIETQUIET=false;
	$SCREAM=true; 
	if ($DO_SIMULATION and !$SIMULATION_VIRTUAL_LEDS){
		$SIMULATION_VERBOSE_REGISTERS=true;
	}
}
if (!$DO_SIMULATION and ($SIMULATION_VERBOSE_REGISTERS)){	//Option -r implies -s
	print_notice("option -r implies -s. Simulation enabled.");
	$DO_SIMULATION=true;
}
if (!$SIMULATION_FULL and ($SIMULATION_BEEP or $SIMULATION_USE_KEYPRESSES or $SIMULATION_VIRTUAL_LEDS or $SIMULATION_PIANOROLL or $SIMULATION_REALTIME or $SIMULATION_WAIT_MANUAL or $CLOCK_FACTOR or $SIMULATION_OUTPUT_FIFO or $SIMULATION_STEP_LIMIT or $PBSIM_FILE or $VCD_FILE)){
	print_notice("options -bgGjklptuwz all imply -f. Full simulation enabled.");  //Options -bjklptw all imply -f
	$SIMULATION_FULL=true;
}
if ($SIMULATION_FULL){	//Full simulation implies simulation (obviously)
	$DO_SIMULATION=true;
}
if ($SIMULATION_USE_KEYPRESSES){	//-k implies -p, because otherwise, the prompt and the output status-line (with \r) get "tangled" together.
	$SIMULATION_PIANOROLL=true;
}
if ($SIMULATION_PIANOROLL){		//-p implies a modified -l. No need to say anything though - it's obvious.
	$SIMULATION_VIRTUAL_LEDS=true;
}
if (($PBSIM_FILE) or ($VCD_FILE)){	//-g, -G implies -y by default.
	$SIMULATION_VERY_TERSE = true;
}
if ($VCD_LABELS_LIST and (!$VCD_FILE)){
	print_warning("option -'L' requires -G too. Ignoring it.");
	$VCD_LABELS_LIST=false;
}
if ($SIMULATION_VIRTUAL_LEDS and $SIMULATION_VERBOSE_REGISTERS){	//-l and -r conflict; -l has priority.
	print_notice("options -r and -l/-p conflict. -l/-p overrides -r.");
	$SIMULATION_VERBOSE_REGISTERS=false;
}
if ($CLOCK_FACTOR){			//-z implies -t. No need to say anything - it's obvious.
	$SIMULATION_REALTIME=true;
}
if ($SIMULATION_BEEP and !$SIMULATION_USE_KEYPRESSES and !$SIMULATION_REALTIME){  //-k or -t  are required with -b. Else it goes mad.
	print_notice("beep (-b) is disabled except with -k or -t. Otherwise, the terminal keeps beeping for ages, even after program exit.");
	$SIMULATION_BEEP=false;
}
if ($SIMULATION_FULL and !$SIMULATION_VIRTUAL_LEDS and !$SIMULATION_VERBOSE_REGISTERS and !$SIMULATION_VERY_TERSE){  //If we have a full simulation, we must have some sort of status line.
	print_notice("option -f needs to have some output. Enabling -l.");		 //(Eg  -k or -t force on -s, but neither -l nor -r nor -p nor -y are given)
	$SIMULATION_VIRTUAL_LEDS=true;
}
if (($SIMULATION_PIANOROLL or $SIMULATION_VIRTUAL_LEDS) and $SIMULATION_VERY_TERSE){	// -p and -l clash with -y, turn it off.
	print_notice("option -y clashes with explicit (or implicit) -p or -l. Disabling terse mode.");
	$SIMULATION_VERY_TERSE = false;
}

if ($MONOCHROME){	//unset colours, to have monochrome output. Useful whan redirecting output, or | less, which doesn't understand ANSI colour codes, or to increase terminal driver speed!
	de_colour();
}

if ($PRINT_TEMPLATE and $OUTPUT_FILE){	//Template file goes to STDOUT, not to OUTPUT_FILE. Could be misleading (that the template might be written to the file given with -o)
	print_warning("the -o and -e options potentially conflict. Ignoring -o.");
}

if ( ( ($OUTPUT_FILE == '-') or ($INPUT_FILE == '-') ) and $SIMULATION_FULL ){ //Stdin and Stdout are needed by full simulation, can't be used for file as well.
	fatal_error("Full simulation uses both stdout and stdin, so cannot be combined with '-i -' or '-o -'.");
}

$labels = explode(",",trim($VCD_LABELS_LIST));  //Up to 24 comma-separated labels. '-' means skip this bit.
$VCD_BITS = count($labels);			//(checked <= 24 below).
for ($i=0;$i < $VCD_BITS; $i++){
	$name=trim(array_pop($labels));
	if ((trim($name)) and ($name != $NA)){
		$VCD_LABELS[$i] = $name;
	}
}
if ($VCD_FILE and (!$VCD_LABELS_LIST)){		//Default values, if none supplied.
	for ($i=0;$i<24;$i++){
		$VCD_LABELS[$i] = "Bit_$i";
		$VCD_BITS=$i;
	}
}

$define_opts = array();	//Process the DEFINE_OPTS array into key/value.
if ($DEFINE_OPTS){
	foreach ($DEFINE_OPTS as $define){
		$bits = explode ("=",$define);
		$const = trim($bits[0]);
		if (count($bits) == 1){				//-DFoo (or -DnoFoo)
			if (substr(strtolower($const),0,2) == "no"){   //important - case insensitive No vs no.
				$define_opts[substr($const,2)] =  "";
			}else{
				$define_opts[$const] =  1;
			}
		}elseif (count($bits) == 2){			//-DFoo=bar
			$value = trim($bits[1]);
			$define_opts[$const] =  $value;
		}else{
			fatal_error ("Wrong syntax '$define' for -D. Syntax is '-Dfoo=bar'");
		}
	}
}

if ($DEBUG){
	print_msg("\n################### ${BLUE}ARGUMENT PARSING${NORM} ##################################################################################");
	print_msg ("These are the arguments to the script:");
	for ($i=0;$i<$argc;$i++){
		print_msg ("\tArgument ".str_pad($i,3)." was:  $argv[$i]");
	}
	print_msg("\nHere are the results of getopt:");
	foreach ($options_array as $key => $value){
		if ($value===false){$falsity="[FALSE]";}else{$falsity="";}
		if (is_array($value)){$value = "ARRAY: ".implode(",",$value); }
		print_msg("\tkey:\t".str_pad($key,12)."\tvalue is: ${value}${falsity}");
	}
	foreach ($define_opts as $key => $value){
		print_msg("\tdefine:\t".str_pad($key,12)."\t$value");
	}
}

//--------------------------------------------------------------------------------------------------------------
//PULSEBLASTER DEFINITIONS, from pulseblaster.h via pb_print_config

$HEADER=array();  				//Important constants defined by the header.
						//These are the keys that we care about. Initialise them to FALSE:
$HEADER["PB_VERSION"]=false;			//  Pulseblaster model version.
$HEADER["PB_CLOCK_MHZ"]=false;			//  PB clock frequency, in MHz
$HEADER["PB_TICK_NS"]=false;			//  PB clock period, in ns. This is (probably) an integer, but we shouldn't count on remaining one!
$HEADER["PB_MEMORY"]=false;			//  PB memory. Usually 512 Byte, or 32kByte.
$HEADER["PB_LOOP_MAXDEPTH"]=false;		//  Max loop depth for PB.
$HEADER["PB_SUB_MAXDEPTH"]=false;		//  Max subroutine depth for PB.
$HEADER["PB_INTERNAL_LATENCY"]=false;		//  Internal Latency of PB controller. Only defined here as a reminder that we explicitly do not use it! [See pb_write_vliw().]
$HEADER["PB_MINIMUM_DELAY"]=false;		//  Shortest delay (in ticks) that the PB can cope with.
$HEADER["PB_WAIT_LATENCY"]=false;		//  Wait Latency of PB controller. Only defined here as a reminder that we explicitly not use it! [See pb_write_vliw().]
$HEADER["PB_MINIMUM_WAIT_DELAY"]=false;		//  Shortest delay (in ticks) of a WAIT instruction. See pulseblaster.h in pb_utils for more explanation.
$HEADER["PB_BUG_PRESTOP_EXTRADELAY"]=false;	//  Bug: the instruction which precedes a STOP must be PB_BUG_PRESTOP_EXTRADELAY longer than PB_MINIMUM_DELAY.
$HEADER["PB_BUG_WAIT_NOTFIRST"]=false;		//  Bug: wait may not be the first instruction. See pulseblaster.h in pb_utils for more explanation.
$HEADER["PB_BUG_WAIT_MINFIRSTDELAY"]=false;	//  Bug: if wait is the 2nd instruction, the first instruction's delay must be at least this long.
$HEADER["PB_OUTPUTS_24BIT"]=false;		//  Output range from 0 to FF,FF,FF.  Hardcoded, by virtue of VLIW format. Be very careful if you modify this!!
$HEADER["PB_DELAY_32BIT"]=false;		//  Delay range, from 0 to FF,FF,FF,FF.  Hardcoded, in VLIW format
$HEADER["PB_ARG_20BIT"]=false;			//  Argument max size. Hardcoded in VLIW format
$HEADER["PB_LOOP_ARG_MIN"]=false;		//  Arg for the loop instruction >= 1
$HEADER["PB_BUG_LOOP_OFFSET"]=false;		//  Loopcounter correction for the PB controller. Only defined here as a reminder that we explicitly do not use it! [See pb_write_vliw().]
$HEADER["PB_LONGDELAY_ARG_MIN"]=false;		//  Arg for the long delay instruction >= 2
$HEADER["PB_BUG_LONGDELAY_OFFSET"]=false;	//  Longdelay correction for the PB controller. Only defined here as a reminder that we explicitly do not use it! [See pb_write_vliw().]
$HEADER["VLIWLINE_MAXLEN"]=false;		//  Max length of a line in a VLIW file. (Including the trailing \n and the terminating \0)
//If we add to these, also add to the PRINT_CONFIG section.

//Which header data? From pulseblaster.h via pb_print_config. Must match the installed pb_utils. 
$hb_candidate0 = dirname($argv[0])."/$HEADER_BINARY";			//Try /usr/*/bin (matching this file,
$hb_candidate1 = dirname($argv[0])."/$HEADER_BINARY_DEVEL";		//then fall back to development tree if necessary
if (is_executable($hb_candidate0)){
	$hb = $hb_candidate0;
}elseif (is_executable($hb_candidate1)){
	$hb = $hb_candidate1;
}else{
	fatal_error("could not find command '$HEADER_BINARY' to obtain header configuration. Please install pb_utils.");
}

debug_print_msg("\n################### ${BLUE}HEADER INFO AND DEFINITIONS${NORM} #####################################################################");
debug_print_msg("Now getting header data from '$HEADER_BINARY'.");
unset ($lines_array);
$lastline = exec ($hb, $lines_array, $retval);		//read into array, line-at-a-time. Format is "NAME: value\n"
if ($retval != 0){
	fatal_error("failed to run command '$hb' (retval: $retval)");
}
foreach($lines_array as $line){				//For each line...
	$line=trim($line);
	$words=preg_split('/: /',$line);		//Split up line by ": ".
	if (array_key_exists($words[0], $HEADER)) {	//If the 1st word is a KEY in the $HEADER array (i.e. we care about it), then set its value to the 2nd word.
		$HEADER[$words[0]]=$words[1];
		debug_print_msg("\t".basename($HEADER_BINARY).": defined ".str_pad($words[0],30)."  =  $words[1]");
	}
}
debug_print_msg("");

foreach ($HEADER as $key => $value){		//Check that all the keys are defined.
	if ($value === false){
		fatal_error("\$HEADER[$key] is undefined. Please define it in $HEADER_BINARY\n");
	}
}

//Sanity check the definitions. This should never fail...
if (($HEADER["PB_MEMORY"]!=($HEADER["PB_MEMORY"]+0)) or ($HEADER["PB_MEMORY"] < 128) or ($HEADER["PB_MEMORY"] > 1048576)){	//The test for $x==$x+0 is to check "is this string an integer"?
	fatal_error ("silly value of '$HEADER[PB_MEMORY]' for \$HEADER[\"PB_MEMORY\"]\n");
}
if (($HEADER["PB_LOOP_MAXDEPTH"]!=($HEADER["PB_LOOP_MAXDEPTH"]+0)) or ($HEADER["PB_LOOP_MAXDEPTH"] < 2) or ($HEADER["PB_LOOP_MAXDEPTH"] > 1024)){
	fatal_error ("silly value of '$HEADER[PB_LOOP_MAXDEPTH]' for \$HEADER[\"PB_LOOP_MAXDEPTH\"]\n");
}
if (($HEADER["PB_SUB_MAXDEPTH"]!=($HEADER["PB_SUB_MAXDEPTH"]+0)) or ($HEADER["PB_SUB_MAXDEPTH"] < 2) or ($HEADER["PB_SUB_MAXDEPTH"] > 1024)){
	fatal_error ("silly value of '$HEADER[PB_SUB_MAXDEPTH]' for \$HEADER[\"PB_SUB_MAXDEPTH\"]\n");
}
/* Note: Explicitly not using: $HEADER["PB_INTERNAL_LATENCY"]. So we don't care about it here. */
if (($HEADER["PB_MINIMUM_DELAY"]!=($HEADER["PB_MINIMUM_DELAY"]+0)) or ($HEADER["PB_MINIMUM_DELAY"] < 1) or ($HEADER["PB_MINIMUM_DELAY"] > 64)){
	fatal_error ("silly value of '$HEADER[PB_MINIMUM_DELAY]' for \$HEADER[\"PB_MINIMUM_DELAY\"]\n");
}
/* Note: Explicitly not using: $HEADER["PB_WAIT_LATENCY"] So we don't care about it here. */
if (($HEADER["PB_MINIMUM_WAIT_DELAY"]!=($HEADER["PB_MINIMUM_WAIT_DELAY"]+0)) or ($HEADER["PB_MINIMUM_WAIT_DELAY"] < 1) or ($HEADER["PB_MINIMUM_WAIT_DELAY"] > 64)){
	fatal_error ("silly value of '$HEADER[PB_MINIMUM_WAIT_DELAY] for \$HEADER[\"PB_MINIMUM_WAIT_DELAY\"]\n");
}
if (($HEADER["PB_BUG_PRESTOP_EXTRADELAY"]!=($HEADER["PB_BUG_PRESTOP_EXTRADELAY"]+0)) or ($HEADER["PB_BUG_PRESTOP_EXTRADELAY"] < 0) or ($HEADER["PB_BUG_PRESTOP_EXTRADELAY"] > 10)){
	fatal_error ("silly value of '$HEADER[PB_BUG_PRESTOP_EXTRADELAY]' for \$HEADER[\"PB_BUG_PRESTOP_EXTRADELAY\"]\n");
}
if (($HEADER["PB_BUG_WAIT_NOTFIRST"]!=($HEADER["PB_BUG_WAIT_NOTFIRST"]+0)) or ($HEADER["PB_BUG_WAIT_NOTFIRST"] < 0) or ($HEADER["PB_BUG_WAIT_NOTFIRST"] > 10)){
	fatal_error ("silly value of '$HEADER[PB_BUG_WAIT_NOTFIRST]' for \$HEADER[\"PB_BUG_WAIT_NOTFIRST\"]\n");
}
if (($HEADER["PB_BUG_WAIT_MINFIRSTDELAY"]!=($HEADER["PB_BUG_WAIT_MINFIRSTDELAY"]+0)) or ($HEADER["PB_BUG_WAIT_MINFIRSTDELAY"] < 0) or ($HEADER["PB_BUG_WAIT_MINFIRSTDELAY"] > 64)){
	fatal_error ("silly value of '$HEADER[PB_BUG_WAIT_MINFIRSTDELAY]' for \$HEADER[\"PB_BUG_WAIT_MINFIRSTDELAY\"]\n");
}
if (($HEADER["VLIWLINE_MAXLEN"]!=($HEADER["VLIWLINE_MAXLEN"]+0)) or ($HEADER["VLIWLINE_MAXLEN"] < 80)){
	fatal_error ("silly value of '$HEADER[VLIWLINE_MAXLEN]' for \$HEADER[\"VLIWLINE_MAXLEN\"]\n");
}


//These ones ought to be hardcoded. Checking them here, just in case. (These need to be fixed if a new pulseblaster has a different VLIW length).
if (($HEADER["PB_OUTPUTS_24BIT"]!=($HEADER["PB_OUTPUTS_24BIT"]+0)) or ($HEADER["PB_OUTPUTS_24BIT"] != 0xFFFFFF)){
	fatal_error ("silly value of '$HEADER[PB_OUTPUTS_24BIT]' for \$HEADER[\"PB_OUTPUTS_24BIT\"]\n");
}
if ($HEADER["PB_DELAY_32BIT"]+0 != 0xFFFFFFFF){		//This works, because of integer overflow, which (in PHP) casts to float, rather than wraps.
	fatal_error ("silly value of '$HEADER[PB_DELAY_32BIT]' for \$HEADER[\"PB_DELAY_32BIT\"]\n");
}
if (($HEADER["PB_ARG_20BIT"]!=($HEADER["PB_ARG_20BIT"])) or ($HEADER["PB_ARG_20BIT"] != 0xFFFFF)){
	fatal_error ("silly value of '$HEADER[PB_ARG_20BIT]' for \$HEADER[\"PB_ARG_20BIT\"]\n");
}
if (($HEADER["PB_LOOP_ARG_MIN"]!=($HEADER["PB_LOOP_ARG_MIN"]+0)) or ($HEADER["PB_LOOP_ARG_MIN"] !=1)){
	fatal_error ("silly value of '$HEADER[PB_LOOP_ARG_MIN]' for \$HEADER[\"PB_LOOP_ARG_MIN\"]\n");
}
/* Note: Explicitly not using: $HEADER["PB_BUG_LOOP_OFFSET"]. So we don't care about it here. */
if (($HEADER["PB_LONGDELAY_ARG_MIN"]!=($HEADER["PB_LONGDELAY_ARG_MIN"]+0)) or ($HEADER["PB_LONGDELAY_ARG_MIN"] < 1) or ($HEADER["PB_LONGDELAY_ARG_MIN"] > 64)){
	fatal_error ("silly value of '$HEADER[PB_LONGDELAY_ARG_MIN]' for \$HEADER[\"PB_LONGDELAY_ARG_MIN\"]\n");
}
/* Note: Explicitly not using: $HEADER["PB_BUG_LONGDELAY_OFFSET"]. So we don't care about it here. */
if ($ARCH=='32BIT'){  //In case of future expansion, check that the values still fit within a signed int on the 32-bit architecture (if that is what we are using).
	if ( ($HEADER['PB_ARG_20BIT'] > PHP_INT_MAX) or ($HEADER['PB_OUTPUTS_24BIT'] > PHP_INT_MAX) ){
		fatal_error ("at least one of 'PB_ARG_20BIT' or 'PB_OUTPUTS_24BIT' is now misnamed, and has outgrown the value which can fit into a signed int on a 32-bit platform.");
	}
}

$string_20_BIT="2^".round(log($HEADER["PB_ARG_20BIT"]+0,2))." -1";			//For human-readability, express some of these binary numbers as 2^x -1.
$string_24_BIT="2^".round(log($HEADER["PB_OUTPUTS_24BIT"]+0,2))." -1";			//But it would be cheating to hard-code them!
$string_32_BIT="2^".round(log($HEADER["PB_DELAY_32BIT"]+0,2))." -1";

if ($VCD_BITS > round(log($HEADER["PB_OUTPUTS_24BIT"]+0,2))){
	fatal_error("Labels list ('-L $VCD_LABELS_LIST') contains too many labels: there aren't that many bits in the output");
}

//--------------------------------------------------------------------------------------------------------------
//Print the definitions with explanations (if invoked with -c)

function bool2str(&$boolean){	//Turn values such as '1' and '0' into "On" and "Off".
	if ($boolean){		//Done by reference, using &$.
		$boolean='On';	//See also the #hwassert test.
	}else{
		$boolean='Off';
	}
}

if ($PRINT_CONFIG){
	//For each of the important definitions above, print something. Then, exit.
	print_msg("Now printing configuration settings. These are defined either internally, or in the header file\n");

	bool2str($USE_DWIM_FIX);			//Make the booleans into more friendly strings. Otherwise, we just get '1' and ''.
	bool2str($SIMULATION_USE_LOOPCHEAT);
	bool2str($NO_CLOBBER);
	bool2str($DEBUG);
	bool2str($SIMULATION_VERBOSE_REGISTERS);
	bool2str($HEADER["PB_BUG_WAIT_NOTFIRST"]);

	//NOTE: the weird indentation below is correct, when printed. 
	$CONFIG = <<<EOT
PARSER INTERNALS:

	These are the internal configurations of this parser:

	  * Header configuration:	$HEADER_BINARY
	  * Input filename extension:	.$SOURCE_EXTN
	  * Output filename extension:	.$OUTPUT_EXTN

	  * DWIM enabled:		$USE_DWIM_FIX		("Do what I mean")
	  * Simulation loopcheat:	$SIMULATION_USE_LOOPCHEAT		(Optimise simulation)
	  * Max Macro nest depth	$MAX_MACRO_INLINE_PASSES		(Sometimes 1 lower in practice)
	  * Max length of VLIW line	$HEADER[VLIWLINE_MAXLEN]		(Shared by $PROGRAMMER)


	These are internal defaults, which can be overridden by command-line options:

	  * No clobber:			$NO_CLOBBER		(Don't overwrite existing output file)
	  * Debug:			$DEBUG		(Verbose debugging messages)
	  * Simulation verbose:		$SIMULATION_VERBOSE_REGISTERS		(Simulation verbosity)


PULSEBLASTER HARDWARE:

	These are the configuration settings for the PulseBlaster hardware. They are defined for pb_utils; see 
	pb_print_config (or pulseblaster.h). Some of them are model-dependent, some seem to be design-limitations:

	  * Model:			$HEADER[PB_VERSION]		(Constant: PB_VERSION)
	  * Clock speed (MHz):		$HEADER[PB_CLOCK_MHZ]		(Constant: PB_CLOCK_MHZ)
	  * Clock tick (ns):		$HEADER[PB_TICK_NS]		(Constant: PB_TICK_NS)
	  * Internal RAM (Bytes):	$HEADER[PB_MEMORY]		(Constant: PB_MEMORY)

	  * Max Loop Depth:		$HEADER[PB_LOOP_MAXDEPTH]		(Number of nested loops)
	  * Max Subroutine Depth:	$HEADER[PB_SUB_MAXDEPTH]		(Number of nested subroutines)

	  * Minimum Delay (ticks):	$HEADER[PB_MINIMUM_DELAY]		(Shortest allowed LENGTH)
	  * Minimum Wait Delay (ticks):	$HEADER[PB_MINIMUM_WAIT_DELAY]		(Shortest allowed LENGTH for a WAIT)
	  * Minimum Pre-Stop Delay:	$HEADER[PB_BUG_PRESTOP_EXTRADELAY]		(Shortest allowed LENGTH for instruction preceeding STOP)

	  * Minimum Loop counter arg:	$HEADER[PB_LOOP_ARG_MIN]		(Smallest allowed ARG for a LOOP)
	  * Minimum LongDelay arg:	$HEADER[PB_LONGDELAY_ARG_MIN]		(Smallest allowed ARG for LONGDELAY - but see DWIM)

	  * Wait may not be 1st instr:	$HEADER[PB_BUG_WAIT_NOTFIRST]		(Wait may not be the first instruction)
	  * Min 1st length if wait 2nd: $HEADER[PB_BUG_WAIT_MINFIRSTDELAY]		(If 2nd instruction is WAIT, 1st LENGTH must be >= $HEADER[PB_BUG_WAIT_MINFIRSTDELAY])

	  * OUTPUT range (24-bit):	[0,$HEADER[PB_OUTPUTS_24BIT]]	(24-bit OUTPUT, required by in WLIW format)
	  * LENGTH range (32-bit):	[0,$HEADER[PB_DELAY_32BIT]]	(32-bit LENGTH range, required by in WLIW format)
	  * ARG range (20-bit):		[0,$HEADER[PB_ARG_20BIT]]	(20-bit ARG range, required by in WLIW format)

	The following weirdnesses of the hardware are abstracted away. This abstraction
	is performed by $ASSEMBLER, *not* by pb_parse. Provided that you use pb_parse and
	$ASSEMBLER together, you can safely forget about these:

	  * Internal Latency (ticks):	$HEADER[PB_INTERNAL_LATENCY]		(Offset to LENGTH, required by hardware)
	  * Wait Latency (ticks):	$HEADER[PB_WAIT_LATENCY]		(Offset to LENGTH, required by hardware during WAIT)
	  * Loop Offset:		$HEADER[PB_BUG_LOOP_OFFSET]		(Offset to Loop counter, required by hardware)
	  * Longdelay Offset:		$HEADER[PB_BUG_LONGDELAY_OFFSET]		(Offset for LongDelay, required by hardware)
EOT;
	print_msg("$CONFIG\n");
	exit ($EXIT_SUCCESS);   //Note: if we ever decide NOT to exit here, we must undo the effect of bool2str().
}


//--------------------------------------------------------------------------------------------------------------
//TEMPLATE pbsrc file.
//NOTE this has the "standard" layout, in particular: "LABEL:\t\tOUTPUT\t\t\tLENGTH\t\tOPCODE\t\tARG\t\t//comment", where OUTPUT is followed by *3* tabs, not just 2.
//Stick to this, and the debug-output will be nicely aligned.
if ($PRINT_TEMPLATE){	//NOTE: we have to \escape all the '$' signs.   Note2: the alignment is exactly right when evaluated, even if the tabs sometimes look wrong below.
$TEMPLATE = <<<EOT
/* This is an example .$SOURCE_EXTN file (created by pb_parse -e) The format is documented fully in doc/pbsrc.txt.								*
 * Here, we demonstrate most of the features, as a quick reference. Everything is case-sensitive, except opcode names.	 						*
 * For hardware config details (max loop/subroutine stack-depth, clock-speed, min allowed value of length etc), invoke pb_parse with -c 				*
 * The parser is very good at catching errors, e.g. illegal instructions, or invalid values. Simulate to catch stack/loop-depth bugs.					*
 * VLIW     summary: Output; do Opcode(ARG) taking LENGTH to execute. May write: out,opc,arg,len; opc,arg,out,len; or out,len,opc,arg.  				*	
 * Numbers  summary: 0xFF, 1123, 0b_0000_1111__0000_1111, 20_ticks, 10_us, 5ms, 3.5_day, 20_ticks. (underscores optional for clarity).					*
 * Opcodes  summary: cont[inue], longdelay, loop, endloop, goto, call, return, wait, stop, nop, debug, mark, never.							*
 * Keywords summary: #include, #hwassert, #assert, #define, #what, #default:, #if, #ifnot, #set, #vcdlabels, #endhere, #execinc, #macro, #echo, same, short, auto.	*
 * Bitwise  summary: bit_{or, set, clear, mask, and, flip, xor, xnor, nand, nor, add, sub, bus, rlf, rrf, slc, src, sls, srs}(xxx)					*
 * Operator summary: Math: (),+,-,*,/,%  Bit: (,),|,&,^,~,<<,>>  Compare: ==,!=,<,>,<=,>=  Logic: &&,||,!,?,:  Err_ctl: @. '/' is float.				*
 * Description:  This is a contrived example; it's not meant to be useful. It's convoluted so as to check all features of pb_parse.					*
 * Author: $AUTHOR, Date: $RELEASE_DATE																*
 */

#hwassert PB_TICK_NS	$HEADER[PB_TICK_NS]			//Check/enforce the value of a configuration setting of pb_utils, via pb_print_config. (Optional)

#include  "$DEV_NULL"				//Include a file. Filename between double-quotes. Nested includes are not allowed.

#define	  T		100us			//Define a constant T to be the string 100us (subsequently evaluated as 100 microseconds).
#define	  LOOP_5_TIMES	loop   5		//The value of a #define needn't be a single word.
#define	  CLOCK		0x10000			//Use '#define constname #what' to indicate requirement for '-Dconst=???' with pb_parse
#define	  TRIG		#default:0x20000	//This defaults to 0x20000 but may optionally be overridden with -D. (#what requires -D).
#define   BIT7		0x100			//Friendly mnemonics for some output bits.
#define   BIT9		0x400
#define   X		100			//Used in expressions below.
#define	  Y		200
#define	  P		0xF0
#define	  Q		0x12

#vcdlabels  TRIG,CLOCK,-,-,-,-,-,-,-,-,BIT7,-,-,-,-,-,-,-    //Signal names to monitor in the VCD file.

#set OUTPUT_BIT_INVERT  0x800000|CLOCK			//Set that these outputs are active_low. At the end of the parsing, these bits will be flipped.

#execinc  "/bin/echo"  #define XZZY  22+Y		//Example #execinc. This one is trivial, using echo to simply return a dummy #define.

#assert   (X+Y)	>  	10				//Check the truth of an expression.

/*** MACRO definitions. Macros are declared with the '#macro' keyword, and are inlined by the parser. ***/

//LABEL:	OUT/OUT/OPC		OPC/LEN/ARG	ARG/OPC/OUT	LEN/ARG/LEN	//comment

#macro	trigger() {									//trigger() macro takes no arguments.
		bit_set(TRIG)		cont		-		T		//Set, then clear the trigger bit.
		bit_clear(TRIG)		cont		-		T
}

#macro  clock_n(\$n,\$t){  								//clock_n() macro takes two arguments. Squelch the warning.
	lbl:	@bit_set(CLOCK)		loop		3*\$n+(4/2)	\$t		//Pulse the clock line 3*n+1 times.  Note: inlining parenthesises (\$n).
		bit_clear(CLOCK)	endloop		lbl		\$t
#echo   Macro clock_n() was called with _n \$n and _t \$t .			 	//Parser will print this during the compile stage. Useful to see what the values are.
}

/*** THE MAIN PROGRAM STARTS HERE. (This is the first instruction, after macros have been inlined.) ***/

//LABEL:	OUT/OUT/OPC		OPC/LEN/ARG	ARG/OPC/OUT	LEN/ARG/LEN	//comment

		0x00			cont		-		1_s		//output 000000 for 1 second. Then continue.
		
#if(ABC)        0xabc			cont		-		10		//Enable or disable, with -DABC or -DNoABC
#ifnot(ABC)     0xcba			mark		-		10		//Disable or enable, with -DABC or -DNoABC. Mark breakpoint for simulation.

		trigger()								//call the trigger() macro.

lpstart:	loop			7		0x01		10		//Loop exactly n times. (n >= $HEADER[PB_LOOP_ARG_MIN])
		0x_FE_DC_BA		longdelay	auto		4_min		//Wait for ARG * LENGTH. Here, 4 minutes (using auto).

lpinner:	BIT7|BIT9		LOOP_5_TIMES			short		//start another loop. Output is logical_or of BIT7 and BIT9.

		clock_n(100,30*T)							//inline the clock_n macro with arguments 100, 30*T

		1234			short		endloop		lpinner		//End of inner loop. (Max nested loops = $HEADER[PB_LOOP_MAXDEPTH].) (Note: 1234 is decimal, i.e. 0x4d2).

		0b1111_00000		15_ticks	endloop		lpstart		//Loop back to start of this loop at label 'lpstart' (not lpstart+1), or continue. VLIW reordering.

		0xFF_FF_FF		call		sub_x		5.43s		//Output ... then wait ... then call subroutine 'sub_x'.

		(X>Y)?P:(Q<<2)		cont		-		(X+Y)*1us	//Example of conditional #define (ternary operator). As Y > X, the output is (Q<<2) i.e. 0x48

		clock_n( P?P:Q, (X>Y)?P*T:(Q*T) )					//A more complex macro call, with expressions.

		XZZY			wait		-		200+(301-1)	//Output, then WAIT for HW_TRIGGER. ON *wakeup*, wait 500 cycles, then continue.

		same			goto		lpstart		10*T		//Goto label 'loopstart'.

		/* We just did a GOTO. These instructions are never reached; here for completeness. */
		-			nop		-		-		//No operation. (shortest delay, outputs unchanged)
		0x33			stop		-		-		//Overloaded opcode. Set the outputs to 0x33, then STOP. HW_TRIGGER will restart from the beginning.



/*** SUBROUTINES START HERE. (Put subroutines at the end, or jump over them with a GOTO). Max depth of nested subroutines is $HEADER[PB_SUB_MAXDEPTH]. ***/

//LABEL:	OUT/OUT/OPC		OPC/LEN/ARG	ARG/OPC/OUT	LEN/ARG/LEN	//comment

sub_x:		0xFFFF00		 cont		-		60s		//Note: 60s gets 'promoted' to longdelay.
		bit_add(0x01),bit_rlf(2) cont		-		1us		//Next is a __return opcode macro, which merges into cont.
		__return								//Return from subroutine. Argument ignored.


/*** END OF PROGRAM ***/
EOT;
	print_msg("Writing a template .$SOURCE_EXTN file to stdout...");
	output ($TEMPLATE);
	exit($EXIT_SUCCESS);
}

//--------------------------------------------------------------------------------------------------------------
function read_mail(){	//Zawinski's Law.
	print_msg ("This program cannot yet read email. May we recommend Thunderbird or Alpine...\n");
	global $EXIT_SUCCESS;
	exit ($EXIT_SUCCESS);
}
function forty_two(){   //Ultimate answer.
	global $RED, $GREEN, $NORM, $EXIT_SUCCESS;
	$int = 42; $answer = str_repeat(" ".str_repeat ("${RED}$int  ${GREEN}$int  ",4)."${RED}$int${NORM}\n"." ".str_repeat ("${GREEN}$int  ${RED}$int  ",4)."${GREEN}$int${NORM}\n",3);
	print_msg ($answer);
	exit ($EXIT_SUCCESS);
}

//--------------------------------------------------------------------------------------------------------------
//CHECK AND OPEN FILES:
//Check filenames and extensions. This is very important - it prevents shooting of self in foot by swapping infile with outfile!
debug_print_msg("################### ${BLUE}OPENING FILES${NORM} #####################################################################################");

//source file
if (!$SOURCE_FILE){
	fatal_error("no source file was specified. (Maybe you forgot to use '-i'?)");
}elseif ($SOURCE_FILE == "-"){						//Source is stdin.
	$SOURCE_FILE = $DEV_STDIN;
}elseif (substr($SOURCE_FILE,-strlen($SOURCE_EXTN)) != $SOURCE_EXTN){	//Check that input filename has extension .pbsrc
	fatal_error("source_file '$SOURCE_FILE' must have extension '.$SOURCE_EXTN'");
}elseif (!file_exists($SOURCE_FILE)){					//Check if input file exists.
	fatal_error("could not read file '$SOURCE_FILE'.");
}
$EXTENSIONLESS_FILE = ($SOURCE_FILE == $DEV_STDIN) ? "stdin." : substr($SOURCE_FILE,0,-strlen($SOURCE_EXTN));	//Use input file as the base for pbsim etc, unless output file is explicit and not /dev/null or '-'.
debug_print_msg ("Source file is '$SOURCE_FILE'.");

//output file
if (!$OUTPUT_FILE){			//If no output file was specified, use the same name as input file, but renaming .pbsrc to .vliw.
	$OUTPUT_FILE=$EXTENSIONLESS_FILE . $OUTPUT_EXTN;
	debug_print_msg("Output filename not specified: using '$OUTPUT_FILE'.");
}elseif ($OUTPUT_FILE == $DEV_NULL){	//Output file is /dev/null. (No need to care about clobbering it)
	$NO_CLOBBER=false;
}elseif ($OUTPUT_FILE == '-'){		//Output file is STDOUT. (No need to care about clobbering it). Use /dev/stdout for simplicity.
	$NO_CLOBBER=false;
	$OUTPUT_FILE = $DEV_STDOUT;
}elseif (substr($OUTPUT_FILE,-strlen($OUTPUT_EXTN)) != $OUTPUT_EXTN){	//Else, check that output filename has extension .vliw.
	fatal_error("output_file '$OUTPUT_FILE' must have extension '.$OUTPUT_EXTN' (or it may be '$DEV_NULL' or '-').");
}else{
	$EXTENSIONLESS_FILE=substr($OUTPUT_FILE,0,-strlen($OUTPUT_EXTN)); //If output file is "normal", override the use of input file (above) as base.
}

if (file_exists($OUTPUT_FILE)){			//Check whether output file exists, what type it is, and whether it's ok to overwrite.
	$filetype = filetype ($OUTPUT_FILE);
	if ($filetype == "block"){		//Block device. Almost certainly don't want to overwrite a block device!!
		fatal_error("output file, '$OUTPUT_FILE' is a block device. You almost certainly don't want to do this!");
	}elseif( ($filetype == "file") and ($NO_CLOBBER) ){  	//Ordinary file, and it already exists. Only overwrite if -x specified
		fatal_error("output file '$OUTPUT_FILE' already exists: will not clobber it. Use -x to overwrite anyway.");
	}							//Otherwise, file is char, fifo, or link (eg /dev/stdout), so no-clobber is irrelevant.
}
if (!$fp_out=fopen($OUTPUT_FILE,"w")){			//Open (and truncate) output file for writing. Side effect: if we exit with a fatal_error, this file will be empty.
	fatal_error("could not open file '$OUTPUT_FILE' for writing.");  	 //This behaviour is beneficial: it prevents a stale .vliw file from being subsequently used by pb_prog.
}elseif ( ($OUTPUT_FILE != $DEV_NULL) and (!flock($fp_out, LOCK_EX | LOCK_NB))){ //Even better, fatal_error now removes the file entirely.
	fatal_error("could not lock file '$OUTPUT_FILE' with LOCK_EX.");	//Lock it. If we can't lock, don't block, but fail.
}
$filetype = filetype ($OUTPUT_FILE);
debug_print_msg("Outputting to '$OUTPUT_FILE'. Type is $filetype. Flocked successfully.");

//Assembled binary (if specified).
if ($DO_ASSEMBLY){
	unset ($output);	//Check assembler exists
	$lastline = exec ("which $ASSEMBLER 2>/dev/null", $output, $retval);
	if ($retval != 0){
		fatal_error("Assembler program '$ASSEMBLER'could not be found.");
	}
	$BINARY_FILE = $EXTENSIONLESS_FILE . $BINARY_EXTN;  //Same as output (input), but extn changed
	if (file_exists($BINARY_FILE)){			//Check whether binary file exists, what type it is, and whether it's ok to overwrite.
		$filetype = filetype ($BINARY_FILE);
		if ($filetype == "block"){		//Block device. Almost certainly don't want to overwrite a block device!!
			fatal_error("binary file, '$BINARY_FILE' is a block device. You almost certainly don't want to do this!");
		}elseif( ($filetype == "file") and ($NO_CLOBBER) ){  	//Ordinary file, and it already exists. Only overwrite if -x specified
			fatal_error("binary file '$BINARY_FILE' already exists: will not clobber it. Use -x to overwrite anyway.");
		}							//Otherwise, file is char, fifo, or link (eg /dev/stdout), so no-clobber is irrelevant.
	} //Don't try to flock() this.
	debug_print_msg("Assembled binary will be '$BINARY_FILE', created with '$ASSEMBLER'.");
}

//Simulation replay log (if -g)
$fp_pbsim = false;
if ($PBSIM_FILE){
	$PBSIM_FILE = $EXTENSIONLESS_FILE . $PBSIM_EXTN;  //Same as output (input), but extn changed
	if (substr($PBSIM_FILE,-strlen($PBSIM_EXTN)) != $PBSIM_EXTN){	//Check that pbsim filename has extension .pbsim  (redundant, unless we switch back to specifying "-g filename")
		fatal_error("pbsim file '$PBSIM_FILE' must have extension '.$PBSIM_EXTN'.");
	}
	if (file_exists($PBSIM_FILE)){			//Check whether pbsim file exists, what type it is, and whether it's ok to overwrite.
		$filetype = filetype ($PBSIM_FILE);
		if ($filetype == "block"){		//Block device. Almost certainly don't want to overwrite a block device!!
			fatal_error("pbsim file, '$PBSIM_FILE' is a block device. You almost certainly don't want to do this!");
		}elseif( ($filetype == "file") and ($NO_CLOBBER) ){  	//Ordinary file, and it already exists. Only overwrite if -x specified
			fatal_error("pbsim file '$PBSIM_FILE' already exists: will not clobber it. Use -x to overwrite anyway.");
		}							//Otherwise, file is char, fifo, or link (eg /dev/stdout), so no-clobber is irrelevant.
	}
	if (!$fp_pbsim=fopen($PBSIM_FILE,"w")){			//Open (and truncate) output file for writing. Side effect: if we exit with a fatal_error, this file will be empty.
		fatal_error("could not open file '$PBSIM_FILE' for writing."); //This behaviour is beneficial: it prevents a stale .pbsim file from being subsequently used
	}elseif (!flock($fp_pbsim, LOCK_EX | LOCK_NB)){			//fatal_error() now removes the file entirely.
		fatal_error("could not lock file '$PBSIM_FILE' with LOCK_EX.");//Lock it. If we can't lock, don't block, but fail.
	}

	$filetype = filetype ($PBSIM_FILE);
	debug_print_msg("Creating simulation replay log: '$PBSIM_FILE'. Type is $filetype. Flocked successfully.");
}

//VCD wavefile (if -G)
$fp_vcd = false;
if ($VCD_FILE){
	$VCD_FILE = $EXTENSIONLESS_FILE . $VCD_EXTN;  //Same as output (input), but extn changed
	if (substr($VCD_FILE,-strlen($VCD_EXTN)) != $VCD_EXTN){	//Check that vcd filename has extension .vcd  (redundant, unless we switch back to specifying "-G filename")
		fatal_error("vcd file '$VCD_FILE' must have extension '.$VCD_EXTN'.");
	}
	if (file_exists($VCD_FILE)){			//Check whether pbsim file exists, what type it is, and whether it's ok to overwrite.
		$filetype = filetype ($VCD_FILE);
		if ($filetype == "block"){		//Block device. Almost certainly don't want to overwrite a block device!!
			fatal_error("vcd file, '$VCD_FILE' is a block device. You almost certainly don't want to do this!");
		}elseif( ($filetype == "file") and ($NO_CLOBBER) ){  	//Ordinary file, and it already exists. Only overwrite if -x specified
			fatal_error("vcd file '$VCD_FILE' already exists: will not clobber it. Use -x to overwrite anyway.");
		}							//Otherwise, file is char, fifo, or link (eg /dev/stdout), so no-clobber is irrelevant.
	}
	if (!$fp_vcd=fopen($VCD_FILE,"w")){				//Open (and truncate) output file for writing. Side effect: if we exit with a fatal_error, this file will be empty.
		fatal_error("could not open file '$VCD_FILE' for writing."); //This behaviour is beneficial: it prevents a stale .pbsim file from being subsequently used
	}elseif (!flock($fp_vcd, LOCK_EX | LOCK_NB)){			//fatal_error() now removes the file entirely.
		fatal_error("could not lock file '$VCD_FILE' with LOCK_EX.");//Lock it. If we can't lock, don't block, but fail.
	}

	$filetype = filetype ($VCD_FILE);
	debug_print_msg("Creating value change dump file: '$VCD_FILE'. Type is $filetype. Flocked successfully.");
}

//Simulation output fifo.
$fp_sofifo = false;
if ($SIMULATION_OUTPUT_FIFO){			//Check the output file. This should normally be a named pipe, though it could perhaps be a regular file or /dev/null.
	if (file_exists($SIMULATION_OUTPUT_FIFO)){	//Try to open it, and create/truncate for writing. Flock LOCK_EX. Set up file pointers in array.
		$filetype = filetype($SIMULATION_OUTPUT_FIFO);
		if ($filetype == "block"){		//Block device. Almost certainly don't want to overwrite a block device!!
			fatal_error("Requested simulation output device, '$SIMULATION_OUTPUT_FIFO' is a block device. You almost certainly don't want to do this!");
		}elseif( ($filetype == "file") and ($NO_CLOBBER_DEV) ){  //Ordinary file, and it already exists. Only overwrite if -x specified
			fatal_error("simulation output file '$SIMULATION_OUTPUT_FIFO' already exists: will not clobber it. Use -x to overwrite anyway.");
		}elseif ($filetype != "fifo"){		 //Expect it to be "fifo", warn otherwise, though this might possibly be intentional.
			print_warning ("simulation output fifo '$SIMULATION_OUTPUT_FIFO' already exists, but it is of type '$filetype', not a named pipe.");
		}else{
			$is_fifo = true;
		}
	}else{
		if (!posix_mkfifo ($SIMULATION_OUTPUT_FIFO, 0644)){  //Create the fifo if it doesn't exist.
			fatal_error("could not create simulation output fifo '$SIMULATION_OUTPUT_FIFO'.");
		}
		$is_fifo = true;
	}
	($is_fifo == true) && print_notice ("fopen('fifo','w') will *block* till the other end is opened; use 'Ctrl-Z; kill -9 ".getmypid()."' if needed. Opening fifo '$SIMULATION_OUTPUT_FIFO' now... ", false);
	if (!$fp_sofifo=fopen($SIMULATION_OUTPUT_FIFO,"w")){	//File exists by now, either as fifo or possibly as regular file or char-device. Open for writing. Note that fopen() blocks if it's a fifo!
		fatal_error("could not open fifo/file '$SIMULATION_OUTPUT_FIFO' for writing.");
	}
	($is_fifo == true) && (!$QUIET) && print_msg (" ...OK.");
	if ((!flock($fp_sofifo, LOCK_EX | LOCK_NB)) and ($SIMULATION_OUTPUT_FIFO!=$DEV_NULL) ) { //Lock it. If we can't lock, don't block, but fail. (/dev/null is the exception)
		fatal_error("could not lock file '$SIMULATION_OUTPUT_FIFO' with LOCK_EX for simulation output.");
	}
	debug_print_msg("Opened (and flocked) simulation_output_device '$SIMULATION_OUTPUT_FIFO'. This is of type $filetype.");
}

$fatal_error_may_delete=true;  //Up till now, fatal error should NOT delete the files (eg "output file exists and should not be clobbered" ... fatal error ... delete ... oops!)

debug_print_msg("Now reading in file '$SOURCE_FILE'...");
$parser_start_time = microtime(true);
if ( $SOURCE_FILE != $DEV_STDIN){
	$source_contents_array=file($SOURCE_FILE);	//read source file into $source_contents_array This is an array, separated at \n, with the '\n's still attached to each element.
}else{	//workaround needed for stdin.
	while (!feof(STDIN)){
		$source_contents_array[] = fgets(STDIN);
	}
}

//--------------------------------------------------------------------------------------------------------------
//APPEND IDENTIFIERS TO EACH LINE OF SOURCE, SO THAT THEY CAN BE USED IN ERROR MESSAGES.
//Appending to each line is the only sane way to do it.Otherwise, one has to do something hideous with tracking lines, insertions,deletions, etc. [array_splice() might help, but not much].
//So, use some "in-band signalling". Can't do any serious harm - worst case is a slightly confusing comment. Adding //....   at end of line is safe, even within /*...*/
//However, if the line is blank, don't add pointless comments, just skip the line.
$source_contents='';
$sloc_count = count($source_contents_array);
for ($i=0; $i < $sloc_count; $i++){
	if (trim($source_contents_array[$i])){	//Append the string " //$PARSER_IBS source: $filename;$linenum"  before the \n.  Note: we search for this magic string later, so modify it with care.
		$line=rtrim($source_contents_array[$i],"\n")." //$PARSER_IBS source: $SOURCE_FILE;".($i+1)."\n";   //linenum is 1-based when talking about line-numbers in files.
		$source_contents.=$line;
	}
}

//--------------------------------------------------------------------------------------------------------------
//DEAL WITH MULTI-LINE COMMENTS (there are several places we may need to remove them.)
function mlc_remove_callback($mlc){
	global $PARSER_CMT;
	$numlines = (substr_count($mlc[0],"\n")+1);
	if ($numlines > 1){  //ignore trivial ones where the /* and */ are on the same line.
		debug_print_msg("\tMLC: Ignored $numlines-line long multiline comment.");
		return ("\t\t\t//$PARSER_CMT Ignored multiline comment of $numlines lines.\n");
	}else{
		return ("");
	}
}
function mlc_sanitise_comment($cmt){   //Not actually possible in theory... see below.
	$ss = $cmt[3].$cmt[4];  $ss_safe = $cmt[3]."_".$cmt[4]; 
	return ( $cmt[1].$cmt[2] . $ss_safe . str_replace($ss,$ss_safe, $cmt[5]));
}
function mlc_remove($contents){	//Remove /* ... */ from code.
	global $lines_array;  
	
	//We'd like to allow for the possibility of commenting out /* and */ with //. BUT that's impossible with regexs (what if the commented out line eg " stuff // xxx */" is itself within an MLC block?
	//Would need to iterate using a tokeniser. For now, just say that /* cannot be commented out. 
	//This approach does NOT work well enough.	
	// /* and */ can also appear within the commented //section  of a line. So these don't falsely get interpreted, de-fang them to  [* ...  *] .
	// $contents = preg_replace_callback ("/(\/\/)(.*)(\/)(\*)(.*)$/mU", "mlc_sanitise_comment", $contents);	  //Tested even with pathological things, eg:
	// $contents = preg_replace_callback ("/(\/\/)(.*)(\*)(\/)(.*)$/mU", "mlc_sanitise_comment", $contents);	  // "the_code_goes_here //  /* x */  /* "

 	//Replace /*...*/ by 'PARSER: Ignored multiline comment of XXX lines.
 	$contents = preg_replace_callback ('/\/\*.*\*\//sU', "mlc_remove_callback", $contents);  //Ungreedy - important.
 	if ($contents === NULL){
 		fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");  //Ungreediness can cause trouble on longish (>100k char) comments. See: DOCREFERENCE: PCRE-LIMIT
 	}
 
	//Check if any MLC were found and remain unmatched. If so, error.
	$lines_array=explode("\n",$contents);	//re-generate $lines_array for debugging
	$n = count($lines_array);    //experimentally, a *very* important optimisation for longer source files; must to only count this once (outside the for-loop). PHP bug #64518.
	for ($i=0; $i < $n ; $i++){  //otherwise, it interacts BADLY with exporting $lines_array as a global. eg "pbsrc_examples/large/32768-words-test.pbsrc" can go from 8 seconds -> over 8 minutes!
		if (strpos($lines_array[$i], '/*')!==false){
			fatal_error("found an un-matched '/*'. Note that /*...*/ take effect even within // comments. Error ".at_line($i));  //needs $lines_array to be exported as global above.
		}else if (strpos($lines_array[$i], '*/')!==false){
			fatal_error("found an un-matched '*/'. Note that /*...*/ take effect even within // comments. Error ".at_line($i)); 
		}
	}
	return ($contents);
}

$source_contents = mlc_remove ($source_contents); //Actually do it.

//--------------------------------------------------------------------------------------------------------------
//DEAL WITH #INCLUDEs

$lines_array=explode("\n",$source_contents);	//Split source contents into array of 1-line chunks delimited by \n
$contents="";					//The contents of the file will be stored in the STRING $contents, which is successively modified.

$n = count($lines_array); 
for($i=0; $i < $n ; $i++){			//For each line...
	if (preg_match('/^\#include\s+/',(trim($lines_array[$i])))){   // if the first non-whitespace part of line matches '#include', followed by space
		$q1=strpos($lines_array[$i],'"');		      	// then we need to include a file. Given syntax for includes of:
		$q2=strpos($lines_array[$i],'"',$q1+1);		      	// #include "path/to/filename.extn"
		if (($q1===false) or ($q2===false)){	      		// we know that the filename is between the first and second double-quote ('"') in the line.
			fatal_error("the #included filename is not properly double-quoted ".at_line($i));
		}
		$included_file=substr($lines_array[$i],$q1+1,$q2-$q1-1);

		if (preg_match ("/^$RE_RESERVED_WORDS\$/i", $included_file)){ //Warn, incase anyone does something truly daft. This probably indicates a mistake.
			fatal_error("included filenames may not be keywords! You tried to #include a file called '$included_file', which is probably a mistake.");
		}
		if (substr($included_file,0,1) != "/" ){      //If included file is not specified with absolute path, assume it is relative to SOURCE file. (not the current directory, as PHP would assume)
			$included_file=dirname($SOURCE_FILE)."/".$included_file;
		}
		if (!file_exists($included_file)){	    //Check if $included_file exists.
			fatal_error("included file '$included_file' does not exist ".at_line($i));
		}
		$contents.="\t\t\t//$PARSER_CMT Including file: '$included_file'\n";  //Internal comment for debug.

		$includefile_contents_array=file($included_file);	//Insert the included file into contents.
		$incnum = count($includefile_contents_array);
		for ($j=0; $j < $incnum; $j++){				//Append identifiers to each line of source, so that they can be used in error messages.
			if (trim($includefile_contents_array[$j])){	//Append the string " //$PARSER_IBS source: $filename;$linenum"  before the \n.  Note: we search for this magic string later, so modify it with care.
				$include_line=rtrim($includefile_contents_array[$j],"\n")." //$PARSER_IBS source: $included_file;".($j+1)."\n"; //linenum is 1-based when talking about line-numbers in files.
				$contents.=$include_line;
			}
		}

		$contents.="\n\t\t\t//$PARSER_CMT End of included file '$included_file'.\n";
		debug_print_msg("\tIncluded file '$included_file'.");
		$sloc_count += $incnum;
	}else{
		$contents.="$lines_array[$i]\n";			    //Otherwise, just append the line followed by \n.
	}
}
if (preg_match('/\n\s*\#include/',$contents)){			//Now check if we've included any #includes. We can't nest them! (Well, we could, if we wanted to,
	fatal_error("cannot nest #includes  - a #included file cannot #include another one."); //but it would require being recursive. Trap the error.)
}

//--------------------------------------------------------------------------------------------------------------
//DEAL AGAIN WITH MULTI-LINE COMMENTS - perhaps there were some more of them in #included files.
$contents = mlc_remove ($contents);

//--------------------------------------------------------------------------------------------------------------
//DEAL WITH #HWASSERTs and #VCDLABELS and #ENDHERE
//This allows a .pbsrc file to assert (ie. check/enforce) a particular value defined in pb_print_config. For example, if the .pbsrc file expects a tick to be 10ns, it can trigger a parser error
//if that turns out not to be true. [In future, perhaps this mechansim should be used to modify values, rather than just checking them...]
$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n
$contents="";
debug_print_msg("\n################### ${BLUE}CHECKING #hwasserts (and #vcdlabels and #endhere)${NORM} #################################################");
$n = count($lines_array);
for ($i=0; $i < $n; $i++){				//For each line...
	if (preg_match('/^\#hwassert\s+/',(trim($lines_array[$i])))){	//if the first non-whitespace part of line matches '#hwassert', (followed by space)
 		$tokens=preg_split('/\s+/',trim($lines_array[$i]));	//then we want to verify that $HEADER[$config'] is set to $value. The syntax is:
		$config=$tokens[1];					//#hwassert CONFIG VALUE
		$value=$tokens[2];					//CONFIG should (by convention) be upper-case; $value probably isn't.
		if ((!$config) or (!$value)){
			fatal_error("the #hwasserted constant and its value are not properly defined ".at_line($i));
		}
		if (substr($value,0,2)=='//') { 		//Check that the value doesn't begin with a comment. (including $PARSER_MAGIC_IBS)
			fatal_error("the hwasserted value may not begin with '//'. Error ".at_line($i));
		}
		if (($tokens[3]) and (substr($tokens[3],0,2)!='//')){ //Check that there is nothing else on this line (except, perhaps a comment).
			fatal_error("this lines contains too many tokens for a #hwassert. (3rd token is '$tokens[3]'). Error ".at_line($i));
		}
		if ($config != strtoupper($config)){		//check is upper case
			print_warning ("the configuration setting '$config' isn't upper-case. This breaks convention, and won't be found as a key of \$HEADER. Warning ".at_line($i));
		}
		if (!isset($HEADER[$config])){		//Is '$config' known as an index for $HEADER?
			fatal_error("cannot hwassert that the configuration setting for $config, since it is not recognised as a \$HEADER[] constant.");
		}else{
			if ($HEADER[$config] == $value){ 	    //Test for equality. Deliberately use '==' rather than '==='
				debug_print_msg("\tConfiguration hwassertion verified: \$HEADER[$config] is indeed equal to '$value'.");
			}else if ( (strtolower($value)=='on') and ($HEADER[$config]) ){  // On and 1 are equivalent.  See bool2str().
				debug_print_msg("\tConfiguration hwassertion verified: $HEADER[$config] is logically equal to '$value'.");
			}else if ( (strtolower($value)=='off') and (!$HEADER[$config]) ){  //Off and 0 are equivalent.
				debug_print_msg("\tConfiguration hwassertion verified: $HEADER[$config] is logically equal to '$value'.");
			}else{
				fatal_error("configuration hwassertion failed: \$HEADER[$config] is actually defined to $HEADER[$config] in $HEADER_BINARY, but the source file, '$SOURCE_FILE' #hwasserts that it must be '$value'. Fatal error ".at_line ($i));
			}
		}

 	}elseif (preg_match('/^\#vcdlabel(s)?\s+/',(trim($lines_array[$i])))){	//if the first non-whitespace part of line matches '#vcdlabels', (followed by space)
   		$tokens=preg_split('/\s+/',trim($lines_array[$i]),2);	//then we want to use this to label the VCD bits. The syntax is:
  		$tokens=preg_split('/\/\//',$tokens[1]);		//  #vcdlabels COMMA_SEPARATED_LIST
  		$vcdlabels=trim($tokens[0]);
 		if (substr($vcdlabels,0,2)=='//'){ 		//Check that it doesn't begin with a comment. (including $PARSER_MAGIC_IBS)
 			fatal_error("#vcdlabels may not begin with '//'. Error ".at_line($i));
 		}elseif (($vcdlabels==='')){
 			fatal_error("the #vcdlabels are not properly assigned ".at_line($i));
 		}
 		if (!$VCD_LABELS_LIST){	//If not over-ridden by -L
 			$labels = explode(",",trim($vcdlabels));  //Up to 24 comma-separated labels. '-' means skip this bit.
 			$VCD_LABELS=array();
 			$VCD_BITS = count($labels);		   //(checked <= 24 below).
 			for ($j=0;$j < $VCD_BITS; $j++){
 				$name=trim(array_pop($labels));
 				if (($name) and ($name != $NA)){
 					$VCD_LABELS[$j] = $name;
 				}
 			}
 		}
 		$vcdlabels_txt='';
 		foreach ($VCD_LABELS as $bit => $name){
 			$vcdlabels_txt .= "Bit_$bit:$name, ";
 		}
 		debug_print_msg("\tVcdlabels are: ".rtrim($vcdlabels_txt,", ").".");

 	}elseif (preg_match('/^\#endhere\s+/',(trim($lines_array[$i])))){	//... if we find "#endhere", then discard everything after. Useful for debugging, similar to __halt_compiler().
		print_warning ("Found '#endhere' at line $i; ignoring everything after this line."); 	 //print warning, to prevent leaving this in production code by accident.
		break;  //this line is consumed, the rest are skipped.
	}else{
		$contents.="$lines_array[$i]\n";		//Otherwise, just append the line followed by \n
	}
}

//--------------------------------------------------------------------------------------------------------------
//DEAL WITH #DEFINEs
debug_print_msg("\n################### ${BLUE}PROCESSING AND SUBSTITUTING #defines${NORM} ##############################################################");

$defines_check_array=array();			//Check for duplicate defines.
$execinc_passes=0;
$do_process_defines = true;
$execinc = false;
while ($do_process_defines){			//Normally just one pass, but loop back here, iff #execinc adds more #defines.

	$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n
	$defines_search_array=array();			//an array which contains all things which are #defined.
	$defines_replace_array=array();			//$defines_search_array contains constants to be replaced by values in $defines_replace_array
	$real_program_started=false;			//So we can warn if a define is placed after code.
	$contents="";

	//Process definitions via the CLI with '-D'.  [These are equivalent to #define in the source; they may NOT be used to override them.]
	foreach ($define_opts as $constant => $value){  //Anything that REQUIRES a -D in the source can hint this by using "#define constant #what"
		if ($execinc){	//2nd time round via #execinc.
			break;
		}
		if (($constant==='') ){ //$value can be left blank.
			fatal_error("the '-D' defined constant ('$constant') and its value ('$value') are not properly assigned; constant may not be empty");
		}elseif (!preg_match("/^$RE_WORD\$/",$constant)){
			fatal_error("the '-D' defined constant ('$constant') may only contain 'word' characters (starting with a letter), i.e. '$RE_WORD'.");
		}elseif (preg_match ("/^$RE_RESERVED_WORDS\$/i", $constant)){  //Check we aren't trying to (re-)define a keyword! This is ALWAYS fatal.
			fatal_error("keywords cannot be redefined. Attempted to '-D' define '$constant'.");
		}elseif (in_array($constant,$defines_check_array)){	//Check whether the constant has ALREADY been defined. If so, we have a duplicate, which is BAD.
			fatal_error("constant: '$constant' has already been '-D' defined, and cannot be defined twice.");
		}else{
			$defines_check_array[]=$constant;	//append constant to this array, to check later for dups.
		}
		//Replace defined constants by their values. Replace:
		//	(1) instances of {$constant}   i.e. $constant which is surrounded by {}.   The {} are discarded.
		//	(2) isolated instances of $constant, i.e. delimited by a non-word character at both ends, so as to prevent substring trouble (never replace a substring within an ordinary string).
		//	    Note: non-word character here *excludes* '$'.  i.e. we do NOT want a parameter $t in a macro to be replaced by a #defined constant, 'T'.
		$constant = preg_quote($constant, "/");		//the constant should already be safe, given that it's an $RE_WORD.
		$value = str_replace (array('\\','$'),array('\\\\','\$'),$value);  //the equivalent of preg_quote() for value: escape backreferences.  
		array_push($defines_search_array,"/(?<!$RE_WORDCHAR_DOLLAR)$constant(?!$RE_WORDCHAR)/m");//(2) ASSERT that $constant is NOT immediately preceeded by a word character nor a literal '$' sign, and is NOT followed by a word character.
		array_push($defines_replace_array,$value);						//       '/m' is multiline modifier.
		array_push($defines_search_array,'/\{'.$constant.'\}/');				//(1) Trivial.
		array_push($defines_replace_array,$value);						//NOTE: (2) comes before (1), since we do array_reverse shortly below.
													//NOTE: the replacements will also take effect within comments. (Buglet)
		debug_print_msg("\tDefined (-D)        ".str_pad($constant,20)."  as:  $value");
	}

	//Process #defines, from within the source file.
	$n =  count($lines_array);
	for ($i=0; $i < $n; $i++){					//For each line...
		if (preg_match('/^\#define\s+/',(trim($lines_array[$i])))){	//if the first non-whitespace part of line matches '#define', (followed by space)
			$tokens=preg_split('/\s+/',trim($lines_array[$i]),3);	//then we want to define CONSTANT as VALUE. The syntax is:
			$constant=$tokens[1];					//   #define CONSTANT VALUE  [VALUE_CONT...]   //comment
			$tokens=preg_split('/\/\//',$tokens[2]);		//NOTE: VALUE is the entire rest of the line, upto (but excluding) the comments.
			$value=trim($tokens[0]);
			
			if ((substr($constant,0,2)=='//') or (substr($constant,0,2)=='//') ){ 	//Check that neither constant nor value begins with a comment. (including $PARSER_MAGIC_IBS)
				fatal_error("neither the #defined constant nor its value may begin with '//'. Error ".at_line($i));
			}elseif ($constant===''){   //originally, we insisted that value couldn't be empty; now it can.
				fatal_error("the #defined constant ('$constant') and its value ('$value') are not properly assigned; constant may not be empty ".at_line($i));
			}elseif (!preg_match("/^$RE_WORD\$/",$constant)){
				fatal_error("the #defined constant ('$constant') may only contain 'word' characters, i.e. '$RE_WORD' ".at_line($i));
			}elseif (preg_match ("/^$RE_RESERVED_WORDS\$/i", $constant)){  //Check we aren't trying to (re-)define a keyword! This is ALWAYS fatal.
				fatal_error("keywords cannot be redefined. Attempted to #define '$constant'. Error ".at_line($i));
			}
			if ($value == "#what"){ 		//Use keyword '#what' to denote that a -Dfoo=bar is *required*. Processed above.
				if (!array_key_exists($constant,$define_opts)){  //is the -Dfoo that we need present?
					fatal_error("Undefined '#what'. '$BLUE$constant$NORM' requires a definition on the command-line, but the appropriate '-Dfoo=bar' (i.e. '-D$constant=???') was not supplied. Error ".at_line($i));
				}
			}elseif ( (strpos($value,"#default")===0) and (array_key_exists($constant,$define_opts)) ){  //Use keyword '#default' to indicated that a -Dfoo=bar is *optional*.
				//#default: value already defined above; do nothing.
			}else{
				if (strpos($value,"#default")===0){	//#default: value not -D defined above, so remove the initial "#default:".
					$value = substr($value, strlen("#default:"));
				}elseif (strpos($value,"default:")===0){ //print helpful notice on common mistake of using "default:" rather than "#default".
					print_notice ("#define of '$constant' to '$value' looks like a typo: 'default:' is literal, but probably you meant the keyword '#default:' instead? Notice ".at_line($i));
				}
				if (in_array($constant,$defines_check_array)){	//Check whether the constant has ALREADY been defined. If so, we have a duplicate, which is BAD.
					fatal_error("constant: '$constant' has already been defined, and cannot be defined twice. Error ".at_line($i));
				}
				$defines_check_array[]=$constant;	//append constant to this array, to check later for dups.
				#[Used to require here that $value can't contain spaces. But this restriction is unnecessary, and leads to over-use of #macro]

				//Replace defined constants by their values. Replace:
				//	(1) instances of {$constant}   i.e. $constant which is surrounded by {}.   The {} are discarded.
				//	(2) isolated instances of $constant, i.e. delimited by a non-word character at both ends, so as to prevent substring trouble (never replace a substring within an ordinary string).
				//	    Note: non-word character here *excludes* '$'.  i.e. we do NOT want a parameter $t in a macro to be replaced by a #defined constant, 'T'.
				$constant = preg_quote($constant, "/");		//the constant should already be safe, given that it's an $RE_WORD.
				$value = str_replace (array('\\','$'),array('\\\\','\$'),$value);  //the equivalent of preg_quote() for value: escape backreferences.  
				array_push($defines_search_array,"/(?<!$RE_WORDCHAR_DOLLAR)$constant(?!$RE_WORDCHAR)/m");//(2) ASSERT that $constant is NOT immediately preceeded by a word character nor a literal '$' sign, and is NOT followed by a word character.
				array_push($defines_replace_array,$value);						//       '/m' is multiline modifier.
				array_push($defines_search_array,'/\{'.$constant.'\}/');				//(1) Trivial.
				array_push($defines_replace_array,$value);						//NOTE: (2) comes before (1), since we do array_reverse shortly below.
															//NOTE: the replacements will also take effect within comments. (Buglet)
				debug_print_msg("\tDefined (#define)   ".str_pad($constant,20)."  as:  $value");
				if ($real_program_started){
					print_warning("constant '$constant' defined AFTER start of code. This is legal ('#define's are processed in the first-pass no matter where they occur), but bad style, perhaps indicating a bug. Warning ".at_line($i));  //Defines *should* come first.
				}
			}
		}else{
			$contents.="$lines_array[$i]\n";				//Otherwise, just append the line followed by \n

			if(!(preg_match('/^\s*($|\/\/|#set|#echo|#assert|#execinc)/',$lines_array[$i]))){//Real program has started. We have seen a line which is neither a comment, nor empty. (nor a #set/#echo)
				$real_program_started=true;				//Already dealt with #hwassert and #include. If it's a macro definition we want to throw an error anyway.
			}
		}
	}
	
	$defines_replace_array=array_reverse($defines_replace_array, true); //Reverse the arrays. If there is a nested define, we want to apply the earlier definition last.
	$defines_search_array=array_reverse($defines_search_array, true);   //[Yes, this *is* the correct order to do it.]
	$contents=preg_replace($defines_search_array, $defines_replace_array, $contents);  //Do the search and replace for each element of each array.
	if ($contents === NULL){
		fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
	}
	
	//[Idea: would it be useful if we could warn if a #defined constant is never actually used? This can be done using preg_replace_callback(). Likely to be very noisy.]

//--------------------------------------------------------------------------------------------------------------
//DEAL *AGAIN* WITH MULTI-LINE COMMENTS - perhaps there were some more of them defined with -D, or from #execinc.
	$contents = mlc_remove ($contents);
	
//--------------------------------------------------------------------------------------------------------------
//DEAL WITH #EXECINCs
//If #execinc, then execute the program with args, and include the result. (Then go back to the #define stage).  [Possible security risk.]
//Each ARG has already been #defined; we also parse with parse_expr() if we can, then escapeshellarg. Flags eg "-x" are allowed, but no shell tricks.
//If CMD is executable, just run it; else run it with "php -r". Expect valid .pbsrc on stdout, and retval == 0; passthru stderr.
	$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n
	$contents="";
	$execinc = false;
	$execinc_forbidden_cmd = false;
	debug_print_msg("\n################### ${BLUE}PROCESSING #execincs${NORM} ##############################################################################");
	$n =  count($lines_array);
	for ($i=0; $i < $n; $i++){			//For each line...
		if (preg_match('/^\#execinc\s+/',(trim($lines_array[$i])))){	//if the first non-whitespace part of line matches '#execinc', (followed by space)
			$execinc = true;					//then we have something to exec.
			$q1=strpos($lines_array[$i],'"');		      	// then we need to include a file. Given syntax for includes of:
			$q2=strpos($lines_array[$i],'"',$q1+1);		      	// #include "path/to/filename.extn"
			if (($q1===false) or ($q2===false)){	      		// we know that the filename is between the first and second double-quote ('"') in the line.
				fatal_error("the #execinc'd filename is not properly double-quoted ".at_line($i));
			}	
			$cmd=substr($lines_array[$i],$q1+1,$q2-$q1-1);		//the command.
			$tokens=preg_split('/\/\//',trim(substr($lines_array[$i],$q2+1)),2);	//   #execinc prog  ARG1 ARG2 ARG3....  //comment
			debug_print_msg("#execinc at line $i: $cmd $tokens[0]");		// print it before we parse_expr().
			$args=preg_split('/\s+/',trim($tokens[0]));		// array of args ($cmd is initially the first one).
			$progname = $cmd;
			if (!$cmd){						//ensure it is nonblank.
				fatal_error("#execinc requires a command to run. But none is given. Error ".at_line($i));
			}
			if (preg_match ("/^$RE_RESERVED_WORDS\$/i", $cmd)){ //Check for daftness
				fatal_error("#execinc'd filenames may not be keywords! You tried to #execinc a file called '$cmd', which is probably a mistake.");
			}
			if (substr($cmd,0,1) != "/" ){      //If execinc'd file is not specified with absolute path, assume it is relative to SOURCE file. (not the current directory, as PHP would assume)
				$cmd = dirname($SOURCE_FILE)."/".$cmd;
			}
			if (!file_exists($cmd)){	    //Check if the $cmd file exists.
				fatal_error("#execinc file '$cmd' does not exist. (Hint: files are searched for in the source-file's directory, '".dirname($SOURCE_FILE).", not \$PATH.) Error ".at_line($i));
			}
			
			if (is_executable($cmd)){		//If $cmd is executable, run it directly. Otherwise, execute it with PHP.
				$err_hint_txt = "(executable)";
			}else{
				$cmd = escapeshellarg ($cmd);
				unset ($output); unset ($retval);  //Check it's valid PHP with lint.
				$lastline = exec (PHP_BINDIR."/php -l $cmd", $output, $retval);
				if ($retval != 0){
					fatal_error("#execinc $cmd is not valid PHP syntax: $BLUE$errmsg$NORM. Error ".at_line($i));
				}else{
				}
				$cmd = PHP_BINDIR."/php -f $cmd --";  //Now use php -f.  NB, don't use the existing PHP instance, or we'd contaminate our own scope. Alternative: runkit.sandbox .
				$err_hint_txt = "(via '/usr/bin/php -f')";
			}
			$args_txt='';
			foreach ($args as $arg){		//Try to parse each arg as an expression and evaluate it.
				if (preg_match('/[0-9]/',$arg)){	//Is this (probably) an expression?
					$value = parse_expr($arg, $i, "LENGTH", true);  //Try parsing it. Length is the most generic type. $silent=true means that parse_expr won't die on error.
					if ($value !== false){		//parse_expr() managed to recognise it.
						$arg = $value;
					}
				}
				$arg = escapeshellarg($arg);	//Escape metachars for safety. (also prevents redirection).
				$cmd .= " $arg ";
				$args_txt .= "__$arg"; 
			}
			if ($QUIETQUIET){			//Normally, stderr is passed through, except in -Q mode.
				$cmd .= " 2>/dev/null";
			}
			$cmd = trim($cmd);
			if ( ($ENABLE_EXECINC) and ($ALLOW_EXECINC) ){   //This is a potential security hole. Is it allowed?
				vdebug_print_msg("#execinc at line $i, command is: \"$cmd\"");  //Run it, and check for success.
				unset ($output); unset ($retval);
				echo $BMAGENTA;   //stderr gets passed through. highlight it if present.
				$lastline = exec ($cmd, $output, $retval);
				echo $NORM;
				debug_print_msg("#execinc returned value '$retval'. Output was:${BLUE}\n\t".implode("\n\t",$output)."${NORM}");
				if ($retval != 0){
					fatal_error("#execinc's command failed with status: '$BLUE$retval$NORM'; cmd $err_hint_txt was: \"$BLUE$cmd$NORM\".\nSTDOUT was:\"$BLUE".implode("\n",$output)."$NORM\"\n${BMAGENTA}STDERR was passed-through above.$NORM\nError ".at_line($i));
				}
				$contents .= "//$PARSER_CMT #execinc cmd: $cmd\n";
				for ($j=0; $j< count ($output); $j++){				//Merge the output into the source file.
					$line =  "$output[$j] //$PARSER_IBS source: execinc__$progname$args_txt;".($j+1)."\n"; //NB the "source" is the nth line of the OUTPUT of the execinc'd program, not the nth line of the execinc'd program (which can be a binary!!)
					$contents .= $line;
					if (preg_match ('/^\s*#execinc/',$line)){		//Nested execincs are a bad idea anyway, and can lead to infinite loops by recursion.
						fatal_error("#execinc'd command '$progname' output another '#execinc'. Recursion is not allowed. Error ". at_line($i));
					}
				}
				$contents .= "//$PARSER_CMT: End #execinc, retval $retval\n";
				$sloc_count += $j;
			}else{
				$execinc_forbidden_cmd[]=$cmd;
				$execinc_forbidden_line[]=$i;
			}
		}else{
			$contents.="$lines_array[$i]\n";	//Otherwise, just append the line followed by \n
		}
	}

	if ($execinc_forbidden_cmd){	//#execinc was forbidden. Show what we WOULD have run before quitting. Useful as a check.
		if (!$ENABLE_EXECINC){			//configured to be off.
			print_warning ("#execinc disabled. Would have run the following commands:\n\t$BLUE". implode("$NORM\n\t$BLUE", $execinc_forbidden_cmd)."$NORM" );
			fatal_error ("Encountered '#execinc', but pb_parse is configured with '\$ENABLE_EXECINC' disabled. Error ".at_line($execinc_forbidden_line[0]));
		}elseif (!$ALLOW_EXECINC){		//no -X given.
			print_warning ("#execinc disabled. Would have run the following commands:\n\t$BLUE". implode("$NORM\n\t$BLUE", $execinc_forbidden_cmd)."$NORM" );
			fatal_error ("Encountered '#execinc', but it wasn't enabled with '$CYAN-X$NORM' on command-line. Disabled by default for security. Error ".at_line($execinc_forbidden_line[0]));
		}
	}

	if ($execinc){  			//If execinc output any new #defines (as we would expect), we must parse them.
		$execinc_passes++;
		if ($execinc_passes > $MAX_EXECINC_PASSES) {  //Prevent infinitely nested loops, just in case #execinc outputs another #execinc.
			fatal_error("Too many nested #execincs. Now exceeded $execinc_passes passes. (The limit, MAX_EXECINC_PASSES is $MAX_EXECINC_PASSES).");
		}
	}else{
		$do_process_defines = false;	//Otherwise. exit the while-loop (i.e. don't goto the defines stage again.)
	}
}

//--------------------------------------------------------------------------------------------------------------
//Parse #SETs
debug_print_msg("\n################### ${BLUE}PROCESSING #sets${NORM} ##################################################################################");
foreach (explode ('|',$RE_SETTINGS) as $setkey){	//These are the complete list of allowable settings! They take effect later.
	$SETTINGS[$setkey] = false;
}
$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n
$real_program_started=false;			//So we can warn if a #set is placed after code.
$contents="";
$n =  count($lines_array);
for ($i=0; $i < $n; $i++){				//For each line...
	if (preg_match('/^\#set\s+/',(trim($lines_array[$i])))){	//if the first non-whitespace part of line matches '#set', (followed by space)
		$tokens=preg_split('/\s+/',trim($lines_array[$i]));	//then we want to set SETTING to VALUE. The syntax is:
		$setting=$tokens[1];					//#set SETTING VALUE
		$value=$tokens[2];					//NOTE: VALUE is delimited by whitespace. So value must be a "word".
		if ((!$setting) or (!$value)){
			fatal_error("the #set setting and its value are not properly assigned ".at_line($i));
		}
		if (substr($value,0,2)=='//') { 		//Check that the value does't begin with a comment. (including $PARSER_MAGIC_IBS)
			fatal_error("the #set value may not begin with '//'. Error ".at_line($i));
		}
		if (($tokens[3]) and (substr($tokens[3],0,2)!='//')){ //Check that there is nothing else on this line (except, perhaps a comment).
			fatal_error("this line contains too many tokens for a #set. (3rd token is '$tokens[3]'). (Hint: the #set value may not contain spaces. Error ".at_line($i));
		}
		if (preg_match ("/^$RE_RESERVED_WORDS\$/i", $setting)){  //Check we aren't trying to set a keyword! This is ALWAYS fatal.
			fatal_error("keywords cannot be set. Attempted to #set '$setting'. Error ".at_line($i));
		}

		if (array_key_exists($setting, $SETTINGS)){		//Is this a legal setting to set?
			if ($SETTINGS[$setting] !== false){		//Have we already tried to set it? If so, error - we have a duplicate, which is potentially BAD..
				fatal_error("setting: '$setting' has already been #set, and cannot be altered. Error ".at_line($i));
			}else{
				switch ($setting){	 		//Process each setting as appropriate. Then set the value.
					case 'OUTPUT_BIT_MASK':				//Hex integer
					   $parsed = parse_expr($value,$i,"SETTING");
					   break;
					case 'OUTPUT_BIT_SET':				//Hex integer
					   $parsed = parse_expr($value,$i,"SETTING");
					   break;
					case 'OUTPUT_BIT_INVERT':			//Hex integer
					   $parsed = parse_expr($value,$i,"SETTING");
					   break;
					default:
					   fatal_error ("unknown setting '$setting'");  //can't get here.
				}
				$SETTINGS[$setting] = $parsed;
				debug_print_msg("\tSet setting '$setting' (written as '$value') to be '$parsed'.");
			}
		}else{
			fatal_error("setting: '$setting' does not exist, so cannot be #set. Error ".at_line($i));
		}
		if ($real_program_started){
			print_warning("Setting '$setting' #set AFTER start of code. Bad style, or maybe a bug. Warning ".at_line($i));  //#SETs *should* be at start of program.
		}
	}else{
		$contents.="$lines_array[$i]\n";				//Otherwise, just append the line followed by \n
		if(!(preg_match('/^\s*($|\/\/|#echo|#assert)/',$lines_array[$i]))){//Real program has started. We have seen a line which is neither a comment, nor empty. (nor a #echo/#assert)
			$real_program_started=true;				//[But it could be a macro definition]
		}
	}
}
//The SETs will now be applied much later.

//--------------------------------------------------------------------------------------------------------------
//Process #ifs and #ifnots - enable and disable specific lines.
debug_print_msg("\n################### ${BLUE}PROCESSING #if/ifnots ${NORM} ############################################################################");

function process_ifsifnots($line,$i,$dollar_passthru=false){
	if (preg_match ("/^\s*#(if|ifnot)\s*(\(\)|\(\s*([^ \t]+)\s*\))(\s+.*)$/", $line, $matches)){   //Match  #if(CONST)  or  #ifnot(CONST).  NB the "(\s*[^ \t]+\s*\)"  MUST have something in the middle, and MAY not contain space/tab, else const can match too greedily.
		$kw = $matches[1]; $const = $matches[2]; $rest = $matches[4];
		$const = substr($const, 1,-1);			//trim the outer ().
		if (( $const === "") or ($const === "0")){    	// 0 and "" are treated as defined false.
			$const = false;				// 1 and other integers are considered as true (note that a named constant cannot start with a number)
		}elseif (preg_match("/^\d+$/",$const)){		// expressions will try to be evaluated. 
			$const = true;				// anything containing a '$' sign will be attempted later, after macro-substitution.
		}elseif (strpos($const,'$') !== false){		// otherwise, strings mean that something went wrong, and the constant was never defined to its value.
			if ($dollar_passthru){
				debug_print_msg("#if($const) contains a '\$', saving it for after macro_substitition");
				return ("$line\n");
			}else{
				fatal_error("found '#$kw($const)' where the constant '$const' contains a '\$' sign: this is only allowed within a macro, for subsequent parsing. Error ".at_line($i));
			}
		}else{
			$result = parse_expr ($const,$i,"IF",true);  //Evaluate as expression: returns integer, or false on failure.
			if ($result === false){
				fatal_error("found '#$kw($const)' where the named constant '$const' hasn't been defined. Either use -D$const or -DNo$const on command-line (or perhaps #define it to 1 or 0). Error ".at_line($i));
			}elseif ($result == false){
				$const = false;
			}else{
				$const = true;
			}
		}
		if ( (($kw == "if") and ($const == true)) or (($kw == "ifnot") and ($const == false)) ) {	//-DConst and  #if(Const)  or  -DNoConst and #ifnot(Const)
			$response = "\t$rest";								//   => include the rest of the line.
			debug_print_msg("#if(1) is true, including line $i (without prefix).");
		}elseif ( (($kw == "if") and ($const == false)) or (($kw == "ifnot") and ($const == true)) ) {	//-DNoConst and  #if(Const)  or  -DConst and #ifnot(Const)
			$response = "//if(false) \t$rest";							//   => comment out the rest of the line.
			debug_print_msg("#if(0) is false, commenting out line $i.");
		}else{
			fatal_error("this can't happen; parser bug at line ". __LINE__ .".\n"); //oops.
		}
		$rest = trim($rest);
 		if ((!$rest) or (strpos($rest, "//") === 0)){	//catch misuse of the #if being alone on a line (the next line will be always, not conditionally, compiled)
 			print_warning ("Unused '#if/#ifnot(...)' at line $i. Note that '#if' and '#ifnot' only affect the remainder of the current line, which is blank. Warning ".at_line($i));
 		}
 		return ("$response\n");
	}elseif (preg_match ("/^\s*#(if|ifnot|endif)/", $line, $matches)){  //catch misformatted #ifs and illegal #endifs.
		if ($matches[1] == "endif"){
			fatal_error("found unexpected '#endif'. Note that the '#endif' is statement is never used, as #if()/#ifnot() only apply to their own line, not blocks. Error ".at_line($i));
		}else{
			fatal_error("found unexpected '#$matches[1]'. Note the correct formatting for '#$matches[1](CONST)'. Error ".at_line($i));
		}
	}else{
		return ("$line\n");
	}
}	

//Now do it.
$lines_array=explode("\n",$contents);
$contents="";
$n = count($lines_array);	//iterate linewise, rather than using preg_replace(/m), so that at_line() can work.
for ($i=0; $i < $n; $i++){	
	$contents .= process_ifsifnots ($lines_array[$i],$i,true);  //this time, allow constants with embedded '$' to trickle down for later...
}

//--------------------------------------------------------------------------------------------------------------
//PARSE MACROS. Macros are shortcuts - they are inlined/evaluated at compile-time. (They look a bit like functions!) Case sensitive.

/* Macros are specified the same way as functions in PHP, i.e.
	#macro macroname ($arg1,$arg2,...){
		//Contents of macro. Here, we must use the args as $a, $b etc.
	}
The #macro keyword is required. macroname may contain a-z,0-9,_ and must start with a letter. Parameters must begin with a $, and be separated by commas.
Parameter names may contain a-z,0-9,_ and may not start with a digit. As many (or zero) arguments as desired are permitted. Whitespace (tabs,spaces) is allowed. Comments are permitted.
*/

//Note: search uses multiline mode (/m).
//Note that '//' might comment out a potential trailing '}', hence the macro body is not just '([^\}])*'.
//Note: PREG's whitespace  '\s' includes newlines, but we often only want to match tab and space. Use $RE_TS == "[\t ]" for simplicity.
//Note: Because we are within double-quotes, rather than single-quotes (so as to use $RE_WORD), use \\\$ for a literal '$' sign, and \\n for a newline. Have to escape both PHP and RE.
//Note: $RE_COMMENT_ENTIRE matches (and discards) an entire comment, from the '//' to the newline).
	#Original RE, using  '[^\/\}\\n]*' fails if there is a division in the macro. Also, it crashes PCRE on PHP 5.3.5, though 5.3.6 is ok.
	#$search="/^  TS*  #macro  TS+  ($RE_WORD)  TS*  \(  TS*  (  \\\$  $RE_WORD  (  TS*  ,  TS*  \\\$  $RE_WORD  )*  )?  TS*  \)  \s* ($RE_COMMENT_ENTIRE)? \s*  \{  \s* ".	//#macro macroname  ($arg1,$arg2...) {
	#	"(  (  [^ \/ \} \\n ]*   ($RE_COMMENT_ENTIRE)?  \s*  )*  )".													//    body of macro
	#	"TS*  \}  /m";
$search="/^  TS*  #macro  TS+  ($RE_WORD)  TS*  \(  TS*  (  \\\$  $RE_WORD  (  TS*  ,  TS*  \\\$  $RE_WORD  )*  )?  TS*  \)  \s* ($RE_COMMENT_ENTIRE)? \s*  \{  \s* ".	//#macro macroname  ($arg1,$arg2...) {
	"(  (  (?U:[^\}\\\n]*)   ($RE_COMMENT_ENTIRE)?  \s*  )*  )".													//    body of macro
	"TS*  \}  /m";
$search=str_replace(' ','',$search); 		//Remove whitespace. Keep the R.E. legible; make PHP do the work!
$search=str_replace('TS', $RE_TS, $search); 	//Use TAB,SPACE, substituted for TS.
$macro_args_array=array();		//KEY=macro_name, VALUE=arguments
$macro_body_array=array();		//KEY=macro_name, VALUE=the macro body.
$macro_usecount_array=array();		//KEY=macro_name, VALUE=how many times this macro has been inlined.

function parse_macro($matches){		//Parse macro. Get macroname, args, body.
	global $macro_args_array;
	global $macro_body_array;
	global $macro_usecount_array;
	global $MAGENTA, $DBLUE, $NORM;
	global $RE_RESERVED_WORDS;

	$macro_name=$matches[1];	//The macro name
	$macro_args=$matches[2];	//"$a, $b, $c"  etc.  Already trimmed, comma-delimited internally, with possible spaces. Empty if no args.
	$macro_body=trim($matches[6]);	//The body of the macro. Trimmed on both sides.

	$macro_body_lines=explode("\n",$macro_body);		//This entire bit is simply to identify which line in the source the macro is declared at.
	$macro_body_dbg ='';					//Separate body into individual lines. The first line is just a comment, for the parser's internal magic line-tracking.
	for ($i=0; $i < count ($macro_body_lines); $i++){	//Since we have to do it, may as well use it to remove the IBS "noise" from the debug output.
		$info=identify_line($macro_body_lines[$i]);	
		if ($i==0){
			$sourcefile=$info['sourcefile_colour'];
			$sourcelinenum=$info['sourcelinenum_colour'];
		}
		$macro_body_dbg.=$info['line']."\n";
	}
	$macro_body_dbg=trim(preg_replace("#\/\/\n#","\n",$macro_body_dbg));  //tidy

	if (preg_match ("/^$RE_RESERVED_WORDS\$/i", $macro_name)){ //Macro names may not be keywords. It probably indicates a mistake.  //We could recover, but it's likely the user made an error.
		fatal_error("Macro names may not be keywords! You tried to use '$macro_name' as a macro. This is probably a mistake. Error in $sourcefile at line $sourcelinenum.");
	}

	if (array_key_exists($macro_name,$macro_args_array)){
		fatal_error("macro '$macro_name has already been defined, and cannot be re-defined. Error in $sourcefile at line $sourcelinenum.");
	}

	$macro_args_array[$macro_name]=$macro_args;
	$macro_body_array[$macro_name]=$macro_body;
	$macro_usecount_array[$macro_name]=0;	//initialise to zero.

	debug_print_msg("Defined macro '${MAGENTA}$macro_name${NORM}' with arguments '({$DBLUE}$macro_args${NORM})', at $sourcefile, line $sourcelinenum. Macro body:\n{\n\t".$macro_body_dbg."\n}\n");  //For debugging
}

debug_print_msg("\n################### ${BLUE}PROCESSING MACRO DEFINITIONS${NORM} ######################################################################");
$contents=preg_replace_callback($search,"parse_macro",$contents);  //Search for macros. Define them, then remove them from the stream.
if ($contents === NULL){
	fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
}
if (preg_match ('/^\s*macro\s+.*$/im',$contents,$matches)){  //If the keyword 'macro' still appears anywhere, then something has gone wrong. (Note, we don't trigger on "macro:", which would be (stupidly) a label!)
	fatal_error("Misformatted macro - probably missing one of '(\$){}'. Error begins ".at_line($matches[0]));
}

$lines_array=explode("\n",$contents);		//Lines array again, for debug check.
$n =  count($lines_array);
for ($i=0; $i < $n; $i++){
	if (preg_match ('/^[^\/]*(\}|#macro)/mi',$lines_array[$i], $matches)){  //un-commented '}' or '#macro' (case-insensitive).  //Note: checking for '/' rather than '//' is imperfect. But it's ok as a check.
		fatal_error("Un-substituted '$matches[0]' This probably indicates a mis-formatted macro which wasn't picked up by the macro-regex. Macros are defined: ".
			    "'${BLUE}#macro ( \$arg1, \$arg2, ... ) { MACRO_BODY }${NORM}'. i.e. keyword '#macro'; zero or more parameters: word-names beginning with '\$' and letter; optional whitespace/comments. Error ".at_line($i));
	}
}

//--------------------------------------------------------------------------------------------------------------
//EVALUATE MACROS. Essentially, we are expanding and in-lining them. Case sensitive.
/*
Macros are inlined thus:
	[label:]  macroname (123, 456)   [//comment]
The macro must be on a line of its own, although a leading label, and trailing comment are allowed. Whitespace is permitted. There is NO terminating ';'.
Macro arguments may contain any expression.  ($RE_EXPRESSION is still slightly too lax,)
NB the expressions are parenthesised here. This ensures that a definintion of '3*n+1' combined with an argument of '5+1'  becomes  18, not 16.
*/

//Generate an array of macronames for the regex. (Note  \\n and \$ since we are double-quoted).
$search="/^  TS*  (  ( $RE_WORD )  : )?  TS*  (  (MACRONAME)  TS*  \(  TS*  ( $RE_EXPRESSION  (  TS*  ,  TS*  $RE_EXPRESSION  )*  )?  TS*  \)  )  TS*  (  \/\/  ( [^ \\n ]* )  )?  \$  /m";
$search=str_replace(' ','',$search); 		//Remove whitespace. Keep the R.E. legible; make PHP do the work!
$search=str_replace('TS', $RE_TS, $search); 	//Use TAB,SPACE, substituted for TS. [Not \s, since we don't want to match newlines here.]

$search_array=array();
foreach ($macro_args_array as $key => $value){   //customise the array, for each macroname which we know.
	$search_array[]=str_replace('MACRONAME',"$key",$search);
}
$search_array=array_reverse($search_array); //Reverse the search array. This means that macros defined FIRST are evaluated LATER, so that they may be nested within a later macro. (No recursion though!)

function evaluate_macro($matches){
	global $macro_args_array;
	global $macro_body_array;
	global $macro_usecount_array;
	global $macroeval_thispass_count;
	global $PARSER_IBS, $PARSER_CMT;
	global $RE_RESERVED_WORDS;
	global $RE_WORDCHAR, $RE_WORD;
	global $RE_OPERATORS, $VERBOSE_DEBUG;
	global $MAGENTA, $DBLUE, $NORM;

	$caller_label=$matches[2];			//Label, preceeding the macro call, if there is one. (without the trailing :) Must restore this!
	$macro_call=$matches[3];			//The whole macro call, i.e. 'macroname($args)' (but without the comments)
	$caller_name=$matches[4];			//The name of the macro (as called)
	$caller_args=$matches[5];			//The arguments to the macro (as called) Separated by commas (and maybe spaces). Empty if no args.
	$caller_comment=$matches[11];			//Comment, if present. (With the leading //)
	$macro_call_entireline=$matches[0];		//The entire line containing the macro call and comments, and IBS.

	$body=$macro_body_array[$caller_name];		//Retrieve Macro body.(before replacement)
	$usecount=$macro_usecount_array[$caller_name]; 	//Number of times this macro has been called so far.

	$body_firstline=substr($body,0,strpos($body,"\n")); //Get first line of body, so as to identify the line of source code where it starts. Note: this line *only* contains PARSER_IBS information, nothing else.
	$info=identify_line($body_firstline);
	$macro_body_defined_at="$info[sourcefile_colour], line $info[sourcelinenum_colour]"; //macro body defined at this place in source code.
	$info=identify_line($macro_call_entireline);
	$macro_called_from="$info[sourcefile_colour], line $info[sourcelinenum_colour]"; //macro called from this place in source code.

	debug_print_msg("Inlining macro '${MAGENTA}$caller_name${NORM}', called as '${DBLUE}$macro_call${NORM}'...");
	
	if (trim($caller_args) !==''){			//Split the args by commas.  (NB could have a single arg of "0".)
		$caller_args=explode(',',str_replace(' ','',$caller_args));  //We can just str_replace, rather than trimming separately, since we know that ARGS may not contain spaces.
		$num_caller_args=count($caller_args);
	}else{
		$num_caller_args=0;
	}

	$defined_args=$macro_args_array[$caller_name]; //The args in the macro definition.
	if ($defined_args){
		if (preg_match ("/$RE_RESERVED_WORDS/", $defined_args)){ //Macro argument names should not be keywords. It would probably indicate a mistake. in the macro definitition.
			fatal_error("macro argument names may not be keywords! At least one of the argument names '$defined_args' is not allowed. Macro '$caller_name()' was defined at $macro_body_defined_at.  Error ".at_line($macro_call_entireline));
		}
		$defined_args=explode(',',str_replace(' ','',$defined_args));
		$num_defined_args=count($defined_args);
	}else{
		$num_defined_args=0;
	}

	if ($num_caller_args != $num_defined_args){  //Verify that the macro has been called with the correct number of arguments.
		fatal_error("macro '$caller_name()' expects to have '$num_defined_args' arguments, but it has been supplied with '$num_caller_args' arguments. Macro '$caller_name()' was defined at $macro_body_defined_at. Error ".at_line($macro_call_entireline));
	}

	if ($num_caller_args == 0){
		vdebug_print_msg("\t$caller_name(): has no parameters which need to be substituted.");
	}else{
		$parameters_search_array=array();	//Substitute parameters by their values. ** Replacement requires that parameters are delimited by a non-wordname character.**
		for ($i=0;$i<$num_caller_args;$i++){	//This prevents a conflict between parameters like ($a,$ap), substituting into ($apple).
			$oldbody=$body;			//The lookbehind assertion is required: although we might expect " $a$b ", to be safe, think what happens if $b gets substituted first?
			vdebug_print_msg("\t$caller_name(): substituting parameter '$defined_args[$i]' with value: '$caller_args[$i]'.");
			$search = "/(?<!$RE_WORDCHAR)".preg_quote($defined_args[$i],'/')."(?!$RE_WORDCHAR)/";   //preg_quote required to deal with $' signs. Use lookbehind/ahead assertions in search to ensure that parameters are delimited by a non-word character. (i.e. not [a-z0-9_])
			if (preg_match("/$RE_OPERATORS/",$caller_args[$i])){  //If the caller arg contains operators...
				$replace = "($caller_args[$i])"; //Add parentheses around caller args, so that '3*$n' called with n='5+1'  becomes 18, not 16.
			}else{
				$replace = "$caller_args[$i]";	//But if, eg just an opcode, then don't.
			}
			$body = preg_replace($search, $replace, $body);	//Case-sensitive.   Replacements also take effect within comments.
			if ($body === NULL){
				fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
			}elseif ($body==$oldbody){ 	//Warn, if it wasn't used.
				print_warning("argument '$caller_args[$i]' to macro '$caller_name()' was never used, since macro body does not contain a parameter '$defined_args[$i]'. ".
					      "macro definition at $macro_body_defined_at; inlined at $macro_called_from.");
			}
		}
	}

	if (preg_match("/(\\\$$RE_WORD)/",$body, $unsub)){  //Fatal error if there are any parameters not yet substituted. Occurs if a macro definition uses more '$' than are in its args list!
		fatal_error ("macro '$caller_name()' still contains an un-substituted parameter, '$unsub[0]'. macro definition was at $macro_body_defined_at.");
	}

	//Replace the internal labels. This is important, since when the macro is inlined, "private" labels must be unique. They get replaced by "macroname_usecount_labelname".
	//Exceptions: (1) "public" destination labels which jump outside the macro are not replaced.  (2) If the first line has a label, and the macro call has a label, the label from the call is used.
	$labels_array=array();  //An array which will contain all the labels in this macro.
	$labels_search_array=array();
	$labels_replace_array=array();
	$lines_array=explode("\n",$body);  //(Not the *main* $lines_array)
	$had_firstline=false;
	$firstline_haslabel=false;  //Does the first (non-empty, non-comment) line have a label?
	$first_noncomment=false;
	foreach ($lines_array as $line){
		if (!preg_match('/(^\s*$)|(^\s*\/\/)/',$line)){  //Line is neither empty, nor just a comment
			if (preg_match ("/^\s*($RE_WORD)\:/",$line,$matches)){
				$labels_array[]=$matches[1];  //the label, without the terminating semicolon.
				$labels_search_array[]='/'.preg_quote($matches[1],'/').'/';  //still case-sensitive.
				$labels_replace_array[]=preg_quote("${caller_name}_${usecount}_".$matches[1],'/');  //Replace it by "macroname_count_label".
				if (!$had_firstline){
					$firstline_haslabel=true;  //First line has a label. (The actual label is $labels_array[0].)
				}
			}elseif (!$had_firstline){
				$first_noncomment = $line;   //the contents of the first line that isn't a comment.
			}
			$had_firstline=true;
		}
	}

	$body=preg_replace($labels_search_array, $labels_replace_array, $body); //Do the replacement of internal labels by unique versions.
	if ($body === NULL){
		fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
	}

	//If the macro body's first line has an [internal] label  (i.e. $firstline_haslabel==true)  AND the caller is also labelled (i.e. $caller_label is nonempty).
	//then we need to replace the first label with the caller label: (i.e. preg_replace $labels_replace_array[0] by $caller_label). This means that other parts of the code can goto the macro's start.
	if (($firstline_haslabel) and ($caller_label)){
		$body=preg_replace('/'.$labels_replace_array[0].'/', preg_quote($caller_label,'/'), $body);
	}else if ($caller_label){
		$body = preg_replace ("/".preg_quote($first_noncomment,"/")."/", "$caller_label:\t$first_noncomment", $body, 1); //If the caller has a label, but the first line of the macro doesn't, prepend the $caller_label to the first proper line of the macro.
	}else{
		$body="\t".$body;  //Indent it correctly.
	}
	
	//Do we want error messages to reference the caller, or the macro? Comment out the next line for the latter.
	$body = preg_replace ("/\/\/$PARSER_IBS(\s?)((.*);\d+)/","//$PARSER_CMT #macro ($2) $caller_comment", $body);  //Replace IBS by caller-comment (including IBS). So errors show the line num of the caller, not the macro.  

	$body = trim ($body); //remove trailing blanks.
	$body = str_replace ("$PARSER_IBS last line of macro",'',$body); //Important: the string "$PARSER_IBS last line of macro" is detected by follows_macro(), so don't modify it
	$body.= "//$PARSER_IBS last line of macro";	  		 //Remove any existing occurences (if we're nested within another macro), then add our own, just once at the end.
	$body.="\n"; 	//Restore one trailing newline to $body.

	$body_lines=explode("\n",$body);	//This entire bit is simply tidy up the debug output, removing IBS noise.
	$body_dbg = '';
 	for ($i=0; $i < count ($body_lines); $i++){
		$info=identify_line($body_lines[$i]);
 		$body_dbg.=$info['trunc']."\n";
 	}
	if (!$VERBOSE_DEBUG){			//Tidy - remove comments entirely here, they don't help readability.
		$body_dbg = preg_replace ("/\/\/.*\n/","\n", $body_dbg);
	}
	debug_print_msg ("\t".str_replace("\n","\n\t",trim($body_dbg))."\n"); 

	//Prepend and append a comment..
	$body="//$PARSER_CMT Inlining and evaluating macro '$caller_name()', called as '$macro_call'.  $caller_comment\n".$body."\n//$PARSER_CMT end of macro '$caller_name()'.\n";

	$macro_usecount_array[$caller_name]++;  //Increment the usage counter, for next time.

	$macroeval_thispass_count++;	//How many replacements on this pass?
	return $body; //Return our shiny new, inlined and substituted macro!
}

debug_print_msg("################### ${BLUE}BEGIN MACRO EVALUATION/SUBSTITUTION${NORM} ###############################################################");

if ($MAX_MACRO_INLINE_PASSES<1){  //Sanity check. Must be >= 1.  (Probably should be 1,2,3, or not much larger)
	fatal_error("MAX_MACRO_INLINE_PASSES is set to '0' in the pb_parse configuration section, so macros will never be evaluated.");
}
for ($i=0;$i<$MAX_MACRO_INLINE_PASSES;$i++){		//Search for macros (which have already been defined). Evaluate them (i.e. Inline them), then remove them from the stream.
	$macroeval_thispass_count=0;			//Do this at least once. The first time allows nesting, but only macros defined earlier may be nested within later macros.
	debug_print_msg("Macro inlining: pass $i:\n"); //The second time allows nesting both ways. Third+ times allow macros to call any macros up to $MAX_MACRO_INLINE_PASSES
	$contents=preg_replace_callback($search_array,"evaluate_macro",$contents);  //calls deep. But no infinite recursion! DOCREFERENCE: MACRO-NESTING.
	if ($contents === NULL){
		fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
	}elseif ($macroeval_thispass_count==0){
		debug_print_msg("\t[Pass $i: there are no macros to inline.]\n");
	}
}

if (preg_match (str_replace('MACRONAME',$RE_WORD,$search),$contents,$matches)){  //If anything still looks like a macro call, it's an error.
	fatal_error("Error calling macro '$matches[4]()'. Either this macro has not been defined, or it is recursive, called too deeply, or mis-nested. ".
		    "Max nesting depth is ".($MAX_MACRO_INLINE_PASSES-1)."; see 'MACRO-NESTING' is the documentation. Error ".at_line($matches[0]));
}

foreach ($macro_usecount_array as $macroname => $usecount){  //Notice if a macro was defined, but never called.
	if ($usecount==0){
		vdebug_print_msg("unused macro (defined, but never called): '$macroname()'.");  //orginally was "print_notice", but if we have an unused function in a "library", this is too noisy.
	}
}

debug_print_msg("\n################### ${BLUE}PROCESSING #if/ifnots with \$ signs in macros ${NORM} ############################################################################");

$lines_array=explode("\n",$contents);
$contents="";
$n = count($lines_array);	//iterate linewise, rather than using preg_replace(/m), so that at_line() can work.
for ($i=0; $i < $n; $i++){	
	$contents .= process_ifsifnots ($lines_array[$i],$i);  //'$' signs not allowed this time.
}

//--------------------------------------------------------------------------------------------------------------
//DEAL WITH #ECHOs
//This allows a .pbsrc file to make the parser print something. Useful when expressions have been created from nested #defines.
//Numeric expressions are evaluated if posssible (though if invalid, it's OK here). #echo within #macro is ok.
$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n
$contents="";
debug_print_msg("\n################### ${BLUE}PRINTING #echos${NORM} ###################################################################################");
$n =  count($lines_array);
for ($i=0; $i < $n; $i++){			//For each line...
	if (preg_match('/^\#echo\s+/',(trim($lines_array[$i])))){	//if the first non-whitespace part of line matches '#echo', (followed by space)
		$tokens=preg_split('/\s+/',trim($lines_array[$i]),2);	//then we have something to echo.
		$tokens=preg_split('/\/\//',$tokens[1]);		//   #echo TEXT //comment
		$echo=trim($tokens[0]);					//NOTE: TEXT is the entire rest of the line, upto (but excluding) the comments.

		debug_print_msg ("\t#echo $echo");			//Raw echo string.
		$bits = preg_split("/($RE_TS)/",  $echo, NULL, PREG_SPLIT_DELIM_CAPTURE);  //Split up by spaces.
		$echo ='';
		foreach ($bits as $bit){
			if (preg_match('/[0-9]/',$bit)){	//Is this (probably) an expression?	
				$value = parse_expr(trim($bit,","), $i, "LENGTH", true);  //Try parsing it. Length is the most generic type. $silent=true means that parse_expr won't die on error.
				if ($value !== false){						//parse_expr() managed to recognise it.
					if (!preg_match("/^,?($RE_DEC|$RE_HEX),?\$/", $bit)){	// ..and the expression is different when evaluated.
						$bit = "$CYAN$bit$NORM [=$DBLUE$value$NORM]";   //so append [=xxx] containing the value of the expression.
					}else{
						$bit = "$CYAN$bit$NORM";  //numeric constant, so colour it.
					}
				}
			}
			$echo .= $bit;
		}
		if (!$QUIETQUIET){
			print_msg ("${BMAGENTA}#echo{$NORM} $echo");
		}
	}else{
		$contents.="$lines_array[$i]\n";		//Otherwise, just append the line followed by \n
	}
}

//--------------------------------------------------------------------------------------------------------------
//Parse #ASSERTs
$lines_array=explode("\n",$contents);		//It's OK to have an #assert, even within a macro.
$contents="";

debug_print_msg("\n################### ${BLUE}PROCESSING #asserts${NORM} ###############################################################################");
$n =  count($lines_array);
for ($i=0; $i < $n; $i++){				//For each line...
	if (preg_match('/^\#assert\s+/',(trim($lines_array[$i])))){	//if the first non-whitespace part of line matches '#assert', (followed by space)
		$tokens=preg_split('/\s+/',trim($lines_array[$i]));	//then we want to assert the truth of ( (EXPR1) OPER (EXPR2) )   
		$expr1=$tokens[1];					//The expressions cannot contain whitespace.
		$oper=$tokens[2];					//Operator is one of: > >=  <  <=  ==  !=
		$expr2=$tokens[3];
		if ((!$expr1) or (!$oper) or (!$expr2)){
			fatal_error("the #assert expressions and operator are not properly defined ".at_line($i));
		}
		if((substr($expr1,0,2)=='//') or (substr($expr2,0,2)=='//')) { 	//Check that the expressions don't begin with a comment. (including $PARSER_MAGIC_IBS)
			fatal_error("the #assert expressions may not begin with '//'. Error ".at_line($i));
		}
		if (($tokens[4]) and (substr($tokens[4],0,2)!='//')){ //Check that there is nothing else on this line (except, perhaps a comment).
			fatal_error("this line contains too many tokens for a #assert. (4th token is '$tokens[4]'). (Hint: the 2 expressions in a #assert may not contain spaces. Error ".at_line($i));
		}
		$val1 = parse_expr ($expr1, $i, "LENGTH");
		$val2 = parse_expr ($expr2, $i, "LENGTH");

		if ($oper == '=='){
			$truth = ($val1 == $val2);
		}elseif($oper == '!='){
			$truth = ($val1 != $val2);
		}elseif($oper == '<'){
			$truth = ($val1 < $val2);
		}elseif($oper == '<='){
			$truth = ($val1 <= $val2);
		}elseif ($oper == '>'){
			$truth = ($val1 > $val2);
		}elseif($oper == '>='){
			$truth = ($val1 >= $val2);
		}else{
			fatal_error("Illegal operator ('$oper') in an #assert. Only >,>=,<,<=,==,!= are allowed. Correct format eg '#assert (2+3) > 4'. Error ".at_line($i));
		}
		
		if ($truth){
			debug_print_msg ("\t#Assertion succeeded: $expr1 $oper $expr2");
		}else{	//assertion failed.
			fatal_error("Assertion failed. '#assert $expr1 $oper $expr2'. Error ".at_line($i));
		}
	}else{
		$contents.="$lines_array[$i]\n";				//Otherwise, just append the line followed by \n
	}
}

//--------------------------------------------------------------------------------------------------------------
//Double-check keywords have now been removed. Catch any strays. Also check style that the first column begins with either space or a label.
$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n

$n =  count($lines_array);
for ($i=0; $i< $n ; $i++){
	$line = $lines_array[$i];
	$p2 = strpos($line,'//');
	if ($p2 === false){
		$p2 = strlen($line);
	}
	$code = substr($line,0,$p2);
	if (trim($code)){
		//Anything containing a keyword. (also matches things like 'X#defineY', which is probably a good thing here).
		if (preg_match("/(#($RE_KEYWORDS_PP))/i", $code, $matches)){  //Either wrong case, wrong place (not first on line), or parser error. (don't search in comments though!)
			fatal_error("Found '${RED}$matches[1]${NORM}' remaining in contents, after processing all #keywords. (Hint: keywords must be lower-case and come first on the line). Error ".at_line($i));
		}
		//Check for start of line first column being a label or whitespace. (originally done in the tokenisation section, but now must preceede opcode macro fixes).
		if ( (substr(str_replace("\t","        ",$code),0,2)!='  ') and (!preg_match("/^\s?$RE_WORD:/",$code)) ){    //expect 1st column to be tab, >=2 spaces, or a label. 
			print_notice("bad style: first column is neither a label, nor empty (tab, or >= 2 spaces). This could cause confusion. Notice ".at_line($i));  //could possibly indicate an error.
		}
	}
}

//--------------------------------------------------------------------------------------------------------------
//DEAL with opcode reordering. Note: opcodes should be case-insensitive. Don't try to match outputs etc in detail, just use the fact they are tokenised by whitespace.
debug_print_msg("\n################### ${BLUE}BEGIN VLIW PARAMETER RE-ORDERING${NORM} ##################################################################");

/* The default opcode order is "OUT OPC ARG LEN". This is very sensible with LONGDELAY,  "set the outputs, longdelay (multiple * length)", but is
  not sensible with Loop "Loop n times{ set outputs, delay" or Endloop "set outputs, delay }endloop".  One alternative is the opcode macros (eg "__LOOP"), but it
  is also sensible to just accept the VLIW instructions in any sane order. So, accept the 4 tokens in ANY order, provided that OPC,ARG are consecutive. and that OUT preceeds LEN.
  A natural ordering might be

	DEFAULT:	OUT	OPC	ARG	LEN
	CONT:		0xff	10s	cont 	- 			//Or  "0xff cont - 10s", like longdelay.
	LONGDELAY:	0xff	longdel	auto	10s
	GOTO:		0xff	10s	goto	dest
	CALL:		0xff	10s	call	routine
	RET:		0xff	10s	return	-
	LOOP:		loop	n	0xff	10s
	ENDLOOP:	0xff	10s	endloop	lbl
	WAIT:		0xff	wait	-	10s
	STOP:		0xff	stop	-	-

*/

//Search pattern:  "space? (token:)?  space? (token)  space  (token)  space  (token)  space  (token)  space?  comment?"  Where token="any non-space" and "space" = "space or tab, but not newline".
$search="/^TS* ((TOK:)TS+)?  (TOK)  TS+  (TOK) TS+  (TOK) TS+ (TOK)  (TS+)?  $RE_COMMENT?\$/"; //Note: we DON'T care (here) about the form of the tokens, only that they are delimited.
$search=str_replace(' ','',$search); 			//Remove whitespace. Keep the R.E. legible; make PHP do the work!
$search=str_replace('TS', $RE_TS, $search); 		//Use TAB,SPACE, substituted for TS.
$search=str_replace('TOK', $RE_TOKEN, $search); 	//Use [^\t ]+ , substituted for TOK.

function vliw_reorder($matches){	//Callback function for the replacement. [Use a callback for simplicity.]
	global $RE_OPCODES;
	global $i;			//line number (global, since we can't pass vliw_reorder() extra args.)

	if (count($matches) < 8){
		return ($matches[0]);	//something odd happened; return line unaltered for later error detection.
	}
	
	//The instruction we are reordering.
	$lbl=$matches[2];	//label(with :) if present.
	$vliw_a=$matches[3];	//4 parts of the vliw
	$vliw_b=$matches[4];
	$vliw_c=$matches[5];
	$vliw_d=$matches[6];
	$cmt=$matches[8];	//Comment, complete with leading '//', or empty.
	//debug:  for ($j=0;$j<count($matches);$j++) { echo "VLIW reorder $j:  XX$matches[$j]YY\n"; }  //$i is global!

	//Carefully match exact opcode, so that eg "__return" doesn't get captured.  This SHOULD be case-insensitive for opcode names.
	if (preg_match("/^($RE_OPCODES)\$/i",$vliw_b)){		//Opcode 2nd, i.e. default position.
		$opc = $vliw_b; $arg = $vliw_c; $out = $vliw_a; $len = $vliw_d;
	}else if (preg_match("/^($RE_OPCODES)\$/i",$vliw_c)){	//Opcode 3rd. e.g. "goto"
		$opc = $vliw_c; $arg = $vliw_d; $out = $vliw_a; $len = $vliw_b;
	}else if (preg_match("/^($RE_OPCODES)\$/i",$vliw_a)){	//Opcode 1st, e.g. "loop"
		$opc = $vliw_a; $arg = $vliw_b; $out = $vliw_c; $len = $vliw_d;
	}else{							//Note: opcode can never be 4th.
		return ($matches[0]);		//something odd happened; return line unaltered for later error detection.
	}
	$replacement="$lbl\t\t$out\t\t\t$opc\t\t$arg\t\t$len\t\t$cmt";
	return $replacement;
}

$lines_array=explode("\n",$contents);	//Split contents into array of 1-line chunks delimited by \n. We have to do it this way and not all in one go, because we MUST
$contents="";				//NOT make a replacement inside a // comment. However, preg_replace cannot use a negative lookbehind of unknown length.

$n =  count($lines_array);
for ($i=0 ; $i < $n ;$i++){	//For each line...
	if (substr(trim($lines_array[$i]),0,2) != "//"){	// ...that isn't just a comment
		$line = preg_replace_callback($search,"vliw_reorder",$lines_array[$i]);
		if ($line === NULL){
			fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
		}
	}else{
		$line = $lines_array[$i];
	}
	$contents .= "$line\n";
}

//--------------------------------------------------------------------------------------------------------------
//DEAL with "underloaded" opcode macros. Don't try to match outputs/lengths etc in detail, just use the fact they are tokenised by whitespace.
//Not: opcode reordering is perhaps a better way to achieve the same effect. (See above)
debug_print_msg("\n################### ${BLUE}BEGIN '__OPCODE' MACRO SUBSTITUTION${NORM} ###############################################################");
// Note that this will have the side-effect of breaking some literal numeric (rather than labelled) jump destinations, because it changes the effective line-numbers; warning is generated if needed.
// [Unlike zeroloop, this merges, rather than stealing some cycles. So there's no need for the merged instruction to have sufficient extra length.]
//TODO: we could also allow merging into a longdelay; though it would mean splitting the longdelay into 2 lines first.
//TODO: debug and mark opcodes are equivalent to cont, so could be allowed here (just change the regexp), but would then vanish into the other opcode... that would defeat the point of using them!

$opcode_macro_used = false;  //for warning later.
$lines_array=explode("\n",$contents);		//Remove any blank lines, or lines that consist entirely of comments.
$contents='';					//This matters now, because the underloaded opcode macro replacement works on pairs of lines, and a blank line
foreach ($lines_array as $line){		//would mess this up in some cases. (Also, we get PARSER_IBS comments on blank lines).
	$trim = trim ($line);
	if ( ($trim) and (substr($trim,0,2)!= '//') ){
		$contents.="$line\n";
	}
}

// [1] Merge into the following cont: OPCMACRO_POST: __loop
/* "__LOOP n" can 'merge into' a succeeding CONT. (it must be a "cont, -")		
	The pair of instructions looks like:

		[label1]	__LOOP		n						[//comment]
	        ^in
		[label2]	OUT		cont		-		LEN		[//comment]
														^out
	We want to replace it by:

		[label1]	OUT		LOOP		n		LEN		[//comment] //PARSER: converted opcmacro LOOP.
		^in															^out

	where  ^in and ^out mark the respective points in the search/replace. The following opcode MUST be "cont -", and may NOT have a label. The __GOTO MUST have a label
*/

//Search pattern: token? space?  __LOOP  space? token? space? comment? newline token? space? token space CONT space NA space token space? comment?
$search="/(TOK:)?  (TS+)?  __($RE_OPCMACRO_POST)  TS+  (TOK)? (TS+)?  $RE_COMMENT?\$\n+TS+ (TOK:)? (TS+)?  (TOK)  TS+  (CONT|CONTINUE)  TS+  ($NA)  TS+  (TOK)  (TS+)?  $RE_COMMENT?\$/imU"; //Modifier 'i' for case-insensitive, 'm' for multiline, U for ungreedy. Note: we DON'T care (here) about the form of the tokens, only that they are delimited.
$search=str_replace(' ','',$search); 			//Remove whitespace. Keep the R.E. legible; make PHP do the work!
$search=str_replace('TS', $RE_TS, $search); 		//Use TAB,SPACE, substituted for TS.
$search=str_replace('TOK', $RE_TOKEN, $search); 	//Use [^\t ]+ , substituted for TOK.


function opcode_macro_post($matches){	//Callback function for the replacement. [Use a callback for simplicity.]
	global $HEADER;
	global $NA, $NORM, $MAGENTA;
	global $SAME, $SHORT;
	global $PARSER_IBS, $PARSER_CMT;
	global $i;		//Can't pass other args to a callback function, so must resort to faking global variables ($i).  !!OUCH
	global $opcode_macro_used; $opcode_macro_used = true;
	
	//The "underloaded" opcode/macro
	$ulbl=$matches[1];	//label
	$uopc=$matches[3];	//loop
	$uarg=$matches[4];	//if present.
	$ucmt=$matches[6];
	$underload = "__".strtoupper($uopc); //__LOOP
	//The instruction we are merging with.
	$lbl=$matches[7];	//label if present
	$out=$matches[9];
	$opc=$matches[10];	//must be "cont": check it.
	$arg=$matches[11];	//must be "-":  check it.
	$len=$matches[12];
	$cmt=$matches[14];	//Comment, complete with leading '//', or empty.

	//debug: for ($j=0;$j<count($matches);$j++) { echo "Opc-macro match $j:  $matches[$j]\n"; }  //$i is global!

	if ( (($opc != "cont") and ($opc != "continue")) or ($arg != $NA)){  //Double check we found cont, - as expected
		fatal_error("opcode_macro_post(): wrong opcode/arg. Expected 'CONT','$NA'; got '$opc', $arg'. Error ".at_line($i+1));
	}
	if ($uarg === ''){	//Where $uarg isn't given, detect fatal error. use NA. Will be picked up later as fatal.
		$uarg = $NA;
		print_warning("__loop opcode-macro had no argument. Warning ".at_line($i));
	}
	if ($lbl){		//If the CONT line has a label, this will be removed. The label should be at the __loop instead.
		print_notice("__loop opcode-macro also has label '$lbl' on the CONT line. This will be discarded (only the __loop line should be labelled). Notice ". at_line($i));
	}
	if (!$ulbl){		//If the __loop line doesn't have a label, there will be trouble to come. Will cause fatal error later.
		print_warning("__loop opcode-macro has no label. Notice ". at_line($i));
	}
	if (($ucmt) and (substr($ucmt,0,strlen("//$PARSER_IBS"))!== "//$PARSER_IBS")){	//Use the __underload line's comment by preference, unless it's boring.
		$cmt = $ucmt;
	}

	$replacement="$ulbl\t\t$out\t\t$uopc\t\t$uarg\t\t$len\t\t//$PARSER_CMT $underload opcm merging into next CONT $cmt";
	debug_print_msg("Converted $underload opcode macro to merge into the following CONT (-), generating ${MAGENTA}this{$NORM} ".at_line($i)."\n\t${MAGENTA}".preg_replace("/\t+/","\t",$replacement)."${NORM}\n");
	return $replacement;
}

$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n. We have to do it this way and not all in one go, because we MUST
$contents="";					//NOT make a replacement inside a // comment. However, preg_replace cannot use a negative lookbehind of unknown length.
$n =  count($lines_array);			//For each line, look for __LOOP (and then consume the next line too)
for ($i=0 ; $i < $n ;$i++){	
	$p1=strpos(strtolower($lines_array[$i]), "__$RE_OPCMACRO_POST");	//If   __LOOP  is present, and "//" (if present) does NOT occur before "__NOP", then do the replacement.
	$p2=strpos($lines_array[$i],'//');
	if ( ($p1!==false) and (($p2===false) OR (($p2!==false) and ($p1<$p2))) ){
		$line = preg_replace_callback($search,"opcode_macro_post",$lines_array[$i]."\n".$lines_array[$i+1]);	//search in 2 lines (but return only one).
		if ($line === NULL){
			fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
		}
		$i++;  //skip the next line.
	}else{
		$line = $lines_array[$i];
	}
	$contents .= "$line\n";
}

//[2] Merge into the previous cont: OPCMACRO_PREV: __goto, __call, __return, __endloop
/*  "__GOTO lbl" can 'merge with' from a preceeding CONT. (it must be a "cont, -")
	The pair of instructions looks like:

		[label]		OUT		cont		-		LEN		[//comment]
				^in
				__GOTO		addr						[//comment]
														^out
	We want to replace it by:

		[label]		OUT		GOTO		addr		LEN		[//comment] //PARSER: converted opcmacro GOTO.
				^in													^out

	where  ^in and ^out mark the respective points in the search/replace. The preceeding opcode MUST be "cont -", and may have an optional label. The __GOTO may NOT have a label
*/

//This also works will all 4 of the $RE_OPCMACRO_PRE opcodes, namely "goto,call,return,endloop". __goto,__call,__endloop take arguments; __return doesn't.
//For __loop, see below.

//Search pattern:  "(token) space CONT space - space (token) space optional_comment  newline  optional-space __GOTO space (token) optional_comment"
//Where token="any non-space" and "space" = "space or tab, but not newline". (so, can't use \S,\s)
$search="/(TOK)  TS+  (CONT|CONTINUE)  TS+  ($NA)  TS+  (TOK)  (TS+)?  $RE_COMMENT?\$\n+  (TS+)?  __($RE_OPCMACRO_PREV) TS+ (TOK)? (TS+)? $RE_COMMENT?\$/imU";	//Modifiers: case-insensitive, multiline, ungreedy. Note: we DON'T care (here) about the form of the tokens, only that they are delimited.
$search=str_replace(' ','',$search); 			//Remove whitespace. Keep the R.E. legible; make PHP do the work!
$search=str_replace('TS', $RE_TS, $search); 		//Use TAB,SPACE, substituted for TS.
$search=str_replace('TOK', $RE_TOKEN, $search); 	//Use [^\t ]+ , substituted for TOK.

function opcode_macro_prev($matches){	//Callback function for the replacement. [Use a callback for simplicity.]
	global $HEADER;
	global $NA, $NORM, $MAGENTA;
	global $SAME, $SHORT;
	global $PARSER_CMT, $PARSER_IBS;
	global $i;		//Can't pass other args to a callback function, so must resort to faking global variables ($i).  !!OUCH
	global $opcode_macro_used; $opcode_macro_used = true;
	
	//The instruction we are merging with.
	$out=$matches[1];
	$opc=$matches[2];	//must be "cont": check it.
	$arg=$matches[3];	//must be "-":  check it.
	$len=$matches[4];
	$cmt=$matches[6];	//Comment, complete with leading '//', or empty.
	//The "underloaded" opcode/macro
	$uopc=$matches[8];	//goto  (or one of the re_opcode_macro_prev)
	$uarg=$matches[9];	//if present.
	$ucmt=$matches[11];
	$underload = "__".strtoupper($uopc); //__GOTO
	//debug: for ($j=0;$j<count($matches);$j++) { echo "Opc-macro match $j:  $matches[$j]\n"; }  //$i is global!

	if ( (($opc != "cont") and ($opc != "continue")) or ($arg != $NA)){  //Double check we found cont, - as expected
		fatal_error("opcode_macro_prev(): wrong opcode/arg. Expected 'CONT','$NA'; got '$opc', $arg'. Error ".at_line($i));
	}
	if ($uarg === ''){	//Where $uarg isn't given (eg because __return takes no argument, supply NA.
		$uarg = $NA;
	}
	if (($ucmt) and (substr($ucmt,0,strlen("//$PARSER_IBS"))!== "//$PARSER_IBS")){	//Use the __opcode line's comment by preference, unless it's boring.
		$cmt = $ucmt;
	}

	$replacement="$out\t\t\t$uopc\t\t$uarg\t\t$len\t\t//$PARSER_CMT $underload opcm merging into from prev CONT $cmt";
	debug_print_msg("Converted $underload opcode macro to merge into preceeding CONT (-), generating ${MAGENTA}this{$NORM} ".at_line($i)."\n\t${MAGENTA}".preg_replace("/\t+/","\t",$replacement)."${NORM}\n");
	return $replacement;
}

$lines_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n. We have to do it this way and not all in one go, because we MUST
$contents="";					//NOT make a replacement inside a // comment. However, preg_replace cannot use a negative lookbehind of unknown length.
$n =  count($lines_array);			//For each line, look at the line AFTER it for the __GOTO....   (or other opcode_macro_prev)
for ($i=0 ; $i < ($n-1) ;$i++){	
	foreach (explode('|',$RE_OPCMACRO_PREV) as $ulop){
		$p1=strpos(strtolower($lines_array[$i+1]),"__$ulop");	//If   __GOTO (or other underload opcode)  is present, and "//" (if present) does NOT occur before "__NOP", then do the replacement.
		if ($p1!==false){
			break;
		}
	}
	$p2=strpos($lines_array[$i+1],'//');
	if ( ($p1!==false) and (($p2===false) OR (($p2!==false) and ($p1<$p2))) ){
		$line = preg_replace_callback($search,"opcode_macro_prev",$lines_array[$i]."\n".$lines_array[$i+1]);	//search in 2 lines (but return only one).
		if ($line === NULL){
			fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
		}
		$i++;  //skip the next line.
	}else{
		$line = $lines_array[$i];
	}
	$contents .= "$line\n";
}

// Note: we don't support __cont or __longdelay (which would be pointless), __stop (it's already overloaded), or  __wait (which could lead to confusion)
//Debug check - ensure we didn't miss any.
$lines_array=explode("\n",$contents);
$n =  count($lines_array);
for ($i=0; $i < $n; $i++){
	if (preg_match ("/^[^\/]*($RE_OPCMACRO_ALL)/i",$lines_array[$i],$matches)){  //un-commented  underload macro. Note: checking for '/' rather than '//' is imperfect. But it's ok as a check.
		$ul = strtoupper($matches[1]);
		if (preg_match("/^__($RE_OPCMACRO_BAD)\$/i",$ul)){  //Check it wasn't one of the unsupported ones.
			fatal_error("Un-substituted opcode-macro '$ul'. This one isn't supported; only __($RE_OPCMACRO_PREV|$RE_OPCMACRO_POST) may be used. Error ".at_line($i));
		}
		$adj = "preceeding";			//usage hint.
		$usage = "$ul   label   [//comment]";   //__goto, __call, __endloop
		if ($ul == "__LOOP"){			//__loop
			$usage = "__LOOP   n   [//comment]";
			$adj = "following";
		}elseif ($ul == "__RETURN"){		//__return
			$usage = "__RETURN   [//comment]";
		}
		$hint_txt = ($ul != "__ENDLOOP")? "" : "(Hints: __loop,__endloop pairs need >= 2 intermediate CONTs; cannot have 2 adjacent __endloop macros)." ;
		fatal_error("Un-substituted opcode-macro '$ul'. It was probably mis-used. Correct usage: '${BLUE}$usage${NORM}', *and* the immediately $adj instruction (with which it merges) must be '${BLUE}CONT  $NA${NORM}'. $hint_txt Error ".at_line($i));
	}
}

//--------------------------------------------------------------------------------------------------------------
//DEAL with pseudo opcodes. Don't try to match outputs etc in detail, just use the fact they are tokenised by whitespace.
//This whole section would be better as DWIM, but it would mean somthing tricky using array_splice().
debug_print_msg("\n################### ${BLUE}BEGIN PSEUDO-OPCODE SUBSTITUTION${NORM} ##################################################################");
//[1] Overload STOP. The real STOP instruction doesn't set its outputs. So if we encounter it with outputs set, convert to composite instruction: CONT;STOP. Not quite a DWIM, since it adds an instruction.
// Note that this will have the side-effect of breaking some literal numeric (rather than labelled) jump destinations, because it changes the effective line-numbers.

	/*
	Overloaded STOP-instruction looks like:
		[label]		OUT	STOP	-	-	[//comment]
				^in		 	   		    ^out
	We want to replace it with two instructions:
		[label]		OUT	CONT	-	X	[//comment]
				^in
				-	STOP	-	-	//$PARSER: converted overloaded-STOP to CONT;STOP. //$PARSER_IBS source: generated;n+1
																		^out

	where  ^in and ^out mark the respective points in the search/replace. X is the shortest valid length, i.e. $HEADER["PB_MINIMUM_DELAY"] + $HEADER["PB_BUG_PRESTOP_EXTRADELAY"]
	LEN and ARG ought to be '-', but if they are not, they will be discarded, and a warning will be generated.
	*/

//Search pattern:  "(token)  space  (STOP)  space  (token)  space  (token)	space?   (//anything)?  ENDLINE"
//		     OUTPUT          OPCODE	     ARG           LENGTH		  COMMENT
//Where token="any non-space" and "space" = "space or tab, but not newline". (so, can't use \S,\s)
//Note: we DON'T care (here) about the form of the tokens, only that they are delimited.
$search="/  (TOK)  TS+  (STOP)  TS+  (TOK)  TS+  (TOK)  (TS+)?  $RE_COMMENT? \$ /i";   //Case-insensitive for STOP opcode.
$search=str_replace(' ','',$search); 			//Remove whitespace. Keep the R.E. legible; make PHP do the work!
$search=str_replace('TS', $RE_TS, $search); 		//Use TAB,SPACE, substituted for TS.
$search=str_replace('TOK', $RE_TOKEN, $search); 	//Use [^\t ]+ , substituted for TOK.

function overload_stop_contstop($matches){	//Callback function for the replacement. [Use a callback for simplicity.]
	global $HEADER;
	global $NA;
	global $PARSER_IBS, $PARSER_CMT;
	global $i;			//Can't pass other args to a callback function, so must resort to faking global variables ($i)

	$line=$matches[0];
	$out=$matches[1];		//OUT
	$opc=$matches[2];		//'stop' (case unknown)
	$arg=$matches[3];		//ARG (or empty)
	$len=$matches[4];		//LEN
	$cmt=$matches[6];		//Comment, complete with leading '//', or empty.

	if ($out===$NA){		//If OUTPUT is '-', we have a regular STOP. No need to do anything clever. Return the original.
		vdebug_print_msg("Ordinary, non-overloaded STOP opcode at line $i\n");
		return ($line);
	}else{
		if (($len!==$NA) and ($len != ($HEADER["PB_MINIMUM_DELAY"] + $HEADER["PB_BUG_PRESTOP_EXTRADELAY"]) )){ //Warn if LEN is not NA (or a value which we won't change)
			if ($len==0){
				print_warning("ignoring superfluous length '$len' for overloaded-opcode 'STOP' (should be explicitly '$NA') ".at_line($i));
				$len=$NA;
			}else{
				fatal_error("Opcode 'STOP' does not have a length, even if overloaded. Length '$len' is illegal (should be '$NA') ".at_line($i));
			}
		}
		if ($arg!==$NA){	//Should be "-" (meaning NA). "0" is allowed (but a bit of a syntax error). Other values are illegal.
			if ($arg == 0){
				print_warning("ignoring superfluous argument '$arg' to overloaded-opcode 'STOP' (should be explicitly '$NA') ".at_line($i));
				$arg=$NA;
			}else{
				fatal_error("Opcode 'STOP' does not take an argument, even if overloaded. Argument '$arg' is illegal (should be '$NA') ".at_line($i));
			}
		}
		print_notice("converted overloaded-STOP opcode to 'CONT(output); STOP' ".at_line($i));
		$replacement=$out."\t\t\tCONT\t\t$NA\t\t".($HEADER["PB_MINIMUM_DELAY"] + $HEADER["PB_BUG_PRESTOP_EXTRADELAY"])."\t\t$cmt\n".
			"\t\t$NA\t\t\tSTOP\t\t$NA\t\t$NA\t\t//$PARSER_CMT converted overloaded STOP opcode to CONT;STOP. //$PARSER_IBS source: auto-generated;n+1\n";
		return $replacement;
	}
}

$lines_array=explode("\n",$contents);	//Split contents into array of 1-line chunks delimited by \n. We have to do it this way and not all in one go, because we MUST
$contents="";				//NOT make a replacement inside a // comment. However, preg_replace cannot use a negative lookbehind of unknown length.

$n =  count($lines_array);
for ($i=0; $i < $n; $i++){		//For each line...
	$p1=strpos(strtolower($lines_array[$i]),"stop");	//If STOP is present, and "//" (if present) does NOT occur before "STOP", then do the replacement.
	$p2=strpos($lines_array[$i],'//');
	if ( ($p1!==false) and (($p2===false) OR (($p2!==false) and ($p1<$p2))) ){
		$line = preg_replace_callback($search,"overload_stop_contstop",$lines_array[$i]);
		if ($line === NULL){
			fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
		}
	}else{
		$line = $lines_array[$i];
	}
	$contents .= "$line\n";
}

//--------------------------

//[2] NOP => CONT, with SAME outputs and SHORTEST length ($HEADER["PB_MINIMUM_DELAY"]). This is primarily intended for debugging, in that it ignores all the
//other parts of the instruction. The intention is to allow a quick change of the input-file from, say, 'GOTO' to 'NOP'. Almost equivalent to commenting out the line,
//but useful if we don't want to comment out a label.  NOP shouldn't be used in normal programming.
/*
	NOP pseudo-instruction looks like:
		[label]		OUT	NOP	ARG	LEN	[//comment]
				^in		 	   		   ^out
	We want to replace it with:
		[label]		same	CONT	-	short	[//comment] //PARSER: converted NOP to CONT.
				^in				 			           	^out

	where  ^in and ^out mark the respective points in the search/replace. short is the shortest valid length, i.e. $HEADER["PB_MINIMUM_DELAY"]
	Unless LEN is equal to $HEADER["PB_MINIMUM_DELAY"] and OUT = "same" and ARG is '-' (which is unlikely), a warning will be generated.
*/

//Search pattern:  "(token)  space  (NOP)  space  (token)  space  (token)"   Where token="any non-space" and "space" = "space or tab, but not newline". (so, can't use \S,\s)
$search="/(TOK)  TS+  (NOP)  TS+  (TOK)  TS+  (TOK)  (TS+)?  $RE_COMMENT? \$ /i";	//Modifier 'i' for case-insensitive match to NOP. Note: we DON'T care (here) about the form of the tokens, only that they are delimited.
$search=str_replace(' ','',$search); 			//Remove whitespace. Keep the R.E. legible; make PHP do the work!
$search=str_replace('TS', $RE_TS, $search); 		//Use TAB,SPACE, substituted for TS.
$search=str_replace('TOK', $RE_TOKEN, $search); 	//Use [^\t ]+ , substituted for TOK.

function nop_cont($matches){	//Callback functionfor the replacement. [Use a callback for simplicity.]
	global $HEADER;
	global $NA;
	global $SAME, $SHORT;
	global $PARSER_IBS, $PARSER_CMT;
	global $i;		//Can't pass other args to a callback function, so must resort to faking global variables ($i).

	$out=$matches[1];
	$opc=$matches[2];
	$arg=$matches[3];
	$len=$matches[4];
	$cmt=$matches[6];	//Comment, complete with leading '//', or empty.
	
	//Warn if we use a NOP opcode at all - these are only meant for debugging. Give further details (where relevant) of which bit of instruction is overridden.
	$sub = $rep = false;
	if ( ($out!==$SAME) and ($out!==$NA)){				//Warn if OUTPUT is not 'same', or '-', in which case, we are ignoring it.
		$sub="OUT='$out', ";  $rep="OUT=$SAME, ";
	}
	if (($len!=$HEADER["PB_MINIMUM_DELAY"]) and ($len!==$NA) and ($len !==$SHORT)){	//Warn if LEN is not the same as PB_MINIMUM_DELAY (synonym: short), in which case, we are overriding it.
		$sub.="LEN='$len', ";  	$rep.="LEN='PB_MINIMUM_DELAY', ";
	}
	if (($arg!="$NA") and (substr($arg,0,2)!='//')){		//Likewise, unless ARG is '-' (or begins with '//').
		$sub.="ARG='$arg'";  $rep.="ARG='$NA'";
	}
	$sub=rtrim($sub,', ');  $rep=rtrim($rep,', ');
	print_notice("using a NOP opcode (for debugging). NOP ".at_line($i));

	if ($sub){
		print_warning("The NOP substitution above changed the values ($sub) to ($rep) ".at_line($i));
	}

	$replacement="$SAME\t\t\tCONT\t\t$NA\t\t$SHORT\t\t//$PARSER_CMT converted NOP pseudo-opcode to CONT($SAME,min_delay CMT). $cmt";
	debug_print_msg("Converted NOP pseudo-opcode to CONT($SAME,min_delay) ".at_line($i) ."\nThe replacement line is:\n\t\t\t$replacement");
	return $replacement;
}

$lines_array=explode("\n",$contents);	//Split contents into array of 1-line chunks delimited by \n. We have to do it this way and not all in one go, because we MUST
$contents="";				//NOT make a replacement inside a // comment. However, preg_replace cannot use a negative lookbehind of unknown length.

$n =  count($lines_array);
for ($i=0 ; $i < $n ;$i++){		//For each line...
	$p1=strpos(strtolower($lines_array[$i]),"nop");	//If NOP is present, and "//" (if present) does NOT occur before "NOP", then do the replacement.
	$p2=strpos($lines_array[$i],'//');
	if ( ($p1!==false) and (($p2===false) OR (($p2!==false) and ($p1<$p2))) ){
		$line = preg_replace_callback($search,"nop_cont",$lines_array[$i]);
		if ($line === NULL){
			fatal_error("Regular expression process failed. (error code: ".preg_last_error()."). Bug in ".__FILE__." at line ".__LINE__.".");
		}
	}else{
		$line = $lines_array[$i];
	}
	$contents .= "$line\n";
}

//--------------------------------------------------------------------------------------------------------------
//END OF PREPROCESSING.

//--------------------------------------------------------------------------------------------------------------
//PARSE FILE INTO ARRAYs, REMOVE COMMENTS, SPLIT LINES INTO TOKENS.

debug_print_msg("\n################### ${BLUE}BEGIN TOKENISE LINES${NORM} ##############################################################################");
$chunks_array=explode("\n",$contents);		//Split contents into array of 1-line chunks delimited by \n
$i=0;						//i counts the line number from 0, after included files, and excluding non-code lines.
$lines_array=array();				//Array containing the complete ith line of code.
$comments_array=array();			//Array containing ith COMMENT. (if a comment is on the same line as the code).
$spare_comments_array=array();			//Array containing all spare comments (i.e. comments with no line of code on that line) between lines $i-1 and $i.
$labels_array=array();				//Array containing LABEL of line i (or '')
$outputs_array=array();				//Array containing ith OUTUT
$opcodes_array=array();				//Array containing ith OPCODE
$args_array=array();				//Array containing ARG of line i (or '')
$lengths_array=array();				//Array containing ith LENGTH

foreach($chunks_array as $line){		//For each line...
	$line=trim($line);				//Now, trim whitespace.
	if ($line==""){					//Ignore the line if it is blank.     [could match on regex: (^\s*$)  ,but we just trimmed it!]
		//do nothing
	}else if (preg_match('#^\s*//#',$line)){  	//If the line is just a comment i.e. (startline,[whitespace],endline) or (startline,[whitespace],//...) .
		$spare_comments_array[$i].="//".trim($line,"/\t ")."\n\t";  //  append to $spare_comments_array[$i]. Note, we may append more than one. Remove any whitespace between '//' and start of comment, to improve readability.
	}else{						//Otherwise...
		$lines_array[$i]=$line;					//Store the complete (trimmed) ith line of code here.

		$code_comment_array=preg_split('#//#',$line,2);  	//Split the line into code and comment. Split into at MOST 2 parts.
		if (count($code_comment_array)==2){			//If there is a comment, store it.  (Note: we've lost the // in the preg_split.)
			$comments_array[$i]=$code_comment_array[1];
		}else{							//Otherwise, create an empty comment.
			$comments_array[$i]="";
		}

		$tokens_array=preg_split('/\s+/',trim($code_comment_array[0]));	//Split up the code part by whitespace.
										//i.e. [LABEL]   OUTPUT   OPCODE   ARG   LENGTH
		if (substr($tokens_array[0],-1)==':'){				//If element 0 ends with a colon, then it is a label.
			$tokens_array[0]=substr($tokens_array[0],0,-1);		//	Remove the trailing colon.
		}else{
			array_unshift($tokens_array,'');			//Otherwise, create a dummy empty element for LABEL. (by unshifting a "")
		}
		
		$tcount = count($tokens_array);
		if ($tcount != 5){
			if (strpos($code_comment_array[0], $SEMI_COLON) !== false){ //Check for ';'. These are standard in 'C' and PHP, but wrong here. Eg macro-calls like 'macro(1,2);' wouldn't be recognised, because the ';' messes up the RE.
				fatal_error("this line contains an unexpected '$SEMI_COLON' character. [Unlike C, PBSRC files don't use '$SEMI_COLON' to terminate statements.] Error ".at_line($i));
			}elseif ($tcount>5) {					// if > 5, then, we have too many! This is *probably* indicative of a label missing its colon.
				fatal_error("too many tokens ($tcount) in this line. Perhaps a label is missing its terminating colon, or a token contains an inadvertent space? Error ".at_line($i));
			}elseif ($tcount <5){					//If < 5, then too few. All 4 parameters (+label) are required.
				fatal_error("too few tokens ($tcount) in this line. Exactly 4 tokens (+ optional 'label:' prefix and '//comment' suffix) are required. Error ".at_line($i));
			}
		}

		$labels_array[$i]=$tokens_array[0];		//Contains the ith label (or a "" if there is none)
		$outputs_array[$i]=$tokens_array[1];		//Contains the ith output
		$opcodes_array[$i]=$tokens_array[2];		//Contains the ith opcode
		$args_array[$i]=$tokens_array[3];		//Contains the ith argument
		$lengths_array[$i]=$tokens_array[4];		//Contains the ith instruction length
		$i++;
	}
}
$number_of_code_lines=$i;		//the total number of lines of *actual code* (and also the number of elements in outputs_array etc.)
$redundant_labels=$labels_array;	//As each label is dereferenced, it will be removed from $redundant_labels. (to check for wasted labels)

//--------------------------------------------------------------------------------------------------------------
//Dump lines (as parsed) to stdout. This is helpful if you get a cryptic 'error at line $i' message and find yourself thinking: 'Now, which line is $i ?!'
//Yes, this is an UGLY hack, but to track the erroneous line to the correct place in the source file (and maybe even the correct file) would be rather time-consuming to implement!
//[UPDATE: we *do* now have this information, so be more helpful]
//Everything else goes to STDERR, so send this to STDOUT to be helpful.  We have to do it this early, before the fatal error on which we want more information.
if ($DO_DUMPLINES){
	print_msg("\n################### ${BLUE}BEGIN LINE DUMP${NORM} ###################################################################################");
	print_msg("Now dumping preprocessed lines of the (generated) code to stdout. The line is identified by a zero-based instruction number (aka 'the line number', and (lower-cased) source-file with corresponding line number. ".
		  "Lines are dumped after pre-processing, tokenising, and substitution of #defines, and #macro-expansion, but before parsing each field as a number/opcode.\n");
			//TODO: ideally, we would reformat the line to align the comments all to the right, and re-arrange each opcode with str_pad.
	for ($i=0;$i<$number_of_code_lines;$i++){
		$info=identify_line($lines_array[$i]);
		$line=$info['tidied_colour'];			//text of line (after removing the magic $PARSER_IBS stuff).
		$sourcefile=$info['sourcefile_colour'];	//source filename and line number corresponding (hopefully!) to line $i.
		$sourcelinenum=$info['sourcelinenum_colour'];
		$identifier="Line ".str_pad($i,3)." ".str_pad("($sourcefile,$sourcelinenum) ",30).": ";
		$tabs = ($labels_array[$i]=='') ? "                " : "" ; 	//Insert \t\t (as spaces) if there is no label - helps re-adjust lines.
		$bits = explode("//",$line);
		$code = str_pad("$tabs$bits[0]",90);
		$cmt  = (!array_key_exists(1, $bits)) ? "" : "${GREY}//$bits[1]${NORM}"; 
		output("$identifier\n\t$code\t$cmt\n");
	}
	print_msg("\nEnd of dump of lines to stdout.");
	print_msg("\n################### ${BLUE}END LINE DUMP${NORM} #####################################################################################\n");
}

//--------------------------------------------------------------------------------------------------------------
//FOR DEBUG - print out what we have now. This is very similar to the above, but after tokenising the lines.

if ($VERBOSE_DEBUG){
	print_msg("\n################### ${BLUE}BEGIN VERBOSE DATA DUMP${NORM} ###########################################################################");
	print_msg("End of pre-processing and tokenising. Printing all lines and their tokenisation to stdout.The line-numbers at the start of each line match the zero-based instruction ".
		"number, which is referred to as 'the line number' by many error messages. This is followed by the source-file (sometimes folded to lower-case), and the ".
		"line number within the source. What we are dumping is the pre-processed and tokenised lines, after substitution of #defines, and macro-expansion, but before parsing each ".
		"field as a number/opcode.\n");
	for ($i=0;$i<$number_of_code_lines;$i++){		//The original code line, followed by how it has been parsed. Ideally, we'd like to show the original line, before #defines were
								// substituted in, but that would be extremely hard to do.
		$spare_comments=(array_key_exists($i,$spare_comments_array)) ? explode("\n",$spare_comments_array[$i]): array();
		$printme='';
		foreach ($spare_comments as $scomment){
			$info=identify_line($scomment);
			$interesting=trim($info['line']);
			if ($interesting){
				$printme.="\t$interesting\n";
			}
		}
		if($printme){
			print_msg("Before line $i:\n$printme");
		}

		$info=identify_line($lines_array[$i]);
		if ($labels_array[$i]){
			$label_indent="";	//indent if there's no label, for tidiness.
		}else{
			$label_indent="\t\t";
		}
		print_msg("Line $i ($info[sourcefile_colour], line $info[sourcelinenum_colour]) is:\n\tSOURCE:\t${DBLUE}$label_indent$info[line]${NORM}");
		print_msg("\tLABEL:\t$labels_array[$i]");
		print_msg("\tOUTPUT:\t$outputs_array[$i]");
		print_msg("\tLENGTH:\t$lengths_array[$i]");
		print_msg("\tOPCODE:\t$opcodes_array[$i]");
		print_msg("\tARG:\t$args_array[$i]\n");
	}
	print_msg("\n################### ${BLUE}BEGIN ADDRESS-TABLE DUMP${NORM} ##########################################################################");
	print_msg("Here are the addresses which are labelled:");	//Now print the label table, i.e.
	foreach ($labels_array as $key => $value){			//all the labels and their addresses.
		if ($value!=""){
			print_msg("\tADDRESS:  $key\tLABEL:  $value");
		}
	}
}

//--------------------------------------------------------------------------------------------------------------
//Check that the file actually contains some instructions!
if ($number_of_code_lines == 0){
	fatal_error("Source file '$SOURCE_FILE' contains no actual pulseblaster instructions. It's either empty, or consists entirely of comments!");
}

//--------------------------------------------------------------------------------------------------------------
//PARSE THE TOKENS INTO VALUES.

//--------------------------------------------------------------------------------------------------------------

//FUNCTION TO PARSE NUMERIC EXPRESSIONS into an integer.  Split up by operators, parse the strings separately, then eval().
//Although PHP's native integer is only signed-int32 (on 32-bit CPU), a double has 52-bit integer precision. So even the larger values (eg 32-bit LENGTH) will fit. Be careful using intval(), '%'.  or printf('%x') though.
function parse_expr($complicated_string, $linenumber, $type, $silent=false){	//$complicated _string is something like "2*(4+5/6)|0x2 ".    In the case of length, units are allowed  eg "0.2*(200us+1us)"
	global $DEBUG, $NA, $SHORT;		//$linenumber is for error-messages.
	global $HEADER;				//$type is "OUTPUT", "ARG", "SETTING", "BITWISE" or "LENGTH".  LENGTH is the most general type.
	global $SUFFIX_NS;			//Valid examples for lengths:  15, 15_ticks, 1_ps, 15_ns, 15_ms, 15_us, 15_s, 4.3_s, 0.1week. Decimals are allowed, eg 4.3_ms is the same as 4300_us.
	global $RE_OPERATORS,$RE_OPERATORS_MATH,$RE_OPERATORS_BITWISE,$RE_OPERATORS_COMPARE,$RE_OPERATORS_LOGICAL,$RE_DEC,$RE_HEX,$RE_BIN,$RE_OCTAL_UGH,$RE_DEC_FRAC;  //$silent makes this quiet, and return false on error rather than die.
	global $CYAN, $NORM, $DBLUE, $RED;			//coloiur highlighting.

	$input = $complicated_string;
	if ($input === $NA){				// If not applicable (i.e. a single "-"), return it unaltered.
		return ($NA);
	}
	$input=str_replace('_','',$input); 		//Strip out any '_'. We want to ignore them.

	if ($input===''){				//Check it isn't blank. (NOTE: 0 is OK, so test with ===)
		fatal_error("$type value '$complicated_string' is empty. Error ".at_line($linenumber));
	}

	/* Only the following choices *should* need to be used, but let's not bewilder the user for no reason, and generate confusing error messages.
	 * if ($type == "LENGTH"){	 			//Length may only use the maths, comparison, logical operators:  *+/-()  and units.
	 *	$re_split = "$RE_OPERATORS_MATH|$RE_OPERATORS_COMPARE|RE_OPERATORS_LOGICAL";
	 *}elseif ($type == "SETTING"){
	 *	$re_split = $RE_OPERATORS_BITWISE;	//Settings can only use bitwise operators.
	 *}else{
	 *	$re_split = $RE_OPERATORS;		//Otherwise, all operators: maths, bitwise, logical and comparison are allowed..
	 *} */
	$re_split = $RE_OPERATORS; 	 //Less scope for confusion than above.

	$expr = $expr_d = '';				//Split up the string by any of the operators.
	$ptr = 0;					//Build up the resulting $expr
	$cs = $cs_d = '';
	$dcheck = '';					//dimensionality check.
	$result=false;
	$bits = preg_split ("/$re_split/", $input, NULL, PREG_SPLIT_OFFSET_CAPTURE);
	foreach ($bits as $bit){
		$mult = 1;
		$str = $bit[0];
		$offset = $bit[1];
		$delim = substr ($input, $ptr, ($offset - $ptr));
		$ptr = $offset + strlen($str);
		$int = false;
		if ($delim){				//delimiter is the operator.
			$cs .= "$RED$delim$NORM,";
			$cs_d.= "$delim,";
			$expr .= $delim;
			$expr_d .= "$RED$delim$NORM";
			$dcheck .= $delim;
		}
		if ($str!==''){				//str is the string betwen the operators, to be parsed as a number (usually int)
			$str_copy = $str;

			if ($type == "LENGTH"){		//Length may have units attached; deal with them. If there is NO unit, or the unit is explicitly 'ticks', don't modify the number. Else, convert by suffix and PB_TICK_NS.
				$in_ticks = false;	//Formula is: ticks = string * suffix_multiplier / $HEADER["PB_TICK_NS"]
				$dcheck_units = 0;
				if ($str === $SHORT){	//If 'short' is used within an expression, treat it as PB_MINIMUM_DELAY. (But if it stands alone, parse_expr() is not called, and 'short' has opcode-dependent value).
					$str = $HEADER["PB_MINIMUM_DELAY"];
				}
				foreach ($SUFFIX_NS as $suffix => $multiplier){	//Convert suffix to multiplier, then remove the suffix. If there is no suffix (eg '5'), then trate as integer number of ticks.
					if (substr ($str, - strlen($suffix)) == $suffix){
						$str = substr ($str,0,-strlen($suffix));
						if ($multiplier == -1){		//-1 is special case: units were explicitly specified in ticks, (e.g. '5_ticks'), rather than with no suffix.
							$in_ticks = true;
						}else{
							$mult = $multiplier / $HEADER["PB_TICK_NS"]; //Convert from ns to ticks.
						}
						$dcheck_units = 1e6;
					}
				}
				$dcheck .= $dcheck_units;
			}
			if (preg_match("/^$RE_OCTAL_UGH\$/",$str)){	//  Input has leading zero, so it is ambiguous. Does "025" mean 25, or 21?   This "bug" is probably fixed upstream now.
				if ($silent){ return false; }
				fatal_error("parse_expr(): value '$str_copy' is ambiguous: it has a leading '0', so might be interpreted as octal. We probably don't want to do that! Error ".at_line($linenumber));
			}elseif (preg_match("/^($RE_DEC|$RE_HEX)\$/i",$str)){ //Dec/Hex integer.Cast directly, don't use intval() because intval fails when larger then PHP_INT_MAX.
				$int = $str +0;				//PHP will overflow cleanly to a double (whose integer range is greater than int).
			}elseif (preg_match("/^($RE_DEC_FRAC)\$/",$str)){  //Decimal fraction.  As above.
				$int = $str +0;
				if (($type == "LENGTH") and ($in_ticks)){  //Fractional lengths of ticks aren't allowed: this would be a programming mistake.
					if ($silent) {return false;}
					fatal_error("parse_expr() length expression '$input' part '$str_copy' explicitly specifies a fractional number of TICKs. Don't be silly. Error ".at_line($linenumber));
				}
			}elseif (preg_match("/^$RE_BIN\$/i",$str)){	// Binary, beginning 0b.
				$str = substr($str,2);			//  PHP doesn't recognise this format (except very recent versions), so trim the 0b, and use intval with base 2.
				$int = intval($str,2);			//  This will have problems for values > 2^31. Can't easily work-around, so just detect error.
				if ($int == PHP_INT_MAX){		// Intval overflow doesn't wrap, but sets to PHP_INT_MAX.
					if ($silent){ return false; }
					fatal_error("parse_expr(): binary $type expression '$input', part '$str_copy' is too large, exceeding PHP_INT_MAX. Error ".at_line($linenumber));
				}
			}elseif ( ($type == "LENGTH") and ($str == '') ){ //Expressions containing Bare units eg '(20+90)*us'  should have the 'us' treated as '1us'.
				$int = 1;
				if (!$silent){ print_notice ("parse_expr(): parsed $type expression '$input', part '$str_copy' as '1 $str_copy'. Notice ".at_line($linenumber)); }
			}else{
				if ($silent){ return false; }
				fatal_error("parse_expr(): cannot parse $type expression '$input', part '$str_copy' as a positive integer. Error ".at_line($linenumber));
			}

			$int *= $mult;		//multiply by length factor, if necessary.
			$cs .= "$DBLUE$str_copy$NORM,";
			$cs_d.= "$str_copy,";
			$expr .= $int;
			$expr_d .= "$DBLUE$int$NORM";
		}
	}
	$cs = rtrim ($cs,","); $cs_d = rtrim ($cs_d,",");

	$ret = @eval("\$result = $expr;");	//Evaluate the expression using PHP's eval().   (This eval() is safe: malicious code from the source file couldn't get to it).
	if ($ret === false){			//Check this succeeded. Eg division by zero is fatal.
		if ($silent){ return false; }
		print_warning ("Line $linenumber: expression '${CYAN}$input${NORM}' was parsed as '$cs' and converted to '$expr_d' by eval().");
		fatal_error("parse_expr(): cannot evaluate string '${CYAN}$input${NORM}': eval() failed on calculation. Error ".at_line($linenumber));
	}

	if ($type != "LENGTH"){			//Expressions other than length must evaluate to integers. Otherwise, it is clearly a bug.
		if ($result != floor($result)){
			if ($silent){ return false; }
			fatal_error ("parse_expr(): evaluated $type string '$input' as '$result'; this is not an integer. Error ".at_line($linenumber));
		}
	}else{					//Lengths can be rounded (eg converting 3.33333us), but this should generate warnings if significant.
		$round = round($result);
		if ($result == 0){		//don't divide by 0!  (The fact that LEN==0 is too short will be caught later).
			$error_percent = 0;
		}else{
			$error_percent = round((100 * abs($round - $result) / $result),4); //Is this a significant error?
		}
		if ($error_percent > 1){	//Rounded by > 1%.  Warn.
			if ($silent){ return false; }
			print_warning ("parse_expr(): rounded with '$error_percent %' error: evaluated string '$input' as '$result' ticks; converted to integer '$round'. Warning ".at_line($linenumber));
		}elseif ($round != $result){				//  Notice.
			if ($silent){ return false; }
			print_notice ("parse_expr(): rounded with '$error_percent %' error: evaluated string '$input' as '$result' ticks; converted to integer '$round'. Notice ".at_line($linenumber));
		}
		$result = $round;

		//Dimensionality check. Rather a nasty hack. Take the expression, replace any number with explicit units by 1E6, and other numbers by zero. Then convert - to +, / to *, and % to *.
		$dcheck = str_replace('/','*',str_replace('%','*',str_replace('-','+',$dcheck)));
		$ret = @eval("\$dcheck = $dcheck;");  	//If the result is > 1e11, then this is dimensionally wrong (or the string has a stupid number of terms). If not, it is (probably) dimensionsally ok.
		if ($ret === false){			//Note that "77" can be either "77ticks" or "the number 77" depending on context.
			if ($silent){ return false; }
			fatal_error("parse_expr(): cannot evaluate dimensionality-check expresssion '$dcheck', caused by input '$input'. Error ".at_line($linenumber));
		}elseif ($dcheck > 1e11){
			if ($silent){ return false; }
			fatal_error ("parse_expr(): $type string '$input' attempts to multiply two elements which both have explicit units of time. Dimensionality error ".at_line($linenumber));
		}
	}
	vdebug_print_msg ("Line $linenumber: evaluated $type expression '$input' (expr parsed as '$cs_d') as $result (Hex: ".sprintflx($result,0,$linenumber).").");
	return ($result);			//Return the value. Hopefully this is sensible!
}						//[We check that the value is in range after returning.]

//--------------------------------------------------------------------------------------------------------------
function parse_bitwise($complicated_string,$linenumber){	//Parse a bitwise change to the previous output. Also cope with the string 'same'.
	global $DEBUG, $SCREAM;					//This is with respect to $previous_value, i.e. $outputs_array[previous line].
	global $HEADER;						//Previous means the "$i-1" output, and NOT necessarily "most recently executed" if is_destination() is true.
	global $outputs_array;					//IMPORTANT: these bitwise modifications are "executed" by the parser, NOT by the pulseblaster
	global $NA;						//See parse_bitwise() for more
	global $SAME, $SQUELCH_PREFIX;				//Squelch can supress warnings.
	global $RE_BITWISE;					//bit_XXX
	global $RE_EXPRESSION;					//Integers or expression.
	global $number_of_silences;				//count squelches	
	static $warned_same=false;				//Don't warn so verbosely every time.

	if ($linenumber == 0){  //Previous value must be defined. We can't deal with a bitwise change on line zero.
		fatal_error("can't read the previous value of OUTPUT from the line before: it is undefined for the -1st line! 'bit_' and 'same' are not permitted in the first instruction. Error ".at_line($linenumber));
	}
	$previous_linenumber=$linenumber-1;			//Previous output value (already parsed).
	$previous_value=$outputs_array[$previous_linenumber];
	$squelch = $squelch_dbg_txt = false;

	$input=$complicated_string;
	$input=str_replace('_','',$input); //Strip out any '_'. We want to ignore them.

	$mask24=$HEADER["PB_OUTPUTS_24BIT"]+0; //Bitmask: 0xFFFFFF. This is the maximum legal value for a 24-bit number. The +0 seems necessary, although it shouldn't be. Use for truncation.

	if (substr($input,0,1) === $SQUELCH_PREFIX){ //Prefixed with '@'   (eg  '@bit....'  or ''same') can squelch the warning about using these in controversial places.
		$input = substr($input,1);
		if ($DEBUG){			     //But debug overrides '@' and shows warning anyway.
			$squelch_dbg_txt = "(debug overrides '@'.) ";
		}elseif ($SCREAM){
			$squelch_dbg_txt = "(scream overrides '@'.) ";
		}else{
			$squelch = true;
		}
	}

	if ($input == $SAME){			//SAME as previous output.
		$change_txt='the same as';
		$output=$previous_value;
		vdebug_print_msg("Line $linenumber: evaluated OUTPUT string '$complicated_string' as SAME as previous line ($previous_linenumber), i.e. $output (Hex: ".sprintf("0x%x",$output).").");  //Print something helpful for debugging.
		if ($output==="$NA"){   //If the previous output was '-', then using "same" is likely to cause trouble. The only time output is allowed to be '-' is the 2nd instruction of an overloaded "STOP", so it should never be followed anyway.
			print_warning("Just evaluated '$SAME' as '$NA' at line $linenumber. This is likely to be fatal. It probably means you put a NOP after a STOP (which should never happen!).");
		}

	}else if (strpos($input,'bit')!==false){ //One, or more bitwise changes. For example:   bit_set(0x01),bit_clear(0xf0)

		$change_txt='a bitwise change from';
		if (!preg_match("/^$RE_BITWISE\($RE_EXPRESSION\)(,$RE_BITWISE\(($RE_EXPRESSION)\))*\$/",$input)){   //Check for valid format.
			//   ^  bit_XXX  \(  expression  \)    (,  THE_SAME  ) *  $	//I.e. one or more bitwise changes.
			fatal_error("bitwise change '$complicated_string' is not a valid format. Error ".at_line($linenumber));
		}

		$output=$previous_value;	//Iterate (left to right) through the bitwise changes, each time, modifying the output as required.
		$changes_list='';
		$changes = explode (",bit", substr($input,3));	//Remove the initial 'bit'. Then explode by ',bit'.
		foreach ($changes as $change){

			$change="bit_".$change;				//Replace synonyms if required. Right-hand column are the main names (all 3 characters!)
			$change_copy=$change;				//[we reinstated the  'bit_' for clarity, and since 'or' is a substring of 'xor' !]
			$change=str_replace('bit_or',   'bit_set',$change);	// "or" => "set"		bit_set		SET bits     $number
			$change=str_replace('bit_clear','bit_clr',$change);	// "clear" => "clr"		bit_clr		CLEAR bits   $number
			$change=str_replace('bit_mask', 'bit_and',$change);	// "mask" => "and"		bit_and		AND with     $number
			$change=str_replace('bit_flip', 'bit_xor',$change);	// "flip" => "xor".		bit_xor		XOR with     $number
			$change=str_replace('bit_xnor', 'bit_xnr',$change);	// "xnor" => "xnr".		bit_xnr		XNOR with    $number
			$change=str_replace('bit_nand', 'bit_nnd',$change);	// "nand" => "nnd".		bit_nand	XNAND with    $number
			//no synonyms										bit_nand	NOR with    $number
			//											bit_add		ADD to 	     $number  and warn if overflow.
			//											bit_sub		SUBTRACT     $number  and warn if underflow.
			//											bit_bus		SUBTRACT FROM $number and warn if underflow.
			//											bit_rlf/rrf	ROTATE bits left/right, within 24_bits. Ignore $number.
			//											bit_slc/src	SHIFT left/right and Clear: Shift, Mask at 24 bits, Shift in zero. Ignore $number.
			//											bit_sls/srs	SHIFT left/right and Set: Shift, Mask at 24 bits, Shift in one. Ignore $number.

			$command=substr($change,4,3);	//the 'command' part. (removed the 'bit_' again). MUST be 3 characters long.
			$number=substr($change,7);		//the numeric part, e.g.  (0xf), or ().
			$number=substr($number,1,-1);		//trim off the outer ().

			if (($number==='') or ($number===$NA) ){	//  - Input was (), i.e. empty. Also, allow (-) to make this explicit.
				$number=false;
				//We originally considered this valid syntax for the bit-shift/rotate instructions, meaning "shift-by-one". Now, always require a number to shoft by.
				fatal_error("invalid bitwise change '$change_copy'. bit_$command requires an argument: for example 'bit_set(0xf0)' or 'bit_rlf(1)'. Error ".at_line($linenumber));
			}else{					//Parse input as binary/hex/decimal number.
				$number=parse_expr($number,$linenumber,"BITWISE");
			}

			if (in_array($command, array ('rrf', 'rlf', 'srs', 'sls', 'src', 'srs') )){  //Bitshift commands should have sensible numbers.
				if (($number < 0) or ($number > 64)){
					fatal_error ("bitwise change '$change_copy' contains bit_$command, with number '$number'. Shifting by this number of bits is crazy. Error ".at_line($linenumber));
				}
			}

			//By now, we have a number in $number, and a 'command' in $command.  All commands *require* $number
			switch ($command){		//$command is one of:    {set, clr, and, xor, xnr, add, sub, bus}
				case 'set':			//bit_set, or bit_or
					$output=($output | $number);
					break;
				case 'clr':			//bit_clr, bit_clear
					$output=($output & (~$number));
					break;
				case 'and':			//bit_and, bit_mask
					$output=($output & $number);
					break;
				case 'xor':			//bit_flip, bit_xor
					$output=($output ^ $number);
					break;
				case 'xnr':			//bit_xnor  (xnr)
					$output=( (~($output ^ $number)) & $mask24); //Mask this to 24-bits.
					break;
				case 'nnd':			//bit_nand
					$output=( (~($output & $number)) & $mask24); //Mask this to 24-bits.
					break;
				case 'nor':			//bit_nor
					$output=( (~($output | $number)) & $mask24); //Mask this to 24-bits.
					break;
				case 'add':			//bit_add   ADD this to previous value.
					$output_copy=$output;
					$output=($output + $number);
					if ($output > $mask24){
						$output = $output & $mask24;	//Truncate it to 24 bits.
						print_warning("overflow for addition. Truncated to 24 bits. ($output_copy + $number = $output). Warning ".at_line($linenumber));
					}
					break;
				case 'sub':			//bit_sub.  SUBTRACT $number from previous value.
					$output_copy=$output;
					$output=($output - $number);
					if ($output < 0){
						$output = $output & $mask24;	//Truncate it to 24 bits.
						print_warning("underflow for subtraction. Truncated to 24 bits. ($output_copy - $number = $output). Warning ".at_line($linenumber));
					}
					break;
				case 'bus':			//bit_bus. SUBTRACT previous_value FROM $number.
					$output_copy=$output;
					$output=($number - $output);
					if ($output < 0){
						$output = $output & $mask24;	//Truncate it to 24 bits.
						print_warning("underflow for subtraction. Truncated to 24 bits. ($output_copy - $number = $output). Warning ".at_line($linenumber));
					}
					break;

				case 'rlf':			//bit_rlf. ROTATE left (MSB comes back in as LSB). Do it n times. [rlF, rrF is by analogy with PIC]
					for ($i=0; $i<$number; $i++){
						$output=$output << 1;
						if ($output > $mask24){
							$output = (($output & $mask24) + 1);
						}
					}
					break;
				case 'rrf':			//bit_rrf. ROTATE right (LSB comes back in as MSB). Do it n times
					for ($i=0; $i<$number; $i++){
						if (($output & 0x1) == 1){
							$output = $output >> 1;
							$output = ($output & $mask24) + (($mask24 + 1) >> 1);
						}else{
							$output = $output >> 1;
							$output &= $mask24;
						}
					}
					break;
				case 'slc':			//bit_slc. SHIFT left and Clear (Truncate on LHS, and new LSB is always zero.). Do it n times
					$output = $output << $number;
					$output &= $mask24;
					break;
				case 'src':			//bit_src.  SHIFT right and Clear (Discard LSB, and new MSB is always zero.). Do it n times
					for ($i=0; $i<$number; $i++){
						$output = $output >> 1;
						$output = (($output & $mask24) ^ (($mask24 + 1) >> 1));
					}
					break;
				case 'sls':			//bit_sls. SHIFT left and Set (Truncate on LHS, and new LSB is always one.). Do it n times
					for ($i=0; $i<$number; $i++){
						$output = $output << 1;
						$output = (($output & $mask24) | 0x1);
					}
					break;
				case 'srs':			//bit_srs.  SHIFT right and Set (Discard LSB, and new MSB is always one.).
					for ($i=0; $i<$number; $i++){
						$output = $output >> 1;
						$output = (($output & $mask24) | (($mask24 + 1) >> 1));
					}
					break;

				default:			//Unknown command
					fatal_error("unrecognised bitwise change '$change_copy'.  Error ".at_line($linenumber));
					break;
			}
			$changes_list.=strtoupper($command).'('.sprintf("0x%x",$number).'), ';
		}
		$changes_list=trim($changes_list,", ");

		vdebug_print_msg("Line $linenumber: evaluated OUTPUT string '$complicated_string' as $output (Hex: ".sprintf("0x%x",$output)."). Applied bitwise changes '$changes_list' to previous value $previous_value.");  //Print something helpful for debugging.

	}else{	//Unrecognised => error.
		fatal_error("'$complicated_string' cannot be recognised as a bitwise change or a 'same' output. Error ".at_line($linenumber));
	}

	$desttype=is_destination($linenumber);	//Note: if the line is a destination (i.e. has a label, or is after a call), this calculation is not based on the previous instruction!
	if ($desttype){				//We must warn - it's most likely a mistake.
		if ($desttype=='after_return'){
			$dest_string="follows a subroutine's RETURN";	//'previous' (addr) NEVER equals 'previous' (chronological).
		}else{ //has_label
			$dest_string="is (sometimes) the destination of a jump (i.e. of a call/goto/endloop)";  //'previous' (addr) SOMETIMES doesn't equal 'previous' (chronological).
		}

		//We'd like to print the origin(s) of the jump, but we haven't yet parsed everything, so we can't. Also, we can't be *certain* that the destination is used; only that it has a label.
		//Ideally, we'd  make 'previous' mean 'most recently executed', but that wouldn't be unique (consider the first instruction in a loop).
		if (!$squelch){  //Squelch warnings using the '@' operator, except in debug mode.
			if ($warned_same and !$DEBUG){		//this common warning is verbose - shorten it after the 1st time.
				print_warning("output at line $linenumber is \"$change_txt the 'previous' line\", However line $linenumber $dest_string. $squelch_dbg_txt Warning ".at_line($linenumber));
			}else{
				print_warning("output at line $linenumber is \"$change_txt the 'previous' line\", (i.e \$address-1, line ".($linenumber-1)."). ".
					"However, line $linenumber $dest_string, so the 'previous' (i.e. most recently executed) instruction is not *always* line ".($linenumber-1).". ".
					"Is this intentional? Otherwise, unexpected results will occur: see documentation on 'bitwise changes'. ".
					"$squelch_dbg_txt Warning ".at_line($linenumber));
			}
			$warned_same = true;
		}else{
			$number_of_silences++;
		}
	}

	//Actually, this one is OK, no need to warn. Macros are expanded inline, rather than like function calls.
	//if ((follows_macro($linenumber)) and (!$squelch)){  //Similar notice, if we have just finished inlining a macro. 
	//	//print_warning("output at line $linenumber is \"$change_txt the 'previous' line\", which is an inlined macro. Is this intentional? Warning ".at_line($linenumber));
	//}
	
	return ($output);
}

//--------------------------------------------------------------------------------------------------------------
//A FUNCTION to REPORT WHETHER A LINE is a DESTINATION of a jump, or not.
	/*If the line is a destination, return the reason ('has label' or 'after_return'). Otherwise, return false.
	Destination lines are those which are the target of a jump (i.e. ENDLOOP,GOTO,CALL,RETURN)
	Errors:
	 - False positives: occur if we have a redundant label. We warn later about this (redundant labels)
	 - False negatives: if ARG to ENDLOOP,CALL,GOTO is a number, not a label. We warn about this in sanity-checks.
	*/
function is_destination($linenumber){	//Return reason if the line is a destination; false otherwise.
	global $labels_array;		//Destination lines are those which are the target of a jump. [or which have a redundant label]
	global $opcodes_array;		//ENDLOOP, GOTO and CALL identify their destinations by the label in ARG. However, RETURN doesn't: it is simply the instruction after the CALL.
	if ($labels_array[$linenumber]){ //Thus, line $linenumber is a destination if either:
		return ("has_label");	 //	$labels_array[$i] is non-empty.			[This will also trigger on unused labels]
	}else if ($opcodes_array[$linenumber-1]=="call"){
		return ("after_return"); //	$opcodes_array[$linenumber-1] == "call"
	}else{
		return (false);		//We care about this, since a destination instruction may not be executed in sequence. So bitwise instructions w.r.t. the "previous" output
	}				//might not mean what we expect. Previous means "$i-1", and NOT necessarily "most recently executed". See parse_bitwise() for more.
}

function follows_macro($linenumber){    //Does a particular linenumber follow an inlined macro? If so, return true. (Else, false)
	global $comments_array;
	global $PARSER_IBS;
	return (strpos($comments_array[$linenumber-1],"$PARSER_IBS last line of macro")!==false);   //The string "$PARSER_IBS end of macro" is appended to every macro when it is inlined.
}

//--------------------------------------------------------------------------------------------------------------
//A FUNCTION to ESTIMATE FACTORS
/*
We wish to factorise $product into two factors, $smaller and $larger as accurately as possible.  All 3 variables are integers (though they may be stored within a float). The ranges are:
	1 < $smaller <= $max_arg      (this is a constraint on $smaller)
	0 < $larger <= $max_length    (this is a constraint on $larger)
	0 < $product  < $max_value    (we know this, and define $max_value=$max_length*$max_arg.)
We want to find a good approximate rule, which always works. This algorithm is very good (see accuracy), but it does not necessarily spot most exact solutions, if they exist.
For example with $max_arg=10,$max_length=50, this algorithm would calculate 133 as (approx) 44*3 [which evaluates to 132], but would miss out on the exact answer, 7*19.
So try a bit harder: test up to 100 neighbours to see if they are any better, This makes it hit exact answers for "nice" numbers.

Calculation (with integers):
	1) $larger = $product/$smaller.	  	The remainder (and errors) will be smallest if $smaller is smallest.

	2) So, we need to find the smallest possible value of $smaller which satisfies $larger < $max_length.
	   =>  $product/$smaller < $max_length
	   =>  $smaller/$product > 1/$max_length
	   =>  $smaller > $product/$max_length.

	3) Thus, we calculate $smaller as the integer division of $product/$max_length,  ROUNDED UP TO THE NEAREST INTEGER.
	   We round up a) so that we later divide by a number which is too large, never too small and b) So that $smaller != 0.

	4) Check that $smaller is still < $max_arg. If not, we know that we are exactly on the limit, therefore
		$smaller=$max_arg and $larger=$max_length
	   otherwise...

	5) $larger= integer division of  ($product / $smaller)
	   Calculate the remainder. If ($remainder/$smaller) is greater than 0.5, then increment $larger by 1.
	   Check incase the increment makes $larger exceed $max_length. If so, decrement $larger again.

	6) Calculate the actual result ($smaller * $larger) and the error and fractional error.

Accuracy:
	1)For small numbers (i.e. $product < $max_arg), this is exact.
		The result is $smaller=1 and $larger = $product
	2)For larger numbers (i.e. $product > $max_arg),
		$remainder < $smaller
	     => $error < $smaller/2
	     => $fractional_error < ($smaller/2) / $product			But $smaller = $product/$max_length, ROUNDED UP.
	     => $fractional_error < 1/($max_length *2)	+ 1/($product *2)	The right-hand '1' arises from the rounding.
	     => $fractional_error < 1/ $max_length			$product > $max_length.

	3)If $product is zero the result is $smaller=1 and $larger=0.
	4)If $product is negative,  or out of range (i.e. $product > $max_value), this will generate a fatal error.
*/
function estimate_factors($product,$linenumber){	//$product is the number (stored as float, should be an integer) that we wish to factorise (approximately). Return an array of 4 values
	global $HEADER;					//($larger,$smaller$actual,$error) and a float ($frac_error) $larger is the bigger of the 2 factors; $smaller is the smaller;
	global $string_20_BIT;		//Format: 2^x -1	//$error is the actual (signed) error; $frac_error is the (unsigned) fractional error.
	global $string_32_BIT;				//Result is exact for smaller numbers, and has fractional error of <  1/ (2*$maxlen) for larger ones.

	$max_length=$HEADER["PB_DELAY_32BIT"]+0; 	//Max value of the length, i.e. the larger of the factors.
	$max_arg=$HEADER["PB_ARG_20BIT"]+0;	  	//Max value of the arg, i.e. the smaller of the factors.
	$max_value=$max_length*$max_arg;		//Max value for the product.

	if ($product > $max_value){			//Check that $product is sufficiently "small" to fit.
		fatal_error("product to be factorised, '$product' is too large to fit into LENGTH * ARG. It is bigger than (($string_32_BIT) *  ($string_20_BIT)). Please split this extremely long long_delay into two or more ordinarily long long_delays. Error ".at_line($linenumber));
	}
	if ($product < 0){		   //Negative numbers not allowed. Zero raises a warning.
		fatal_error("product to be factorised, '$product' is negative. What are you trying to do?! Error ".at_line($linenumber));
	}elseif ($product==0){
		print_warning("product to be factorised, '$product' is zero. This might not be intentional. Warning ".at_line($linenumber));
		$result["larger"]=$product;	//0
		$result["smaller"]=1;		//1
		$result["actual"]=$product; 	//0
		$result["error"]=$product;	//0
		$result["fractional_error"]=0;	//0
	}else{
		$smaller = ceil ($product / $max_length);    //Round up, to nearest integer. (Can't ever be 0, since $product !=0). We must divide by something too large, rather than too small.
		if ($smaller > $max_arg){		//If we have just gone out of range, then we are right at the limit. We know the only possible
			$smaller=$max_arg;		//answers for the values are $max_arg and $max_length.
			$larger=$max_length;
		}else{
			$tries = array();
			for ($i=0;$i<100;$i++){		//make several attempts to do even better. $i=0 is the average best first-guess, but sometimes we might find a better factorisation by searching a little
				$s = $smaller + $i;
				$l = round ($product / $s);		//Calculate $larger, and the remainder.
				$remainder = fmod ($product, $s);
				if ( ($l * $s) > $max_value){  		//if we went too high,  and that took the new result out of range, stop  [X]
					break;
				}
				$tries[$i] = $remainder;
				if (($l * $s ) == $product){		//If we got there exactly, stop.
					break;
				}
			}
			asort($tries);				//Find the value of $i with the lowest remainder.
			$best = reset($tries);			//Get the value of the optimal remainder
			foreach ($tries as $key => $value){	//Find all the $i that match this remainder.
				if ($value == $best){
					$keys[] = $key;
				}
			}
			sort($keys);				//Of the optimal values, choose the smallest.
			$i = $keys[0];

			$smaller = $smaller + $i;
			$larger = round ($product / $smaller);

			if ($larger * $smaller > $product){	//It is possible that we overshot at [X] above. If so, decrement $larger.
				$larger -= 1;
			}
		}

		$actual= ($smaller * $larger);  	//Calculate the actual result of multiplying $larger*$smaller; then find the actual and fractional errors.
		$error= ($actual - $product);
		$fractional_error=abs($error / $product);

		$result["larger"]=$larger;			// Range: [0,PB_DELAY_32BIT]
		$result["smaller"]=$smaller;			//Range: [1,PB_ARG_20BIT]. NOTE: if ARG is 1, then long_delay is not happy. Revert to CONT.
		$result["actual"]=$actual;			// Range: [0, PB_DELAY_32BIT * PB_ARG_20BIT]
		$result["error"]=$error;			//may be negative or positive (or zero).
		$result["fractional_error"]=$fractional_error;	//float, always pos., between 0 and 1.

	}
	return $result;  //An array, containing integer_floats ($larger,$smaller,$abs_error) and a fractional float ($frac_error).
}

//--------------------------------------------------------------------------------------------------------------
//DEAL WITH EACH LINE, ONE PART AT A TIME:
$this_arg_is_label=array();			//This is used to hold the ARG's string (or false) - so that we can sanity-check it, once we know the opcode.
$this_length_token='';				//Used to hold the most recent LENGTH's string (i.e. the token, before conversion.)
$this_length_has_units=false;			//Does the current length have units, i.e. a suffix? True for eg '10_ns'; false for '10'.
$loops_endloop_count=0;				//To check that loops and endloops are matched - at least in number!
$loopstart_addresscheck_stack=array();		//Used to hold the addresses of the starts of loops. Used to check that loops and endloops (probably) nest correctly.

debug_print_msg("\n################### ${BLUE}PARSING LINES, CONVERTING STRINGS (OUTPUT/LENGTH/OPCODE/ARG)${NORM} ######################################");
for ($i=0;$i<$number_of_code_lines;$i++){	//Iterate over the entire input, linewise, parsing the tokens.

//--------------------------------------------------------------------------------------------------------------
//OPCODES ARRAY.

	if (!preg_match("/^($RE_OPCODES)\$/i",$opcodes_array[$i]) ){	//Check opcode is legal. (Case-insensitive)
		if (is_numeric($opcodes_array[$i])){	//[This will also occur if the fields become misaligned. If opcode isn't a string, then emit extra warning.]
			print_warning("opcode '$opcodes_array[$i]' at line $i is not a string. This means the fields within the line are probably misaligned. Error ".at_line($i));  //shouldn't happen!
		}
		fatal_error("invalid opcode '$opcodes_array[$i]' ".at_line($i));
	}

	switch(strtolower($opcodes_array[$i])){		//Parse the opcodes. Convert the opcode name, if it is one of spincore's, or an abbreviation, or an astromed equivalent.
		case 'cont':				//Opcodes are case-insensitive - this allows eg "LOOP" to stand out from "cont".
		case 'continue':	//[spincore's]
			$opcodes_array[$i]="cont";
			break;
		case 'longdelay':
		case 'long_delay':	//[spincore's]
		case 'ld':		//[abbrev]
			$opcodes_array[$i]="longdelay";
			break;
		case 'loop':
			$opcodes_array[$i]="loop";
			break;
		case 'endloop':
		case 'end_loop':	//[spincore's]
		case 'test_end_loop':	//[astromed]
		case 'testendloop':
		case 'tel':
			$opcodes_array[$i]="endloop";
			break;
		case 'goto':
		case 'branch':		//[spincore's]
			$opcodes_array[$i]="goto";
			break;
		case 'call':
		case 'jsr':		//[spincore's]
			$opcodes_array[$i]="call";
			break;
		case 'return':
		case 'rts':		//[spincore's]
		case 'rtn':		//[abbrev]
			$opcodes_array[$i]="return";
			break;
		case 'wait':
			$opcodes_array[$i]="wait";
			break;
		case 'stop':
			$opcodes_array[$i]="stop";
			break;
		case 'debug':
			$opcodes_array[$i]="debug";  //treated as "cont" by pb_prog, used for vgrep.
			break;
		case 'mark':
			$opcodes_array[$i]="mark";  //treated as "cont" by pb_prog, used by simulator.
			break;			
		case 'nop':	//NOP isn't valid by here! It should have *already* been replaced by CONT(same,min_delay). This "can't happen".
			fatal_error("NOP pseudo-opcode should have already been substituted internally by CONT(same,min_delay). Error ".at_line($i));
			break;
		case 'never':
			$opcodes_array[$i]="never";  //treated as "cont" by pb_prog, used to denote "dead" code.
			break;
		default:	//Invalid opcode. [Should have already detected this by regex above]
			fatal_error("Unknown opcode '$opcodes_array[$i]' ".at_line($i));
	}

//--------------------------------------------------------------------------------------------------------------
//LABELS ARRAY
	if ($labels_array[$i]!=''){		//Parse (i.e. validate) the labels array. Important: labels_array is already complete, so that ARG[$i] can look up LABEL[$j] where $j > $i.
		if (!preg_match("/^$RE_WORD\$/",$labels_array[$i])){	//So this step isn't really necessary, execpt to enforce the requirement that a label can only contain the characters a-z, 0-9, '-' and '_', and must begin with a letter.
			fatal_error("invalid format for label '$labels_array[$i]'. Labels must begin with a letter, contain only '$RE_WORDCHAR', and end with a colon. Error at ".at_line($i));
		}
		$previous_instance=array_search($labels_array[$i],$labels_array);  //Check we haven't got duplicate (non-null) labels. Search the array for a previous instance of the same label.
		if ($previous_instance < $i){	//If the line no. of the first occurrence of this label is less than the current line ($i), then we have a dup.
			fatal_error("cannot have duplicate label '$labels_array[$i]' both ".at_line($previous_instance)."\n and ".at_line($i));
		}
		if (preg_match ("/^$RE_RESERVED_WORDS\$/", $labels_array[$i])){ //Labels can't be keywords. Technically, "macro" is a keyword, and "macro:" is a label. But that would be really daft.
			fatal_error("Label names shouldn't be keywords! You tried to use '$labels_array[$i]' as a label, which is probably a mistake."); //
		}
		//ARG will later be looked up (if it is a label) in $labels_array to retrieve an address.
	}

//--------------------------------------------------------------------------------------------------------------
//OUTPUTS ARRAY
	if ($outputs_array[$i]==="-"){		//Parse the outputs array.
		$outputs_array[$i]=$NA;		//"-" may be occasionally permitted, as N/A.
	}else{
		if ( (strpos($outputs_array[$i],'bit')!==false) or (strpos($outputs_array[$i],'same')!==false) ){ //If the output is "same" or a "bit_*" change, then it requires careful parsing. (may also have '@' prepended).
			$outputs_array[$i]=parse_bitwise($outputs_array[$i],$i); 		     //Args: this string, linenumber. PREVIOUS value is derived from $linenumber by parse_bitwise().
		}elseif ( (stripos($outputs_array[$i],'bit')!==false) or (stripos($outputs_array[$i],'same')!==false) ){ //Wrong-case
			fatal_error("The use of 'bit' and 'same' is case-sensitive. Error ".at_line($i));
		}else{
			$outputs_array[$i]=parse_expr($outputs_array[$i],$i,"OUTPUT");	   	  //Otherwise, it's just a number. Convert each element of $outputs_array to its decimal value.
		}
		if (($outputs_array[$i] < 0) or ($outputs_array[$i] > $HEADER["PB_OUTPUTS_24BIT"])){	//Check it is in allowed range of 0 -> 24_BIT (i.e. [0, 0xFFFFFF])
			fatal_error("output value '$outputs_array[$i]' is not in range [0,$HEADER[PB_OUTPUTS_24BIT]] (i.e. [0,$string_24_BIT]) ".at_line($i));
		}
	}					//The value is sanity checked some more below, depending on the opcode.

//--------------------------------------------------------------------------------------------------------------
//ARGS ARRAY
	if (($args_array[$i]==="") or ($args_array[$i]==="-")){			//Parse the arguments array.
		$args_array[$i]=$NA;						//If it is '', or '-', then it is meant to be ignored. Use $NA, (i.e "-"),
		$this_arg_is_label[$i]=false;					//  which pb_prog will treat as N/A (although 0 would also be accepted).
	}elseif (($args_array[$i]===$AUTO) and ($opcodes_array[$i]=='longdelay')){ //If it is 'auto' AND opcode is 'longdelay', then it will be parsed later by DWIM_FIX.
		$args_array[$i]=$AUTO;						//  leave it alone for now.
		$this_arg_is_label[$i]=false;
	}elseif (preg_match("/$AUTO/i",$args_array[$i])){			// AUTO out of context. or wrong case.
		fatal_error("Mis-use of '$AUTO' by ARG '$args_array[$i]'. 'auto' must be lower-case, and paired with opcode 'longdelay'. Error ".at_line($i));
	}elseif (!preg_match("/^[a-zA-Z]/",$args_array[$i])){			//If it is not a string, try parsing as a number, or numeric address.
		$args_array[$i]=parse_expr($args_array[$i],$i,"ARG");
		$this_arg_is_label[$i]=false;					//Used later, by sanity check. Either false (if the arg is numeric), or the label (string) if it's a string label.
	}else{
		$this_arg_is_label[$i]=$args_array[$i];				//Otherwise, it is a string label, so look it up in $labels_array.
		$redundant_labels=array_diff($redundant_labels,array($args_array[$i]));  //Once we have looked up a label, remove it from the list of (possible) redundant labels.
		$address=array_search($args_array[$i],$labels_array);
		if ($address===false){						//If the label cannot be found, this is fatal!
			$trailing_colon = (substr($args_array[$i],-1)==':')? "(Tip: when jumping to a label, don't end the label-name with a colon).":'';
			fatal_error("non-existent label ARG = '$args_array[$i]'. $trailing_colon Error ".at_line($i));
		}
		$args_array[$i]=$address;					//The value is sanity checked below, depending on the opcode.
	}

//--------------------------------------------------------------------------------------------------------------
//LENGTHS ARRAY
	$this_length_token=$lengths_array[$i];		//Parse the lengths array. Convert each element of $lengths_array to an integer float. (except $NA)
	if ($lengths_array[$i]==="-"){
		$lengths_array[$i]=$NA;			//"-" may be occasionally permitted, as N/A. (string, not float).
	}else if ($lengths_array[$i]=== $SHORT){	//The keyword 'short' means "the shortest (legal) value".
		if ($opcodes_array[$i]=='wait'){				//wait =>  PB_MINIMUM_WAIT_DELAY
			$lengths_array[$i]= $HEADER["PB_MINIMUM_WAIT_DELAY"];
		}else if (array_key_exists ($i+1, $opcodes_array) and $opcodes_array[$i+1]=='stop'){			//PRECEEDING stop =>  (PB_MINIMUM_DELAY + PB_BUG_PRESTOP_EXTRADELAY).
			$lengths_array[$i]=$HEADER["PB_MINIMUM_DELAY"]+$HEADER["PB_BUG_PRESTOP_EXTRADELAY"];
		}else{								//normally => PB_MINIMUM_DELAY
			$lengths_array[$i]=$HEADER["PB_MINIMUM_DELAY"];
		}
		vdebug_print_msg("Line $i: evaluated 'short' as length $lengths_array[$i].");
	}else{
		if (preg_match("/$RE_UNITS/",$lengths_array[$i])){ //Does the length have units? (i.e. does it have any non-numeric part other than operators ?)
			$this_length_has_units=true;		   //Needed for subsequent check and notice, to warn of possible stupidity.
		}
		$lengths_array[$i]=parse_expr($lengths_array[$i],$i,"LENGTH");   //This function *doesn't* check that the length is within the upper limit. We check later, after DWIM.
	}

	//NOTE: we do NOT account here for the fixed, inbuilt 3-cycle delay of the pulseblaster. This is now done AFTER the .vliw file, by pb_prog.
	//NEITHER do we account for the additional latency of a WAIT instruction. This is also done by pb_prog.
	//Thus, do NOT do this (here): $lengths_array[$i] -= $HEADER["PB_INTERNAL_LATENCY"];	//Account for the fixed delay of 3 cycles used by the pulseblaster.
 	//and don't do THIS here either: if ($opcodes_array[$i]=="wait"){ $lengths_array[$i] = $lengths_array[$i] +  $HEADER["PB_INTERNAL_LATENCY"] - $HEADER["PB_WAIT_LATENCY"];

	//The value is sanity-checked below, depending on the opcode.

//--------------------------------------------------------------------------------------------------------------
//"DO WHAT I MEAN".  Some arbitrary limits are imposed, and we can often fix them.
//	#1 Longdelay with arg==auto	-> we can calculate the arg
//	#2 Longdelay with arg==1	-> convert into a CONT.
//	#3 Cont with length too large	-> convert into a longdelay.
//	#4 Loop with arg == 0		-> convert into a goto (jumps one past endloop) - done below
//If the change results in any uncertainty, it causes a Warning. If the change may be made exactly (eg Longdelay(1) -> Cont), a Notice is emitted.
	if($USE_DWIM_FIX){ //May be disabled in Configuration section.

		//#1. longdelay with arg=auto - automatically calculate ARG and LENGTH as factors.
		if ($opcodes_array[$i]=='longdelay'){
			//If a longdelay has ARG=auto or ARG='-', then obviously, we have to calculate a suitable Length/Arg pair. This is a deliberate feature!
			if (($args_array[$i]==="$AUTO") or ($args_array[$i]==="$NA")){   //[Technically, '-' to means "intentionally left blank", whereas 'auto' means "please do it for me".]
				$orig=$lengths_array[$i];
				$suggestion=estimate_factors($lengths_array[$i],$i); //Estimate two factors for LEN/ARG.
				$lengths_array[$i]=$suggestion["larger"];
				$args_array[$i]= $suggestion["smaller"]; //Notice (rather than Warning or Debug) since this is a feature.
				print_notice("Calculating values for (ARG=auto) LongDelay '$orig' at line $i: Length='$lengths_array[$i]', ARG='$args_array[$i]', fractional_error='$suggestion[fractional_error]'. Notice ".at_line($i));

				if (($args_array[$i]==1) and ($HEADER["PB_LONGDELAY_ARG_MIN"]>1)){  //In case ARG is 1, (and unless PB_LONGDELAY_ARG_MIN ceases to be 2 in future versions)
					$opcodes_array[$i]='cont';				    //we need to demote this longdelay back to a CONT.
					$args_array[$i]=$NA;
					debug_print_msg("('Demoted' this Not-So-Long Delay, which had ARG=1, to a Cont ".at_line($i)); //Just a debug message, since it is a feature, and it has no rounding error.
					$comments_array[$i].="//$PARSER_CMT calculated factor from auto longdelay; demoted to cont."; //Append '//PARSER: calculated factor from auto longdelay; demoted to cont.'
				}else{
					$comments_array[$i].="//$PARSER_CMT calculated factors from auto longdelay ('$this_length_token')."; //Append 'PARSER: calculated factors from auto longdelay ('XXX').'
				}

		//#2. Not-so-Long-Delay (with ARG==1): demote to Cont.
			}else if (($args_array[$i]==1) and ($HEADER["PB_LONGDELAY_ARG_MIN"]>1)){  //If ARG==1, this is a "Not-So-Long-Delay"! We have to demote it to a cont, or
				$opcodes_array[$i]='cont';					  //an error will occur. This has no effect on accuracy, so just gets a notice.
				$args_array[$i]=$NA;						  //(If a future version ceases to require $HEADER["PB_LONGDELAY_ARG_MIN"] > 1, then do nothing).
				print_notice("'Demoting' Not-So-LongDelay, which has ARG=1, to a CONT ".at_line($i));
				$comments_array[$i].="//$PARSER_CMT demoted Not-So-LongDelay with ARG=1 to Cont."; //Append 'PARSER: demoted Not-So-Long Delay with ARG=1 to Cont.'

			}else if (($args_array[$i]>1) and ($this_length_has_units==true)){	//Reminder: "50s * 10" is of course 500s !   It would be so easy to mistakenly assume that 50_s has units of seconds!
				print_notice("longdelay is specified with units, as '$this_length_token'. Remember that this is yet to be multiplied by the ARG '$args_array[$i]'. (Hint: use 'auto'.) Notice ".at_line($i));  //Perhaps this notice is superfluous?
			}
		}

		//#3. Over-long Cont: promote to Longdelay.
		if (($opcodes_array[$i]=='cont') and  ($lengths_array[$i] > $HEADER["PB_DELAY_32BIT"]) ){  	//If a Cont is too long, then 'Promote' it automatically to a LongDelay.
			if ($args_array[$i]!==$NA){								//This gets a warning, since there is some possible small rounding error.
				print_warning("ignoring superfluous argument '$args_array[$i]' to opcode '$opcodes_array[$i]' ".at_line($i));
				$args_array[$i]=$NA;								//Must also check first that CONT doesn't have a (superfluous) ARG.
			}
			$orig=$lengths_array[$i];
			$suggestion=estimate_factors($lengths_array[$i],$i);
			$lengths_array[$i]=$suggestion["larger"];
			$args_array[$i]= $suggestion["smaller"];
			$opcodes_array[$i]='longdelay';

			if ($suggestion['fractional_error']==0){	//If exact, print debug. If tiny error print NOTICE. If inexact, print WARNING.
				debug_print_msg("'Promoting' over-long Cont '$orig' to LongDelay. New Length='$suggestion[larger]', ARG='$args_array[$i]', fractional_error='$suggestion[fractional_error]'.");
			}elseif ($suggestion['fractional_error']<1E-6){
				print_notice("'Promoting' over-long Cont '$orig' to LongDelay. New Length='$suggestion[larger]', ARG='$args_array[$i]', fractional_error='$suggestion[fractional_error]'. Notice ".at_line($i));
			}else{
				print_warning("'Promoting' over-long Cont '$orig' to LongDelay. New Length='$suggestion[larger]', ARG='$args_array[$i]', fractional_error='$suggestion[fractional_error]'. Warning ".at_line($i));
			}
			$comments_array[$i].="$PARSER_CMT promoted over-long Cont (with length '$this_length_token') to LongDelay."; //Append by 'PARSER: promoted over-long Cont (with length 'XXX') to LongDelay.'
		}
		//Note in all cases, if promotion/demotion leaves the length/arg STILL out of range, it will be caught later by the sanity-checks.
	}
}

		//#4. Loop(0) - jump right past the loop.  (This DWIM looks at multiple lines; it's easier to do after the entire list has been parsed).
		//See loops.txt.  (Note: the alternative to goto would be some ghastly RE hackery above: consider nesting, possible perverse code flow, and what if "loop" has a label that is needed by more than the endloop.)
if($USE_DWIM_FIX){ 		 //May be disabled in Configuration section.
	for ($i=0;$i<$number_of_code_lines;$i++){	//Iterate again.

		if (($opcodes_array[$i] == "loop") and ($args_array[$i] == 0)){  //Loop (0). Jump past the entire loop, to endloop +1.
			$ldepth=1; $foundit = false;				//Find the matching (paired) endloop. Search foward. (if there are crazy code structures with perverse control flow, then too bad).
			$lstart = $i;
			$zl_adjacent = 0; $mid_nevers = array();
			for ($j=$i+1;$j<$number_of_code_lines;$j++){
				if ($opcodes_array[$j] == "endloop"){
					$ldepth--;
					if ($ldepth == 0){
						$foundit=true;
						$zl_adjacent++;
						if (($opcodes_array[$j+1] == "loop") and ($args_array[$j+1] == 0)){	//Once we found our paired endloop, check if the NEXT instruction is also a zeroloop.
							#echo "Next (adjacent) instruction is ALSO a zeroloop.\n";	//If so, keep on consuming instructions (important, else output[$i] will be wrong).
							$mid_nevers[]=$j; $mid_nevers[]=$j+1;
							$lstart = $j+1;
						}else{
							#echo "Next (adjacent) instruction is not a zeroloop.\n";
							if ($args_array[$j] != $lstart){	//Double-check that endloop's arg really does point to us.
								fatal_error("Zeroloop: endloop's arg ($args_array[$j]) doesn't point back to loop-start ($lstart). Error ".at_line($j));
							}elseif (!$opcodes_array[$j+1]){	//Will Goto $j+1; check it exists.
								fatal_error("Zeroloop: want to goto one past the corresponding endloop. i.e. to instruction at ".($j+1).". But the endloop is the final instruction. Error ".at_line($i));
							}
							break;
						}
					}
				}elseif ($opcodes_array[$j] == "loop"){
					$ldepth++;
				}
			}
			if ($foundit == false){			//check we got it.
				fatal_error("Zeroloop: couldn't find subsequent matching endloop to pair with loop(0). Error ".at_line($i));
			}

			$zl_start = $i;		//addresses of the start, end, and destination instructions.
			$zl_end   = $j;
			$zl_dest  = $j+1;
			
			print_notice("Zeroloop: Converted Loop(0), at line $zl_start (matched with Endloop at line $zl_end) [with $zl_adjacent adjacent ZLs], to Goto(line $zl_dest). Notice ".at_line($zl_start));
			
			//The Loop instr (at $zl_start).
			$opcodes_array[$zl_start]	= "goto";		//Change the "loop" instruction into a goto. Jump one past the endloop.
			$args_array[$zl_start]		= $zl_dest;
			$outputs_array[$zl_start]	= $outputs_array[$zl_dest];	//A loop(0) has no effect. So set the outputs to what they will be when we land.
			$lengths_array[$zl_start]	= $HEADER["PB_MINIMUM_DELAY"];  //Also, shouldn't wait. Set to short, and (if possible), steal that back from the landing site..
			$comments_array[$zl_start]	.= "//$PARSER_CMT Convert zeroloop_$zl_adjacent to GOTO. "; 
			$lines_array[$zl_start] 	.= "//$PARSER_CMT Convert zeroloop_$zl_adjacent to GOTO.";
			$this_arg_is_label[$zl_start]   = "dwim_loop0";			//Prevent the Bug bait warning about arg not being a label. It's deliberate.

			//The Endloop instr (at $zl_end)
			$opcodes_array[$zl_end] 	=  "never";		//Change the "endloop" instruction into a "never".  (pb_asm treats this as a "cont").
			$args_array[$zl_end] 		=  $NA;			//It won't get executed; this just keeps subsequent loop/endloop pairing-checks happy.
			$comments_array[$zl_end] 	.= "//$PARSER_CMT Zeroloop: convert skipped EL to NEVER.";
			$lines_array[$zl_end] 		.= "//$PARSER_CMT Zeroloop: convert skipped EL to NEVER.";  //Also put into lines_array, useful for identify_line() when simulator warns about 'redundant instructions'

			//The Dest instr
			$dest_instr = $opcodes_array[$zl_dest];	//Steal back the "short" used by the goto, if we can. If we can't steal it all, steal back as much as we can.
			if ( ($dest_instr == "cont") or ($dest_instr == "debug") or ($dest_instr == "mark") or ($dest_instr == "goto") or  ($dest_instr == "call") or ($dest_instr == "return") or ($dest_instr == "endloop") ){ //Safe to steal from.
				if ($lengths_array[$zl_dest] >= (2 * $HEADER["PB_MINIMUM_DELAY"])){//perfect
					$overtime = 0;
					$lengths_array[$zl_dest] -= $HEADER["PB_MINIMUM_DELAY"];
				}else{
					$overtime =  2 * $HEADER["PB_MINIMUM_DELAY"] - ($lengths_array[$zl_dest]);  //do our best
					print_warning("Imperfect zeroloop takes $overtime extra cycles. ZL at $zl_start wants $HEADER[PB_MINIMUM_DELAY] cy from instr $zl_dest (length ".$lengths_array[$zl_dest]."), which has too few. Warning ".at_line($zl_dest));
					$lengths_array[$zl_dest] = $HEADER["PB_MINIMUM_DELAY"];
				}
			}else{
				//Loop: Dangerous to steal from "loop", as we would alter the inside of the loop, as well as before it.
				//Longdelay: could do this, but need to handle ARG. Not usually useful anyway: 90ns vs 47 seconds!
				//Wait: would steal from after wakeup, so WRONG behaviour
				//Stop: can't take a length anyway (if it was overloaded, we have a cont by now)
				$overtime = $HEADER["PB_MINIMUM_DELAY"];
				print_warning("Imperfect zeroloop takes $overtime extra cycles: ZL at $zl_start wants $HEADER[PB_MINIMUM_DELAY] cy from instr $zl_dest ('$dest_instr'), but $dest_instr is theft-proof. Warning ".at_line($zl_dest));
			}
			$comments_array[$zl_dest] .= "//$PARSER_CMT Zeroloop at $zl_start jumped here, overtime $overtime.";

			//The Ones in the middle.  
			foreach ($mid_nevers as $k){
				$opcodes_array[$k] 	= "never";	//ensure that subsequent loop/endloop checks balance.			
				$args_array[$k]		= $NA;		//see zeroloop.pbsrc for a (hard) test.
			}
			for ($k=$zl_start+1; $k < $zl_end; $k++){
				$comments_array[$k] 	.= "//$PARSER_IBS zeroloop_skip"; 	//hint to simulator about redundant code warning.
				$lines_array[$k] 	.= "//$PARSER_IBS zeroloop_skip";  
			}

			$p1=strpos($lines_array[$zl_dest],$SAME);	//If dest line contains "same", (before a comment), warn
			$p2=strpos($lines_array[$zl_dest],'//');	//The parser does the right thing, but it probably isn't what the user expects..
			if ( ($p1!==false) and (($p2===false) OR (($p2!==false) and ($p1<$p2))) ){
				if (substr(trim($lines_array[$zl_dest]),0,1) == $SQUELCH_PREFIX){	//Prefix with '@' squelches this warning (except in DEBUG/SCREAM mode)
					if ($DEBUG or $SCREAM){  
						print_warning("Zeroloop dest: output at line $zl_dest is 'the same as' the 'previous' line, $zl_end, the endloop of the *skipped* loop-body. Correct, but ${RED}probably *not* what you intended${NORM}. Warning ".at_line($zl_dest));
					}else{
						$number_of_silences++;
					}
				}
			}
			
			$i= $j;  //Important - if we found a match, don't re-search within the same space.
		}
	}
}
//Now all lines have been parsed.

//--------------------------------------------------------------------------------------------------------------
debug_print_msg("\n################### ${BLUE}PARSING LINES: APPLY #SETs and SANITY CHECK ${NORM} ######################################################");
for ($i=0;$i<$number_of_code_lines;$i++){	//Iterate again, linewise doing sanity checks and , parsing the tokens, then sanity-checking the instructions.

//--------------------------------------------------------------------------------------------------------------
//Apply Settings. (these were parsed above in the #set section). Don't do this too early! In particular, we MUST have dealt with all the instances of SAME before we start to apply masks.
	if ($outputs_array[$i] !== "$NA"){					//Don't mangle a STOP opcode which has output == NA.
		if ($SETTINGS['OUTPUT_BIT_MASK'] !== false){
			$outputs_array[$i] &= $SETTINGS['OUTPUT_BIT_MASK'];	//The ordering of these 3 is defined, and does make a difference.
		}
		if ($SETTINGS['OUTPUT_BIT_SET'] !== false){
			$outputs_array[$i] |= $SETTINGS['OUTPUT_BIT_SET'];
		}
		if ($SETTINGS['OUTPUT_BIT_INVERT'] !== false){
			$outputs_array[$i] ^= $SETTINGS['OUTPUT_BIT_INVERT'];
		}
	}

//--------------------------------------------------------------------------------------------------------------
//SANITY CHECKS. Check that each instruction is valid, and self-consistent. Warn if an argument is unnecessary; error if it is missing or out of range.
	switch($opcodes_array[$i]){
		case 'cont':
		case 'debug':		//debug, mark, and never are treated as synonymous with "cont" by pb_asm.  So they should validate.
		case 'mark':
		case 'never':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($args_array[$i]!==$NA){
				if ($args_array[$i]==0){
					print_warning("ignoring superfluous argument '$args_array[$i]' to opcode '$opcodes_array[$i]' (should be explicitly '$NA') ".at_line($i));
					$args_array[$i]=$NA;
				}else{
					fatal_error("Opcode '$opcodes_array[$i]' does not take an argument. Argument '$args_array[$i]' is illegal (should be '$NA') ".at_line($i));
				}
			}
			break;

		case 'longdelay':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if (($args_array[$i]===$NA) or ($args_array[$i]< $HEADER["PB_LONGDELAY_ARG_MIN"]) or ($args_array[$i] > $HEADER["PB_ARG_20BIT"])){
				fatal_error("argument '$args_array[$i]' to opcode '$opcodes_array[$i]' is not a valid counter in range [$HEADER[PB_LONGDELAY_ARG_MIN],$HEADER[PB_ARG_20BIT]] (i.e. [$HEADER[PB_LONGDELAY_ARG_MIN],$string_20_BIT]) ".at_line($i));
			}	//Workaround: set $USE_DWIM_FIX to true.
			//NOTE: we do NOT account here for $HEADER["PB_BUG_LONGDELAY_OFFSET"]. This is now done AFTER the .vliw file, by pb_prog.
			//Thus, do NOT do this (here): $args_array[$i]-=$HEADER["PB_BUG_LONGDELAY_OFFSET"]; 	//Account for the the need to subtract 2 from the longdelay's arg, used by the pulseblaster.
			if ($this_arg_is_label[$i]!==false){ //Check that the arg is a number, not a label (since labels have been evaluated to addresses by now). This variable is set while parsing ARG.
				fatal_error("argument '$this_arg_is_label[$i]' is a label, (which evaluates, accidentally, to '$args_array[$i]'), not a number. Error ".at_line($i));
			}
			break;

		case 'loop':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if (($args_array[$i]===$NA) or ($args_array[$i] < $HEADER["PB_LOOP_ARG_MIN"]) or ($args_array[$i] > $HEADER["PB_ARG_20BIT"])){
				fatal_error("argument '$args_array[$i]' to opcode '$opcodes_array[$i]' is not a valid counter in range [$HEADER[PB_LOOP_ARG_MIN],$HEADER[PB_ARG_20BIT]] (i.e. [$HEADER[PB_LOOP_ARG_MIN],$string_20_BIT]) ".at_line($i));
			}
			//NOTE: we do NOT account here for $HEADER["PB_BUG_LOOP_OFFSET"]. This is now done AFTER the .vliw file, by pb_prog.
			//Thus, do NOT do this (here): $args_array[$i]-=$HEADER["PB_BUG_LOOP_OFFSET"]; 	//Account for the the daft 1-based loop counter used by the pulseblaster.
			if ($this_arg_is_label[$i]!==false){
				fatal_error("argument '$this_arg_is_label[$i]' is a label, (which evaluates, accidentally, to '$args_array[$i]'), not a number. Error ".at_line($i));
			}
			if ($labels_array[$i]==''){ //Loops must have labels. Otherwise, it will make is_destination() fail.  [However, this test is somewhat redundant, since we now also warn if ENDLOOP's ARG isn't a string label.]
				print_warning("loop instructions ought to have a label. Otherwise, ENDLOOP must have a numeric address, which is doing it the hard way, and will prevent the parser from spotting some types of errors. Warning ".at_line($i));
			}
			array_push($loopstart_addresscheck_stack,$i);	//Store the address of the start of loop, for later. Use stack, to verify correct nesting. See comment below.
			$loops_endloop_count++;  //number of loop instructions.
			break;

		case 'endloop':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if (($args_array[$i]===$NA) or ($args_array[$i]< 0) or ($args_array[$i]>=$number_of_code_lines)){ //Note: if the arg was a string, we have already checked that it matches a label.
				fatal_error("argument '$args_array[$i]' to opcode '$opcodes_array[$i]' is not a valid address between [0,".($number_of_code_lines-1)."] ".at_line($i));
			}
			if ($this_arg_is_label[$i]===false){  //Warn if we used a numeric address, not a label. The parser will now fail to spot warnings related to is_destination().  (should this be fatal; it's legal, but such awful style)
				if ($opcode_macro_used){
					fatal_error  ("argument '$args_array[$i]' is a number, not a label. The source contains opcode-macros (e.g. '__goto or '__loop'); literal line numbers are incorrect. Error ".at_line($i));
				}else{
					print_warning("Bug bait: argument '$args_array[$i]' is a number, not a label. This is doing it the hard way, and will prevent the parser from spotting some types of errors. Warning ".at_line($i));
				}
			}
			$dest_instr = $opcodes_array[$args_array[$i]];				//Check that ARG does point back to a loop instruction.
			if ($dest_instr != "loop"){
				fatal_error("Endloop instruction '$i' has (evaluated) arg '$args_array[$i]'; this points to a '$dest_instr', which is not a loop. Error ".at_line($i));
			}
			$loop_start_addr=array_pop($loopstart_addresscheck_stack); 		//Verify that ARG matches the address of the corresponding correctly-nested LOOP instruction. Use a stack to verify correct nesting. However, SOME valid programs
			if ($loop_start_addr===NULL){						//will fail this test, therefore we must only warn, and not fatal_error. See doc/simulation.txt -> Note 1 for more
				print_warning("nth endloop occurred before the nth loop. This is most probably an error, but *might* be deliberate. Run the simulator with '-s' to check for certain. Warning ".at_line($i));
			}else if ($loop_start_addr!==$args_array[$i]) {				//This test is done rigorously by the simulator, where there is also a more detailed explanation of loops.  DOCREFERENCE: LOOP-CHECK.
				print_warning("probable mis-nested loop: argument '$args_array[$i]' (label '$this_arg_is_label[$i]') to opcode '$opcodes_array[$i]' is not the address of the expected corresponding ".
					    "LOOP instruction (i.e. '$loop_start_addr'). This is most probably an error, but *might* be deliberate. Run the simulator with '-s' to check for certain. Warning ".at_line($i));
			}
			$loops_endloop_count--;  //Later check, to cover some of the cases where we should have had a fatal_error, but just warned about mis-nesting, since we weren't sure.
			break;

		case 'goto':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if (($args_array[$i]===$NA) or ($args_array[$i]< 0) or ($args_array[$i]>=$number_of_code_lines)){ //Note: if the arg was a string, we have already checked that it matches a label.
				fatal_error("argument '$args_array[$i]' to opcode '$opcodes_array[$i]' is not a valid address between [0,".($number_of_code_lines-1)."] ".at_line($i));
			}
			if ($this_arg_is_label[$i]===false){  //should we just make this fatal?
				if ($opcode_macro_used){
					fatal_error  ("argument '$args_array[$i]' is a number, not a label. The source contains opcode-macros (e.g. '__goto or '__loop'); literal line numbers are incorrect. Error ".at_line($i));
				}else{
					print_warning("Bug bait: argument '$args_array[$i]' is a number, not a label. This is doing it the hard way, and will prevent the parser from spotting some types of errors. Warning ".at_line($i));
				}
			}
			if ($args_array[$i]==$i){
				print_warning("GOTO is going to itself. Should probably use 'STOP' instruction instead. Warning ".at_line($i));
			}
			break;

		case 'call':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if (($args_array[$i]===$NA) or ($args_array[$i]< 0) or ($args_array[$i]>=$number_of_code_lines)){ //Note: if the arg was a string, we have already checked that it matches a label.
				fatal_error("argument '$args_array[$i]' to opcode '$opcodes_array[$i]' is not a valid address between [0,".($number_of_code_lines-1)."] ".at_line($i));
			}
			if ($this_arg_is_label[$i]===false){  //consider making this always fatal? 
				if ($opcode_macro_used){
					fatal_error  ("argument '$args_array[$i]' is a number, not a label. The source contains opcode-macros (e.g. '__goto or '__loop'); literal line numbers are incorrect. Error ".at_line($i));
				}else{
					print_warning("Bug bait: argument '$args_array[$i]' is a number, not a label. This is doing it the hard way, and will prevent the parser from spotting some types of errors. Warning ".at_line($i));
				}
			}
			break;

		case 'return':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($args_array[$i]!==$NA){
				if ($args_array[$i]==0){
					print_warning("ignoring superfluous argument '$args_array[$i]' to opcode '$opcodes_array[$i]' (should be explicitly '$NA') ".at_line($i));
					$args_array[$i]=$NA;
				}else{
					fatal_error("Opcode '$opcodes_array[$i]' does not take an argument. Argument '$args_array[$i]' is illegal (should be '$NA') ".at_line($i));
				}
			}
			break;

		case 'wait':
			if ($outputs_array[$i]===$NA){
				fatal_error("output value is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i]===$NA){
				fatal_error("length is required for opcode '$opcodes_array[$i]' ".at_line($i));
			}
			if ($lengths_array[$i] < $HEADER["PB_MINIMUM_WAIT_DELAY"]) {	//WAIT instructions have a minimum length which is longer than usual.
				fatal_error("length '".sprintflx($lengths_array[$i])."' for a WAIT instruction is less than PB_MINIMUM_WAIT_DELAY (i.e. $HEADER[PB_MINIMUM_WAIT_DELAY]) ".at_line($i));
			}
			if (($i==0) and ($HEADER["PB_BUG_WAIT_NOTFIRST"])){		//WAIT may not be the first instruction.
				fatal_error("the first instruction may not be a WAIT instruction. Error ".at_line($i));
			}
			if (($i==1) and ($HEADER["PB_BUG_WAIT_MINFIRSTDELAY"] > ($lengths_array[$i-1])) ){
				fatal_error("when WAIT is the 2nd instruction, the previous length must be at least PB_BUG_WAIT_MINFIRSTDELAY (i.e. $HEADER[PB_BUG_WAIT_MINFIRSTDELAY]), but it was only '".$lengths_array[$i-1]."'. Error ".at_line($i));
			}
			if ($args_array[$i]!==$NA){
				if ($args_array[$i]==0){
					print_warning("ignoring superfluous argument '$args_array[$i]' to opcode '$opcodes_array[$i]' (should be explicitly '$NA') ".at_line($i));
					$args_array[$i]=$NA;
				}else{
					fatal_error("Opcode '$opcodes_array[$i]' does not take an argument. Argument '$args_array[$i]' is illegal (should be '$NA') ".at_line($i));
				}
			}
			break;

		case 'stop':
			if ($outputs_array[$i]!==$NA){	//Stop opcodes ignore the value of outputs, leaving the previous state.
				if ($outputs_array[$i]==0){
					print_warning("ignoring superfluous output '$outputs_array[$i]' to opcode '$opcodes_array[$i]' (should be explicitly '$NA') ".at_line($i));
					$outputs_array[$i]=$NA;
				}else{
					fatal_error("Opcode '$opcodes_array[$i]' does not take an output. Output '$outputs_array[$i]' is illegal (should be '$NA') ".at_line($i));
				}
			}
			if ($lengths_array[$i]!==$NA){	//Stop opcodes don't have any meaning for length.
				if ($lengths_array[$i]==0){
					print_warning("ignoring superfluous length '$lengths_array[$i]' to opcode '$opcodes_array[$i]' (should be explicitly '$NA') ".at_line($i));
					$lengths_array[$i]=$NA;
				}else{
					fatal_error("Opcode '$opcodes_array[$i]' does not take a length. Length '$lengths_array[$i]' is illegal (should be '$NA') ".at_line($i));
				}
			}
			if ($args_array[$i]!==$NA){
				if ($args_array[$i]==0){
					print_warning("ignoring superfluous argument '$args_array[$i]' to opcode '$opcodes_array[$i]' (should be explicitly '$NA') ".at_line($i));
					$args_array[$i]=$NA;
				}else{
					fatal_error("Opcode '$opcodes_array[$i]' does not take an argument. Argument '$args_array[$i]' is illegal (should be '$NA') ".at_line($i));
				}
			}
			if ($lengths_array[$i-1] < ($HEADER["PB_MINIMUM_DELAY"] + $HEADER["PB_BUG_PRESTOP_EXTRADELAY"])){  //Instruction *PRECEEDING* STOP must be long enough.
				fatal_error("the instruction *preceding* opcode '$opcodes_array[$i]' must have length > PB_MINIMUM_DELAY + PB_BUG_PRESTOP_EXTRADELAY (i.e. ".($HEADER["PB_MINIMUM_DELAY"] + $HEADER["PB_BUG_PRESTOP_EXTRADELAY"])."), but it is '".sprintflx($lengths_array[$i-1])."'. Error ".at_line($i));
			}
			if (is_destination($i)){	//If STOP is a destination, then we can no longer be certain that the previous check for preceding instruction length was valid. So don't do it!.
				fatal_error("STOP opcode must NOT be a destination, i.e. it must not have a label, nor be preceeded immediately by a subroutine CALL. This could cause trouble; please insert a preceding CONT, and label that instead. Error ".at_line($i));
			}
			break;
		default:
			fatal_error("this can't happen. Unknown opcode '$opcodes_array[$i]'. Parser at line ".__LINE__.".\n");
	}

//Check that lengths are within range  MINDELAY -> MAXDELAY (i.e. [9, 0xFFFFFFFF]).   //Partial workaround: set $USE_DWIM_FIX to true.
	if ($opcodes_array[$i]=="stop"){
		//We already checked that length is $NA; do nothing.
	}else{
		if  ($lengths_array[$i] < $HEADER["PB_MINIMUM_DELAY"]){		//Check that length >= MINDELAY.
			fatal_error("length '$lengths_array[$i]' is too small. PulseBlaster requires at least $HEADER[PB_MINIMUM_DELAY] ".at_line($i));
		}else if ($lengths_array[$i] > $HEADER["PB_DELAY_32BIT"]){	//Check that length <= MAXDELAY.
			//This is a fatal error. But try to be helpful first. Suggest using long_delay (unless we already are), and some values that might work.
			if ($opcodes_array[$i]=="longdelay"){
				print_warning("Length '$lengths_array[$i]' is too large for the $opcodes_array[$i] length. Perhaps try some different factors for LENGTH and ARG in this long_delay.");
			}else{
				if ($lengths_array[$i] < ($HEADER["PB_DELAY_32BIT"] * $HEADER["PB_ARG_20BIT"])){
					print_warning("Delay length '$lengths_array[$i]' is too large for a $opcodes_array[$i] instruction. Use long_delay instead.");
					$suggestion=estimate_factors($lengths_array[$i],$i);  //Make a helpful suggestion. Best guess, although doesn't know about exact factors.
					print_warning("Suggestion: try using long_delay with LENGTH='$suggestion[larger])' and ARG='$suggestion[smaller])'. This is '$suggestion[actual]' (an error of '$suggestion[error]', fractional-error '$suggestion[fractional_error]').");
				}else{
					print_warning("Delay length '$lengths_array[$i]' is too large for a $opcodes_array[$i]. It's even too large for a long_delay!");
				}
			}
			fatal_error("delay length '$lengths_array[$i]' is too large. It must be below $HEADER[PB_DELAY_32BIT] (i.e. $string_32_BIT) ".at_line($i));
		}
	}

//--------------------------------------------------------------------------------------------------------------
}   //END PARSE TOKENS INTO VALUES. By now, we have read each instruction in, converted it, and sanity-checked the values i.e. that each instruction is individually valid.

//--------------------------------------------------------------------------------------------------------------
//FURTHER CHECKS, now that every instruction has been parsed.
if ($number_of_code_lines > $HEADER["PB_MEMORY"]){  //Check that the code will fit into memory for the pulseblaster. [Both of these variables are 1-based.]
	fatal_error("there are too many lines of code ($number_of_code_lines) to fit into this pulseblaster (capacity: $HEADER[PB_MEMORY] instructions).");
}
if ($loops_endloop_count!=0){		//Check that the number of loop and endloop instructions match. (endloop may occur before its loop, so we couldn't fatal_error earlier).
	fatal_error("there are not an equal number of 'loop' and 'endloop' instructions! There are '$loops_endloop_count' more loops than there are endloops.");
}

$count=0; $list="";	//Warn about redundant labels. Redundant labels are (slightly) harmful, because they cause false positives for is_destination(), thereby triggering other warnings.
foreach ($redundant_labels as $key => $value){
	if ($value){	//labels_array has keys for every $i, most of which have empty values. $redundant_labels still has all these keys, but hopefully no non-empty values.
		$list.="\tADDRESS: ".str_pad($key, 6). " LABEL: $value\n";
		$count++;
	}
}
if ($count > 0){
	print_notice("There ". ( ($count == 1) ? "is 1 redundant label which is" : "are $count redundant labels which are" ). " never de-referenced:\n$list");
}

//--------------------------------------------------------------------------------------------------------------
$parser_run_time = round ((microtime(true) - $parser_start_time), 2);
//END OF PROCESSING.

//--------------------------------------------------------------------------------------------------------------
//Functions used by the simulation below.

function print_status_header(){    //Print header, as used by print_status() when $SIMULATION_VIRTUAL_LEDS=true
	global $SIMULATION_USE_KEYPRESSES, $SIMULATION_VERY_TERSE;
	global $AMBER, $NORM;
	$leading_space = $keypress_title = false;
	if ($SIMULATION_USE_KEYPRESSES){	//If we're watching for keypresses (other than just Q), print a title over the place where we present the prompt.
		$keypress_title='STEP+=?';	//and indent by an extra space, so that if the user puts in extra (queued) keypresses, these show up distinctly in the far LHS.
		$leading_space=' ';
	}
	if (!$SIMULATION_VERY_TERSE){
		print_msg("\n${AMBER}${leading_space}STEP        ELAPSED_TICKS     PC     :N     LD  SD  OPCODE   ARG      LENGTH      OUTPUT    23 22 21 20 19 18 17 16 15 14 13 12 11 10 9  8  7  6  5  4  3  2  1  0    $keypress_title${NORM}");
	}
}

function light_leds($output){			//Virtual LEDs.
	global $RED, $GREEN, $BLUE, $GREY, $NORM; //24 bits, RGB (8 bits each)
	global $NA;
	static $previous;
	$LED_ON='@';				//'@', '*', or '#'  seem to be the best way to denote ON.  '1' is harder to vgrep.
	$LED_OFF='.';				//'.' is probably best. Greyed-out, '+', or '-' might also work.
	$SPACE="  ";
	$LEDS = $is_na = false;
	if ($output!==$NA){			//Some opcodes (eg STOP) don't actually set the output at all, but leave the output as $NA.
		$previous=$output;		//If so, set output to  be the same as it was last time. Otherwise, save output for future.
	}else{
		$is_na = true;
		$output=$previous;		//IF $OUTPUT is "-", then the outputs remain from previous. Make this clearer by turning the LEDs grey.
		de_colour();			//colours off.
	}
	for ($bit=0;$bit<24;$bit++){		//coloured LEDs, on or off.
		if ($bit < 8){
			$led_on=$BLUE.$LED_ON.$NORM;
			$led_off=$BLUE.$LED_OFF.$NORM;
		}else if ($bit < 16){
			$led_on=$GREEN.$LED_ON.$NORM;
			$led_off=$GREEN.$LED_OFF.$NORM;
		}else{
			$led_on=$RED.$LED_ON.$NORM;
			$led_off=$RED.$LED_OFF.$NORM;
		}
		if (($output >> $bit) & 0x1){
			$LEDS=$led_on.$SPACE.$LEDS;
		}else{
			$LEDS=$led_off.$SPACE.$LEDS;
		}
	}
	if ($is_na){
		de_colour(true);		//colours back on.
		$LEDS="$GREY$LEDS$NORM";	//grey leds.
	}
	return($LEDS);
}

$PROMPT="#? ";		//Prompt used in -k mode. See also: $keypress_title.  Considered also:  '>: '     '> '     '#? '     '$ '     '?: '
$input_queue=array();	//This array holds the queued lines from STDIN, if any.

function print_status($tidied,$PC,$VISIT,$LD,$ELL,$SD,$INSTR,$ARG,$LENGTH,$OUTPUT,$STEP,$ELAPSED_TICKS){ //Print information on the current position. Current instruction line, register details, stack/loop depths, virtual LEDs

	global $SIMULATION_VERBOSE_REGISTERS;
	global $SIMULATION_VIRTUAL_LEDS;
	global $SIMULATION_PIANOROLL;
	global $SIMULATION_VERY_TERSE;
	global $SIMULATION_USE_KEYPRESSES;
	global $SIMULATION_BEEP;
	global $NA;
	global $BMAGENTA, $CYAN, $BLUE, $DRED, $AMBER, $NORM;
	global $HEADER;
	global $PROMPT_DEFAULT_VALUE_BS;
	global $PROMPT;
	global $ASCII_BACKSPACE, $ASCII_BELL;
	global $input_queue;

	if ($SIMULATION_VERY_TERSE){		//In the case of -g, when we need to generate a logfile, this is really useful.  (also helpful with -j to increase speed)
		output_r ("STEP: $STEP");  	//major speed improvement: about 8.2x faster.
		return;
	}

	if (count($input_queue) > 0){		//If a value has been queued for input, and we are about to retrieve it at the next call to read_keyboard_input(),
		$queued_input=$input_queue[0];  //print it here, as if it were typed afresh at the promot *this* time. [Note that $queued_input has a trailing newline.]
		$PROMPT.=$queued_input;

	}elseif ($PROMPT_DEFAULT_VALUE_BS){  	//Neat hack: print '1' followed by ASCII "Backspace". This has the effect of pre-filling the prompt with the character '1',
			$PROMPT.="1$ASCII_BACKSPACE";	//which is the default. Note that this character is NOT read back into STDIN, but an empty response is interpreted as '1'.
	}

	if ($SIMULATION_BEEP){
		$beep=$ASCII_BELL;	//PHP doesn't understand "\a", so use hex code for ASCII "Bell" character.
	}else{
		$beep='';
	}

	$len=strlen($ELAPSED_TICKS);		//The elapsed ticks field is long, so hard to visually grep. As we have no room for commas/spaces, highlight groups of 3 in different colours.
	$outstring='';				//Split ELAPSED_TICKS into 3s, starting at the RHS.
	for ($i=$len; $i > 0; $i = $i-3 ){	//The final 3 digits are black. Previous groups of 3 alternate blue/black. The first 1-3 are in red.
		if (strlen($ELAPSED_TICKS) > 2){	//Each time, chop off the final 3 characters, and put them into $end, keeping the rest in $ELAPSED_TICKS.
			$end3=substr($ELAPSED_TICKS,-3); //If there aren't 3 characters, put the 1 or 2 chars into $end3 instead.
			$ELAPSED_TICKS=substr($ELAPSED_TICKS,0,-3);
		}else{
			$end3=$ELAPSED_TICKS;
		}
		if ($i <= 3){
			$outstring=$DRED.$end3.$NORM.$outstring;
		}elseif ( ($len - $i) %2 != 0 ){
			$outstring=$BLUE.$end3.$NORM.$outstring;
		}else{
			$outstring=$end3.$outstring;
		}
	}
	$ELAPSED_TICKS=$outstring.str_repeat(' ',16 - $len);	//Note that this string contains formatting codes, which would trip up a potential '%-16s' in the sprintf() below.

	if ($ELL){		//Show ELL value next to loop depth. Denote the flag as "*"
		$ell='*';	//ELL set means "we just came from an endloop, which looped. So this loop instruction won't increase the loopdepth again
	}else{
		$ell=' ';
	}

	if ($SIMULATION_VERBOSE_REGISTERS){	//Print the current/next instruction line, register details, and stack/loop depths. (Show ELL too).
		$whatnext=sprintf("Now at instruction %-3d %-15s  Status: PC=${BMAGENTA}%-3d${NORM} LD=${BLUE}%d$ell${NORM} SD=${CYAN}%d${NORM}.   ${BLUE}Source:${NORM} $tidied", $PC, "($INSTR, $ARG).", "$PC,", "$LD,", $SD);
		print_msg($whatnext.$beep);		//[Opcode, Arg, PC, LD, SD, Source]

	}else if ($SIMULATION_VIRTUAL_LEDS){	//Print as much information as we can fit, about the current position, line, instruction, and virtual LEDS.
		//Note: max size of an int before overflow is approx 2x10^9. However, if we use strings, PHP will use doubles, and 10^14 is enough for our needs.
		$statusline=sprintf("%-10s  %s  ${BMAGENTA}%-5d${NORM}  :${BMAGENTA}%-4d${NORM}  ${BLUE}%d$ell${NORM}  ${CYAN}%d${NORM}   ${DRED}%-7.7s${NORM}  ${DRED}%-7s${NORM}  ",
				     $STEP, $ELAPSED_TICKS, $PC,  $VISIT,                $LD,               $SD,               $INSTR,               $ARG);
		$statusline.=sprintflx($LENGTH,10);
		if ($OUTPUT!==$NA){   //If output is $NA, sprintf would treat this as zero, which it isn't.
			$statusline.=sprintf("  0x%-6x  ",$OUTPUT);
		}else{
			$statusline.="  -         ";
		}
		$statusline.=light_leds($OUTPUT);

		if ($SIMULATION_USE_KEYPRESSES){	//If we're waiting for a keypress, use output_prompt, which has no trailing \n (nor \r), and waits for the user input.
			output_prompt(" ".$statusline.$beep,"  ".$PROMPT);  	 //Leading space, so as to reduce confusion if the user types in extra lines too early: these get queued, and show up on the far LHS.
		}elseif ($SIMULATION_PIANOROLL){				//[2 of the spaces before the PROMPT are trailing spaces from the LEDs.]
			output($statusline.$beep);	//Status line, in piano-roll mode (terminated by \n).  The header for this is as printed above.
		}else{
			output_r($statusline.$beep);	 //Status line, constantly updating and being overwritten (terminated by \r).
		}
	}
}

function write_output_fifo($output){		//Write output bytes to simulation output devices via fifo and proxy command (pb_parport-output). This effectively creates a "poor-man's pulseblaster".
	global $SIMULATION_OUTPUT_FIFO, $fp_sofifo;	//Filename and filehandle
	global $NA;
	if ($output === $NA){			//If output is $NA (-), do nothing.
		return;
	}
	if (!fprintf ($fp_sofifo, "0x%06x\n", $output) ){ //Format as asciihex: 0xffeedd\n   (The program pp_parport-output expects to parse this with strtoul() .)
		fatal_error("Simulation could not write output data ".sprintf("0x%06x\\n",$output)." to fifo '$SIMULATION_OUTPUT_FIFO'.\n");
	}
	fflush ($fp_sofifo);			//Force the output buffer to be flushed. (Probably not necessary, but may improve timing accuracy). NB pipes contain upto 64kB buffering.
}

function write_pbsim($comment=''){		//Print line for simulation replay log.  (See also sim_mark_time(), which can provide the (optional) $comment.).
	global $fp_pbsim;			//BUG: This function needs to know more than it should about the way the opcodes work.
	global $OUTPUT, $LENGTH, $INSTR, $ARG;
	global $HEADER;
	
	if ($comment){				//embed a comment, used for "Mark" feature.
		fwrite ($fp_pbsim, "//$comment\n");   
		return (false);
	}

	$multiplier = 1;
	$output = sprintf("0x%06x", $OUTPUT);  	//Output fixed width format. 24-bits.
	if ($INSTR == 'stop'){			//Stop instruction doesn't set outputs.
		fwrite ($fp_pbsim, "//Encountered STOP instruction. STOP doesn't change the outputs.\n");
		return (false);
	}elseif ($INSTR == 'wait'){
		fwrite ($fp_pbsim, "//Encountered WAIT instruction. Continuing. Inserting extra line with length=0, parser can detect this.\n");
		fwrite ($fp_pbsim, "$output\t0x0\n");		//Dummy extra copy. Parser may detect this, or it is safe to ignore. See pbsim.txt for more.
	}elseif ($INSTR == 'longdelay'){
		$multiplier = $ARG;
	}

	$length = $LENGTH * $multiplier * $HEADER["PB_TICK_NS"];  //Convert LENGTH from ticks to ns.
	fwrite ($fp_pbsim, "$output\t$length\n");
}

function vcd_lbl($bit){				//Get the VCD label. This is an ascii-printable character.  $bit is integer from 0..23
	return (chr(ord('A') + $bit));		//Start from 'A' for clarity (though we could have a few more identifiers if we started from '!').
}

function write_vcd(){				//Print line for VCD file.
	global $fp_vcd;				//BUG: This function needs to know more than it should about the way the opcodes work.
	global $OUTPUT, $INSTR, $STEP, $ELAPSED_TICKS;
	global $VCD_LABELS;

	static $output_prev;

	$vcd_time = "#$ELAPSED_TICKS\n";	//Timescale is already in ticks. '#' is the VCD identifier.

	$vcd_changes = '';
	if ($STEP == 0){			//On step 0, must output everything.
		foreach ($VCD_LABELS as $bit => $name){
			$value = ($OUTPUT & (1 << $bit));
			if ($value == 0){
				$vcd_changes .=  "0".vcd_lbl($bit)."\n";
			}else{
				$vcd_changes .=  "1".vcd_lbl($bit)."\n";
			}
		}
	}else{					//Otherwise, only need to output the changes.
		foreach ($VCD_LABELS as $bit => $name){
			$value = ($OUTPUT & (1 << $bit));
			$value_prev = ($output_prev  & (1 << $bit));
			if (($value == 0) and ($value_prev != 0)){
				$vcd_changes .=  "0".vcd_lbl($bit)."\n";
			}elseif (($value_prev == 0) and ($value != 0)){
				$vcd_changes .=  "1".vcd_lbl($bit)."\n";
			}
		}
		$output_prev = $OUTPUT;
	}

	if ($INSTR == 'stop'){			//Stop instruction doesn't set outputs.
		fwrite ($fp_vcd, "$vcd_time");
		return (false);
	}elseif ($INSTR == 'wait'){
		fwrite ($fp_vcd, "$vcd_time");  //WAIT instruction: Inserting extra timestamp with the same value, for possible detection later
	}

	fwrite ($fp_vcd, "$vcd_time$vcd_changes");
}

function sim_output($OUTPUT){		//Do the simulation output - print status, light LEDs in correct mode (if needed), write to the parport/fifo (if needed), and delay (if needed).

	global $ADVANCE_STEPS;
	global $SIMULATION_PIANOROLL;
	global $STEP;
	global $SIMULATION_OUTPUT_FIFO, $PBSIM_FILE, $VCD_FILE;
	global $tidied,$PC,$VISIT,$LD,$ELL,$SD,$INSTR,$ARG,$LENGTH,$OUTPUT,$STEP,$ELAPSED_TICKS;

	//Except when we're skipping output, print the status.
	if ($ADVANCE_STEPS==1){
		 //Reprint the status header every 50 lines, in piano-roll mode.
		if ($SIMULATION_PIANOROLL and ($STEP %50 == 0) and ($STEP > 0)){
			print_status_header();
		}
		//Print information on the current position. Current instruction line, register details, stack/loop depths, virtual LEDs etc.
		print_status($tidied,$PC,$VISIT,$LD,$ELL,$SD,$INSTR,$ARG,$LENGTH,$OUTPUT,$STEP,$ELAPSED_TICKS);

		//Write output to the actual parport/fifo, if specified.
		if ($SIMULATION_OUTPUT_FIFO){
			write_output_fifo($OUTPUT);
		}
		//Write to simulation replay log.
		if ($PBSIM_FILE){
			write_pbsim();
		}
		//Write to vcd file.
		if ($VCD_FILE){
			write_vcd();
		}
	}
}

function sim_wouldbe_output($OUTPUT){		//Print the line of code that we WOULD execute, but grey it out.
	global $GREY, $NORM;
	global $XNL1;
	$grey=$GREY; $norm=$NORM;
	de_colour();		//colour-off
	echo $grey;
	echo "$XNL1\n";
	sim_output($OUTPUT);	//in grey
	echo $norm;
	de_colour(true);	//colour-on
}

function sim_mark_time($cmt_first){	//Mark the time for simulation. This is used for measuring times of specific "mark" instructions in the code.
	global $PC, $STEP, $LENGTH, $OUTPUT, $ELAPSED_TICKS, $VISIT, $CLOCK_FACTOR, $HEADER, $XNL1, $PBSIM_FILE, $MARK_PREFIX;	
	global $BMAGENTA, $CYAN, $BLUE, $DRED, $AMBER, $NORM;
	$elapsed_ns = $ELAPSED_TICKS * $HEADER["PB_TICK_NS"] / $CLOCK_FACTOR;  //Convert ticks to ns.
	
	$mark_line = sprintf("${AMBER}$MARK_PREFIX${NORM} %-4s  ${DRED}%-16s${NORM}  ${BMAGENTA}%-5d${NORM}  :${BMAGENTA}%-4d${NORM}  ${DRED}%-24s${NORM}  0x%-8x  0x%-6x  %s", $STEP, $ELAPSED_TICKS, $PC, $VISIT, $elapsed_ns."ns", $LENGTH, $OUTPUT, $cmt_first);  //c.f. print_status().
	print_msg("$XNL1$mark_line");

	$mark_simtxt = "$MARK_PREFIX\tstep=$STEP\tticks=$ELAPSED_TICKS\tns=$elapsed_ns\tpc=$PC\tvisit=$VISIT\tlength=$LENGTH\tout=".sprintf("0x%x",$OUTPUT)."\tcmt=$cmt_first";  //Machine-readable. See also pbsim header.
	if ($PBSIM_FILE){       //If simulating, embed a comment (with special meaning) there, to be later parsed-out.
		write_pbsim ($mark_simtxt);
	}
}

//Read a line from the keyboard, in either blocking or non-blocking way. It's important to do it this way, even for the blocking case, as otherwise Ctrl-C may not work
//properly (during fgets(STDIN), fgets() may block, and at this point [if we have Ctrl-C handled internally by pcntl_signal()], Ctrl-C is ignored until some data arrives.
//Ideally, we'd also be able to read single chars (eg 'ESC' or just 'q') with fgetc(), but konsole only sends typed characters to STDIN on receipt of ENTER.
//If more than one line of input is available, read it now, place on queue, and return it the next time, instead. This keeps the piano-roll output much saner. Otherwise, it can get really ugly.
//Konsole handles this much better than gnome-terminal, which can have trouble sending the input stream if output is being written too fast for it.
function read_keyboard_input($blocking=true){	//See whether a read from STDIN would not block (i.e. whether there are any inputs (followed by newlines) waiting.) If so, read them.
	global $input_queue;	//Queue of pending STDIN.
	$read=array(STDIN);
	$write=NULL;		//Things to monitor. We only care about reading STDIN.
	$except=NULL;

	if (count($input_queue) > 0){	//Is there anything in the queue already? If so, the first line should be returned.
		$queued_input=array_shift($input_queue);
		//Don't print $queued_input here; print it a few moments ago, as part of the prompt instead. This handles race conditions with correct ordering.
		$line=strtolower(trim($queued_input)); //Trim, lower-case it.

	}else{				//If there was nothing in the queue last time, read a line from STDIN. Blocking, or non-blocking according to function caller.
		if ($blocking){				//Should this be blocking (always wait for input), or non-blocking (return immediately even if no input)?
			$timeout=NULL;			//NULL => wait forever (block); whereas 0 => no timeout, no wait.
		}else{
			$timeout=0;
		}
		$num_changed_streams = @stream_select($read,$write,$except,$timeout);	//Stream select: returns the number of streams that wouldn't block, if they were now read. [Supress warning on Ctrl-C, with '@']
		if ($num_changed_streams===false){	//Error. Shouldn't happen. Ignore this.
			print_notice("stream_select() returned false. Ignoring it.");
			$line=false;
		}elseif ($num_changed_streams > 0){	//At least one stream (actually, exactly one in this case) changed status.
			$input=fgets(STDIN);		//Read line from stdin
			$line=strtolower(trim($input)); //Trim, lower-case it.
		}else{
			$line=false;			//No stream changed status. Nothing to do.
		}
	}

	//Is there anything else in STDIN to queue up? If so, read it all into the buffer. Don't block.
	while (true){
		$num_changed_streams = @stream_select($read,$write,$except,0);  //non-blocking, zero timeout.
		if ($num_changed_streams > 0){
			$in=fgets(STDIN);
			array_push($input_queue,$in);	//append to queue.
		}else{
			break;	//break as soon as there's nothing left to read.
		}
	}

	return($line);	 //Be careful with return value (false vs. '')
}

function read_keyboard_nonblock_check_quit(){	//Did user type "Q"? Useful if loopcheat is off, and $SIMULATION_USE_KEYPRESSES is ALSO off.
	$input=read_keyboard_input(false);	//NON-blocking read; may return immediately.
	if ($input=='q'){		//Quit.
		return ("quit");
	}else if (trim($input)){
		print_notice("unrecognised command: '$input'.");
	}
	return (false); 	//Silently ignore blank lines (ENTER with nothing else). Or "false", which means we didn't block.
}

function read_keyboard_blocking_get_multistep(){	//Blocking read of keyboard. Return number of steps to advance, or "quit". Print notice if illegal command.
	$input=read_keyboard_input(); //Blocking read, wait until [ENTER]
	if ($input=='q'){		//Quit.
		return ("quit");
	}elseif ($input === ''){	//Just Enter is shortcut for 1 step.
		$advance_steps=1;
	}elseif ( $input == intval($input) and is_numeric($input) ){ //Entered an integer number of steps. Advance that number.
		if ($input <= 0){	//Entering Zero (or negative) steps to advance is illegal. We're skipping steps, not altering the program counter.
			print_notice("Minimum increment of steps is 1 step. ($input has been ignored.)"); 	//Use 1, and continue.
			$advance_steps=1;
		}else{
			$advance_steps=$input;
		}
	}else{				//Unrecognised.
		print_notice("unrecognised command: '$input'.");   //Use 1, and continue.
		$advance_steps=1;
	}
	return ($advance_steps);
}

function get_s_us_rel(){		//Precision timestamp (seconds.microseconds), relative to offset. We need to subtract offset before combining the usec and sec parts of microtime, because
	static $first_call_time;	//otherwise, the large integer value of time() costs a loss of precision - especially when successive close microtime()s are subtracted from each other.
	if (!$first_call_time){		//Typically, if we omit the offset, then we go from 8 decimal places down to 2 d.p!  (The problem is really that PHP has nothing higher in precision than a double).
		$first_call_time=explode(' ',microtime());   //What shall we use for an offset? Use the seconds part of microtime(), measured at the first call to this function.
		$first_call_time=$first_call_time[1];	    
	}
	$microtime=explode(' ',microtime());
	$s_us_rel=$microtime[0] + $microtime[1] - $first_call_time;	//One might expect that parenthesising the subtraction would make the return value more precise, but it turns out (perversely?) to be the opposite.
	return ($s_us_rel);						//NOTE that the return value is still in units of seconds (not microseconds!).
}

function sim_delay($length,$multiplier=1,$waited_for_keypress=false){	//Do the delay. If the opcode is longdelay, pass ARG as $multiplier. $waited_for_keypress is for WAIT opcpdes with $SIMULATION_WAIT_MANUAL.
	global $ADVANCE_STEPS;			//* If we're not simulating in realtime, just update the ticks counter.
	global $SIMULATION_REALTIME;		//* If we are trying to simulate in realtime, delay the right amount, compensating where we can, for the time this script itself takes.
	global $SIMULATION_USE_KEYPRESSES;	//      But if $waited_for_keypress, then re-sync our error-correction, since we have waited for the user.
	global $SIMULATION_USE_LOOPCHEAT;
	global $SIMULATION_DELAY_SYNC_QUANTUM_US;
	global $ELAPSED_TICKS;
	global $HEADER;
	global $CLOCK_FACTOR;
	global $XNL1,$LNL;
	global $NA;
	global $simulation_start_time;
	global $wait_correction;
	global $MONOCHROME;
	global $RED, $NORM;
	global $QUIET;
	static $monochrome_hinted=false;
	static $warned_simnotfast=false;

	if ($length === $NA){	//In the special case where $length happens to be $NA ('-'), then cast it to 0,
		$length=0;
	}

	//Add delay.		//This is the most accurate way to make the delays as near as possible to what is requested. This algorithm always tries to re-synchronise itself to the target time. As a result, some individual delays will be quite wrong (esp small delays in percentage terms), but overall, it should stay well synced to wall-clock time.
	if (($ADVANCE_STEPS == 1) and ($SIMULATION_REALTIME)){	//The accuracy is good, provided that all times are > ~ 0.1ms
		$s_us_rel = get_s_us_rel();		 	//Get current timestamp.  *This* point is the "heartbeat" for the instruction cycle.

		$time_so_far = ($s_us_rel - $simulation_start_time);  //Where *are* we at the moment?  (s)
		$target = $ELAPSED_TICKS * $HEADER["PB_TICK_NS"] / (1000 * $CLOCK_FACTOR);  //Where should we be?   (us)
		$error = $target - $time_so_far * 1000000;	//Error in us.  Positive means we need to increase our delay (i.e. we're early)

		if ($waited_for_keypress){			//If we just waited for a keypress, then don't account for that error-correction.
			$wait_correction += $error;		//Zero out the error, then still sleep for the specified opcode_delay
		}						//$wait_correction is a global, so that we maintain the offset.
		$error -= $wait_correction;			//Note that -$wait_correction is the total time (in us) we have ever spent waiting for user. wait_correction is usually negative.

		$opcode_delay = ($length * $HEADER["PB_TICK_NS"]) / (1000 * $CLOCK_FACTOR);	//How long (in us) is the specified delay?  Opcode_Delay (modified by CLOCK_FACTOR).   PB_TICK_NS/1000 converts ns to us.
		$opcode_delay *= $multiplier;

		$delay_req = $opcode_delay + $error;	//Required delay (us). If we can usleep() for *exactly* this long, we'd be in perfect sync.  [Of course, this might be negative!]
		if ($delay_req > 0){			//$delay_req is in microseconds, hence the 1000 and 1000,000 above.
			debug_print_msg("${XNL1}Waiting $delay_req us (actually ".round($delay_req)." us). Opcode (with clock_factor $CLOCK_FACTOR) specifies $opcode_delay us; error compensation is $error us.\n");
			$delay_req = round($delay_req);
			$sleep_sec = floor ($delay_req/1000000);		//Split the sleep into second and us parts.
			$sleep_us  = ($delay_req - (1000000 * $sleep_sec));	//Note: usleep() takes positive values within the integer range only

			if (($SIMULATION_USE_KEYPRESSES) or (!$SIMULATION_USE_LOOPCHEAT)){	//Interactive mode.
				$read = array(STDIN); $write = $except = NULL;			//Select with timeout; this way our sleep will terminate early
				@stream_select($read, $write, $except, $sleep_sec, $sleep_us);	//if the user has typed "Q".
			}else{
				sleep ($sleep_sec);						//Non-interactive. Don't watch the keyboard.
				usleep($sleep_us);
			}

		}else{	//Can't delay a negative value. Just skip.
			//NOTE: we are trying to get back to the exact correct time since start; not just correct individual instructions.
			//So Don't zero out the offset, but carry it forward, in the hope of finding a long delay where we can retrieve the "borrowed" time.
			if (!$SIMULATION_USE_KEYPRESSES){  //Warn,
				if (abs($error) > $SIMULATION_DELAY_SYNC_QUANTUM_US){  //Message is really annoying. Only show it if the error is significant.
					if (!$monochrome_hinted and !$MONOCHROME){
						$monochrome_hint=" ${RED}HINT${NORM}: turn on Monochrome mode (-m) or Terse mode (-y) to speed up the gnome-terminal driver (konsole is fast in colour or monochrome).";
						$monochrome_hinted=true;
					}else{
						$monochrome_hint='';
					}
					if (!$QUIET or !$warned_simnotfast){  //In quiet mode, don't repeat this warning.
						print_warning("Simulation not running fast enough for realtime: opcode (with clock_factor $CLOCK_FACTOR) delay is $opcode_delay us; simulation running late by ". -round($error,2)." us; need (impossible) delay of ". round($delay_req,2)." us to resynchronise.$monochrome_hint",$LNL);
						$warned_simnotfast=true;
					}
				}
			}else{				   //But in keypress mode, where such a warning is rather pointless, make it a debug)
				debug_print_msg("Simulation not running fast enough for realtime: we waited for keypress.$LNL");
			}
		}
	}
	$ELAPSED_TICKS= $ELAPSED_TICKS + ($length * $multiplier);	//Update elapsed_ticks counter.
}

$number_of_warnings_pre_sim = $number_of_warnings;
//--------------------------------------------------------------------------------------------------------------
//DO SIMULATION. Run through the entire code, and verify that it will run ok on the hardware. Note: we don't suffer from the halting problem!  See doc/simulation.txt
//This is my favourite bit of the program :-)
if($DO_SIMULATION){
	print_msg("${BLUE}Finished assembly; starting simulation of pulseblaster hardware...${NORM}");
	debug_print_msg("\n################### ${BLUE}BEGIN SIMULATION OF HARDWARE${NORM} ######################################################################");
	$simulation_in_process=true;

	//Introduction.
	if ($SIMULATION_FULL){			//Full simulation explicitly requested by using -f, or implicitly via -t,-l etc  (Explain how to kill it)
		if ($QUIET){
			print_msg("Now running a full (-f) simulation, executing every instruction, every time. To stop early, use ${RED}Q${NORM},[${RED}Enter${NORM}] or Ctrl-C. (Alternatively, use -u).");
		}else{
			print_msg("\nThis is running a full (-f) simulation. Each and every instruction is faithfully executed; for example, a loop always receives n passes, not just one. Unless the ".
			  "program contains a STOP instruction, the simulation will not terminate, and may need to be stopped with ${RED}Q${NORM},[${RED}Enter${NORM}] or Ctrl-C. (Alternatively, use -u).");
		}
		$SIMULATION_USE_LOOPCHEAT=false;  //$SIMULATION_USE_LOOPCHEAT is defined in the configuration section. Should normally be TRUE, except for a FULL simulation.

	}else if ($SIMULATION_USE_LOOPCHEAT){	//Using loopcheat, because it is the default.
		print_msg("Using loop-cheat: each loop will only be simulated once. This optimised simulation is guaranteed to terminate, and is still completely valid.");
	}else{					//Loopcheat explicitly disabled in config section. This disables the test for $instruction_visited. Therefore, the simulation won't terminate until/unless it encounters a STOP instruction.
		print_warning("NOT using loopcheat. This means that each loop will be simulated exactly the right number of times. As a side-effect, we have to disable the test ".
			     "for re-visiting instructions. That means that the simulation will not terminate unless it encounters a STOP instruction. Even then, it could take ".
			     "rather a long time! Therefore, you may need to terminate this process with Ctrl-C.\n",true);
	}

	if ($SIMULATION_VERBOSE_REGISTERS or $SIMULATION_VIRTUAL_LEDS){
		print_msg("\nThis simulation uses a state-machine. The registers are: ${BMAGENTA}PC${NORM}:${BMAGENTA}N${NORM} (program counter, visited for the nth time), SUB_STACK (subroutine stack array), ${CYAN}SD${NORM} (subroutine stack depth), LOOP_STACK ".
			"(loop stack array), ${BLUE}LD*${NORM} (loop stack depth with ELL). The ELL flag (the '*' in '${BLUE}LD*${NORM}) next to LD is 'Endloop-looped': we came from an endloop which looped, so this ".
			"instruction is already in its loop, and won't begin a new loop. The simulation can tell whether the  program will halt, loop forever (safely), or do something dreadful, such ".
			"as exceed the stack depth/loop depth, jump to a non-existent address, etc. Information from the virtual machine is displayed in decimal, whereas the original instructions are printed in hex. ".
			"(For more details see pb_parse/doc/simulation.txt, or read the (straightforward) source code.)\n");

		if (!$SIMULATION_VIRTUAL_LEDS){			//SVR only
			print_msg("At each step, the current (about-to-execute) instruction is printed, as are the status registers (before the opcode modifies them). This is followed by the correspoding line from the source. ".
				"In addition, the VM prints an explanation of what it is doing.\n");
		}else if (!$SIMULATION_VERBOSE_REGISTERS){   	//SVL only
			print_msg("At each step, the VM prints the current step number, clock-ticks, PC/LD,ELL/SD, the instruction and its args, and the output (in hex, and on LEDs). ".
				"The PC/LD,ELL/SD values are printed at the *start* of the currently executing instruction, i.e. after the output is written, but before the opcode executes, modifies PC/LD/SD and delays. \n");
		}
		if ($SIMULATION_OUTPUT_FIFO){		//output to other hardware?
			print_msg("At each step, the output bytes are also written to fifo: '$SIMULATION_OUTPUT_FIFO' (Use $PB_PARPORT_OUT for parallel ports; kill -9 if it blocks.)\n");
		}
		if ($SIMULATION_REALTIME){   //-t  (may apply to either SVR or SVL)
			if ($CLOCK_FACTOR){
				print_msg("The simulation will try to run in real-time, speeded up by factor of '$CLOCK_FACTOR'. Each instruction's time-delay occurs after its status has been printed.\n");
			}else{
				$CLOCK_FACTOR=1;  //if not set, use 1.
				print_msg("The simulation will try to run in real-time. Each instruction's time-delay occurs after its output/status has been printed. If the VM lags behind, it will ".
					  "warn, and try to resynchronise (cumulative errors will mount up until it has encountered sufficient opcode lengths to pay back borrowed time).\n");
			}
		}
		if ($SIMULATION_USE_KEYPRESSES){  //-k  (may apply to either SVR or SVL)
			print_msg("This is in single/multi-step mode. After each line, at the prompt, enter the (positive) number of steps, n, to advance, followed by [ENTER]. The effect is STEPS+=n. ".
				  "[ENTER] alone implies n=1. To end the simulation, press 'Q'. [If multiple lines are entered prematurely, these will be correctly queued for subsequent use, ".
				  "and the inputs will be used up in their correct order. When reading the output in this case, note that any text aligned on the far-left should be ignored, ".
				  "and that queued input will have been echoed back at the prompt ($PROMPT) to which it applied.]\n");
		}	//More explanation about the input queue. It's very easy for a user to enter two lines of input (eg '5,ENTER,6,ENTER') at a single prompt, instead of typing '5,ENTER' at the first prompt,
			//and 6,ENTER at the second. This results in a very ugly mess, and it's confusing to see what happened. By handling the queue, and re-writing the prompt, we can make everything clearer.
	}		//Note that the input queue has no effect on the simulation, compared with just reading one line from STDIN each time we need it. But it does make the interface much clearer.

	if (!$CLOCK_FACTOR){
		$CLOCK_FACTOR=1;  //If -y, we might not have set this yet, ensure no division by zero later!
	}

	if ($SIMULATION_VIRTUAL_LEDS){
		print_status_header();
	}

	if ($PBSIM_FILE){		//Write pbsim header. The rest is done by write_pbsim();
		fwrite ($fp_pbsim, "//This simulation replay-log file was generated by pb_parse by simulating source file '$SOURCE_FILE' on date $date. \n".
				   "//File format: 'OUTPUT (uint32,hex)  \\t  LENGTH (uint64,dec) \\n'. Comments start '//'. Note that length is in ns, not PulseBlaster ticks.\n".
				   "//Mark opcode: '//$MARK_PREFIX  \\t  step=...  \\t  ticks=...  \\t  ns=...  \\t  pc=...  \\t  visit=...  \\t  length=...  \\t  out=...  \\t  cmt=//... \\n'.\n");      //See sim_mark_time().
		if ($SIMULATION_STEP_LIMIT){
			fwrite ($fp_pbsim, "//Simulation will stop (-u) after $SIMULATION_STEP_LIMIT steps, if not before.\n");
		}else{
			fwrite ($fp_pbsim, "//Simulation has no step-limit (-u); this file could be very very long.\n");
		}
	}
	if ($VCD_FILE){			//Write vcd header. The rest is done by write_vcd();  See doc/vcd.txt
		$vcd_date = $date;
		$vcd_version = "$binary_name v.$VERSION ($RELEASE_DATE)";
		$vcd_comment = "Generated from source file: '$SOURCE_FILE'";
		if ($SIMULATION_STEP_LIMIT){
			$vcd_comment .= " (Simulation will stop (-u) after $SIMULATION_STEP_LIMIT steps, if not before.)";
		}
		$vcd_timescale =  "$HEADER[PB_TICK_NS] ns";
		$vcd_module = "PulseBlaster";
		$vcd_definitions = '';					//Define the VCD bits and their labels.
		$vcd_dumpvars = '';
		foreach ($VCD_LABELS as $bit => $name){
			$chr = vcd_lbl($bit);
			$vcd_definitions .= "\$var wire 1 $chr $name \$end\n";
			$vcd_dumpvars .=  "x$chr\n";			//Intialise in the unknown state, x.
		}
		fwrite ($fp_vcd, "\$date\n$vcd_date\n\$end\n\$version\n$vcd_version\n\$end\n\$comment\n$vcd_comment\n\$end\n\$timescale $vcd_timescale \$end\n\$scope module $vcd_module \$end\n".
				 "$vcd_definitions\$upscope \$end\n\$enddefinitions \$end\n\$dumpvars\n$vcd_dumpvars\$end\n");
	}

	if ($SIMULATION_VIRTUAL_LEDS and !$SIMULATION_PIANOROLL){  	//When using the virtual LEDs line, each statusline gets terminated by a \r. So we don't loose the last one,
		$XNL="\n\n";	$XNL1="\n";  	$LNL=true;		//insert an extra \n\n before the subsequent message. (Only one \n is required; the second is for neatness).
	}else{								//$LNL is for print_warning()
		$XNL='';	$XNL1='';	$LNL=false;
	}
	if ($SIMULATION_USE_KEYPRESSES or $SIMULATION_VERY_TERSE){	//Also useful when in keypress or terse mode.
		$XNL.="\n";	$XNL1.="\n";	$LNL=true;
	}

	//Simulation starts here...

	//Initialise State machine...
	$PC=0;				//program counter (== address counter)
	$SUB_STACK=array();		//subroutine stack (for return addresses)
	$SD=0;  			//subroutine stack depth.
	$LOOP_STACK=array();		//loop stack, containing the loop *counter* for the current (and nested) loops.
	$LD=0;				//loop stack depth
	$ELL=0;				//endloop looped. Did we just jump back from an endloop?

	$STEP=0;			//number of steps so far through simulation.
	$INSTR='';			//current instruction (i.e. opcode)
	$ARG='';			//arg of current opcode.
	$VISIT='';			//instruction visitation count. (How many times have we hit this position before?)
	$ELAPSED_TICKS=0;		//number of ticks elapsed since start. Needs to be a larger integer than 32-bit, but doubles can hold up to 52-bits of integer, which is ok.
	$EXIT_REASON;			//exit status (why did we get out of the infinite loop?)
	$ADVANCE_STEPS=1;		//Advance n steps on next instruction. (Normal value is 1, i.e. $PC++)

	$loopstart_addresscheck_stack=array();	//another loop stack (for addresses). We use it for checking, but it is NOT in the state-machine (the pulseblaster doesn't actually have one of these! It relies on the value of ARG being correct.)

	$instruction_visited=array(); //Has the instruction at address $i been "visited" yet? If we re-visit the same instruction, then we know that we have an infinite loop.
	for ($i=0;$i<$number_of_code_lines;$i++){   //Note: $PC must always be in the range:  0 <= $PC < $number_of_code_lines
		$instruction_visited[$i]=0;
	}
	$stack_depth_before_instruction=array();	//Stack and loopdepths at the instruction (after fetch, before the instruction is "executed".)
	$loop_depth_before_instruction=array();
	$ell_before_instruction=array();

	$simulation_start_time=get_s_us_rel();	//Simulation start time: initialise relative secs/us at instruction start.
	$wait_correction = 0;			//Correction, if needed for total time spent in a wait opcode (or single-step), pending keypress. Negative value, in us.
	$do_quit = false;
	
	//Start the "pulseblaster". We can only stop on a break. Breaks occur a)if we reach STOP  b)If we return to an instruction we have already seen.
	while (true){
		//Have we run off the end of the program?
		if ($PC>=$number_of_code_lines){
			print_msg("\n\n${RED}Error: the program is only $number_of_code_lines lines long, but we have run past the end, and tried to execute instruction '$PC'. Simulation ended with error.${NORM}");
			$EXIT_REASON="RAN_PAST_END";
			break;
		}
		
		$INSTR=$opcodes_array[$PC];		//"Read" instruction from "memory".	//INSTR is a string.
		$ARG=$args_array[$PC];								//integer, or $NA
		$OUTPUT=$outputs_array[$PC];							//integer, or $NA
		$LENGTH=$lengths_array[$PC];							//integer_float, or $NA.

		$info=identify_line($lines_array[$PC]);	//Get more details on this line:  identify the corresponding file and line number in source code
		$sourceline="$info[sourcefile_colour], line $info[sourcelinenum_colour]";
		$line="${DBLUE}$info[tidied]${NORM}";	//the line itself, tidied, having got rid of IBS messages.

		//Have we been here before?
		$VISIT = $instruction_visited[$PC];
		if ($instruction_visited[$PC]==0){	//Have we seen this instruction before? If not...
			$instruction_visited[$PC]++;			//Mark it for next time.
			$stack_depth_before_instruction[$PC]=$SD;	//Save the stack_depth and loop_depth and ELL so that if we get back here, we can see whether they've changed.
			$loop_depth_before_instruction[$PC]=$LD;
			$ell_before_instruction[$PC]=$ELL;		//Value of endloop-looped.

		}else{		//If we have seen it before, we are about to repeat somewhere where we have already been.
			$instruction_visited[$PC]++;
			if ($SD != $stack_depth_before_instruction[$PC]){	//If the stack depth is different, we found a bug!
				sim_wouldbe_output($OUTPUT);	//show the status we WOULD do, in grey.
				print_msg("\n\n${RED}Infinite loop: repeated instruction '${NORM}${BMAGENTA}$PC${NORM}${RED}'. BUT, last time the stack depth was '${NORM}${CYAN}$stack_depth_before_instruction[$PC]${NORM}${RED}' and now it is '${NORM}${CYAN}$SD${NORM}${RED}'.${NORM} This will eventually fail.${NORM}");
				print_msg("Simulation had reached line $PC (for the second time). This is $sourceline:\n\t$line");
				$EXIT_REASON="INFINITE_LOOP_STACKBUG";
				break;
			}
			if ( ($LD - $ELL) != ($loop_depth_before_instruction[$PC] - $ell_before_instruction[$PC]) ){ //If the loop depth (compensated by ELL!) is different, we found a bug!
				sim_wouldbe_output($OUTPUT);	//show the status we WOULD do, in grey.
				print_msg("\n\n${RED}Infinite loop: repeated instruction '${NORM}${BMAGENTA}$PC${NORM}${RED}'. BUT, last time the loop depth was '${NORM}${BLUE}$loop_depth_before_instruction[$PC]${NORM}${RED}' (with ELL = '${NORM}${BLUE}$ell_before_instruction[$PC]${NORM}${RED}') and now it is '${NORM}${BLUE}$LD${NORM}${RED}' (with ELL = '${NORM}${BLUE}$ELL${NORM}${RED}').${NORM} (LD - ELL) should be constant.  This will eventually fail.${NORM}");
				print_msg("Simulation had reached line $PC (for the second time). This is $sourceline:\n\t$line");
				$EXIT_REASON="INFINITE_LOOP_LOOPBUG";
				break;
			}
			if ($SIMULATION_USE_LOOPCHEAT){			//Been here before, and using loopcheat. Stack is the same as before: good! Can exit happily, because of loopcheat.
				sim_wouldbe_output($OUTPUT);		//show the status we WOULD do, in grey.
				$EXIT_REASON="INFINITE_LOOP";
				print_msg("$XNL${BLUE}Infinite loop: repeated instruction '${NORM}$PC${BLUE}'. Simulation can now end.${NORM}");
				print_msg("Simulation had reached line '$PC' (for the second time). This is $sourceline:\n\t$line");
				break;
			}
		}

		//Now simulate the instruction.
		switch ($INSTR){
			case 'cont':		//CONTINUE opcode:	Output, Delay, PC++.
			case 'debug':		//[DEBUG is treated as cont.]
			case 'mark':		//[MARK is basically treated like a cont, but triggers timing mark in simulation].
				if ($INSTR == "mark"){
					sim_mark_time($info['cmt_first']);  //Mark the time BEFORE executing the instruction.
				}
				sim_output($OUTPUT);
				$PC++;
				$ELL=0;
				sim_delay($LENGTH);
				break;

			case 'longdelay':	//LONGDELAY opcode:	Output, Delay*Arg. PC++

				sim_output($OUTPUT);
				$PC++;
				$ELL=0;
				sim_delay($LENGTH,$ARG);
				break;

			case 'wait':		//WAIT opcode:		Output. Wait for Trigger. Delay. PC++

				sim_output($OUTPUT);			//The retrigger is either automatic, or explicitly user-controlled. then continue.
				if ($SIMULATION_WAIT_MANUAL){		//It is correct that the wait-trigger-continue happens *before* the delay..
					print_prompt ($XNL1."\tThis is a ${DRED}WAIT${NORM} instruction. Waiting for trigger...press [${GREEN}ENTER${NORM}] to provide one: ");
					read_keyboard_input();   //blocking read from keyboard; discard the input.
					$wait_already_had_one_keypress=true;
					print_msg("\tContinuing...");
				}else{
					sim_verbose_msg("$XNL\tThis is a ${DRED}WAIT${NORM} instruction. Waiting for trigger...simulated an ${DRED}HW_TRIGGER${NORM}. Continuing...");
				}
				$PC++;
				$ELL=0;
				sim_delay($LENGTH,1,$SIMULATION_WAIT_MANUAL);	//Tell sim_delay() that we have just waited for a keypress, so it should re-sync its error-correction.
				break;						//Otherwise, it will erroneously error "simulation not running fast enough for realtime"

			case 'stop':		//STOP opcode:		No Output. Exit.

				sim_output($OUTPUT);	//Note: stop instructions don't set the OUTPUTs. [Since $OUTPUT is "-", light_leds() will ignore it, and print the prev value again.]
				print_msg("$XNL\tThis is a ${DRED}STOP${NORM} instruction. ${BLUE}Simulation terminated.${NORM}");
				print_msg("\nSimulation had reached line '$PC'. This is $sourceline:\n\t$line");
				$EXIT_REASON="STOPPED";
				//stop doesn't call sim_delay()
				break 2;

			case 'goto':		//GOTO opcode:		Output. Delay. Goto ARG. (i.e. PC=Dest)

				sim_output($OUTPUT);
				sim_verbose_msg ("$XNL\tThis is a ${DRED}GOTO${NORM} instruction, Jumping to address '${DRED}$ARG${NORM}'.");
				$PC=$ARG;
				$ELL=0;
				sim_delay($LENGTH);
				break;

			case 'call':		//CALL opcode:		Output. Delay. Call subroutine at ARG. (Push this address onto the stack, Increment the stack depth and jump)

				sim_output($OUTPUT);
				array_push($SUB_STACK,$PC);   //push this address onto the stack, so we can return to it (+1) later.
				$SD++;
				sim_verbose_msg ("$XNL\t${DRED}CALLING${NORM} subroutine at address '${DRED}$ARG${NORM}'. New stack depth is '${CYAN}$SD${NORM}'");
				if ($SD > $HEADER["PB_SUB_MAXDEPTH"]){ //Test: are we now too deep?
					print_msg("\nError: exceeded maximum stack depth '$HEADER[PB_SUB_MAXDEPTH]' with subroutine call at instruction '${BMAGENTA}$PC${NORM}'. ${RED}Simulation ended with error.${NORM}");
					print_msg("Simulation had reached line '$PC'. This is $sourceline:\n\t$line");
					$EXIT_REASON="STACK_DEPTH_EXCEEDED";
					break 2;
				}
				$PC=$ARG;  			//jump to  the subroutine.
				$ELL=0;
				sim_delay($LENGTH);
				break;

			case 'return':		//RETURN opcode:	Output. Delay. Return from subroutine. (Pop address off the stack, decrement the stack depth and jump back.)

				sim_output($OUTPUT);
				$pop=array_pop($SUB_STACK);	//get the calling address off the stack. [array_pop will be NULL if there is nothing to pop.]
				$SD--;
				if ($SD < 0){ 			//Test: have we tried to return once too far?
					print_msg("\nError: tried to return without a call; stack was empty at instruction '${BMAGENTA}$PC${NORM}'. ${RED}Simulation ended with error.${NORM}");
					print_msg("Simulation had reached line '$PC'. This is $sourceline:\n\t$line");
					$EXIT_REASON="NON_EXISTENT_RETURN";
					break 2;
				}
				if (is_null($pop)){		//Can't happen, because we should have break'd on the above test for $SD <0.
					fatal_error("Simulator bug handling emulated stack. \"Can't happen\" that \$pop == null with \$SD >= 0.");
				}
				$PC=$pop+1;			//add 1, since we want to return to the instruction after the caller.
				sim_verbose_msg ("$XNL\t${DRED}RETURN${NORM} from subroutine to address '${DRED}$PC${NORM}'. New stack depth is '${CYAN}$SD${NORM}'.");
				$ELL=0;
				sim_delay($LENGTH);
				break;

			case 'loop':		//LOOP opcode:		Output. Delay. Begin a loop of n cycles. (Push N and PC onto stack).

				sim_output($OUTPUT);	//If we jumped back from our own endloop, don't begin a deeper loop.
				if (!$ELL){		//Only enter a new loop if we aren't already in it.
					array_push($LOOP_STACK,$ARG);			//Push the number of loops (ARG) onto LS.
					array_push($loopstart_addresscheck_stack,$PC);	//Push the current address onto LAS.  (Note: only the simulator has this register, as an extra check; the pulseblaster doesn't.)
					$LD++;		//Increment LD (after push)
					sim_verbose_msg ("$XNL\tBegin ${DRED}LOOP${NORM} (of '${DRED}$ARG${NORM}' cycles). New loop depth is '${BLUE}$LD${NORM}'.");
					if ($LD > $HEADER["PB_LOOP_MAXDEPTH"]){ //Test: are we now too deep?
						print_msg("\nError: exceeded maximum loop depth '$HEADER[PB_LOOP_MAXDEPTH]' with begin loop at instruction '${BMAGENTA}$PC${NORM}'. ${RED}Simulation ended with error.${NORM}");
						print_msg("Simulation had reached line '$PC'. This is $sourceline:\n\t$line");
						$EXIT_REASON="LOOP_DEPTH_EXCEEDED";
						break 2;
					}
				}else{
					$left = array_pop ($LOOP_STACK);  //no-op: pop, then push, so we can read the value for the debug message.
					array_push ($LOOP_STACK, $left);
					sim_verbose_msg ("$XNL\tContinuing ${DRED}LOOP${NORM} ('${DRED}$left${NORM}' cycles left). Loop depth is '${BLUE}$LD${NORM}'.");
				}
				$PC++;		//Continue to the next address.
				$ELL=0;
				sim_delay($LENGTH);
				break;

			case 'endloop':		//ENDLOOP opcode:	Output. Delay. Endloop: Jump back to loopstart+1, or continue.

				sim_output($OUTPUT);
				$loop_counter=array_pop($LOOP_STACK);				//Pop the number of loops remaining off the stack.
				$start_loop_address=array_pop($loopstart_addresscheck_stack);	//Get the address of the loop-start off the stack - used ONLY by simulator, for checking.
				$LD--;	//Decrement LD after pop.
				if ($LD < 0){ //Test: have we tried to exit too many loops?
 					print_msg("\nError: ${DRED}END LOOP${NORM} tried to end a non-started loop; loop stack is empty at instruction '${BMAGENTA}$PC${NORM}'. ${RED}Simulation ended with error.${NORM}");
 					print_msg("Simulation had reached line '$PC'. This is $sourceline:\n\t$line");
					$EXIT_REASON="NON_EXISTENT_ENDLOOP";
 					break 2;
 				}
				if (is_null($loop_counter)){		//Can't happen, because we should have break'd on the above test for $LD < 0.
					fatal_error("Simulator bug handling emulated loop-stack. \"Can't happen\" that \$loop_counter == null with \$LD >= 0. Parser line: ".__LINE__);
				}
				if ($start_loop_address != $ARG){ //Check that ARG (used by the PB) is the same as the simulator's check in $loopstart_addresscheck_stack
					print_msg("\nError: ${DRED}END LOOP${NORM}'s ARG '$ARG' at instruction '${BMAGENTA}$PC${NORM}' does not correspond to a correctly-nested start of loop. ${RED}Simulation ended with error.${NORM}");
 					print_msg("Simulation had reached line '$PC'. This is $sourceline:\n\t$line");
					$EXIT_REASON="WRONG_LOOP_STARTADDR";   //Occurs when ARG points to the wrong start of loop, or simply not to a loop at all.
 					break 2;
 				}
				$loop_counter--; //Decrement the loop counter.
				if ($SIMULATION_USE_LOOPCHEAT){		//Cheat. We don't need to simulate 1000 cycles when one will do! Does NOT affect validity of simulation.
					if ($loop_counter >0){		//Note: if we don't cheat, we have to abandon the $instruction_visited test. Simulation might not terminate.
						sim_verbose_msg ("$XNL\tCheating: Loop actually has '$loop_counter' cycles remaining - but we're going to skip them all.");
						$loop_counter =0;
					}
				}
				if ($loop_counter == 0){  //If counter == 0, then it's time to exit the loop.
					//[We can now forget about $start_loop_address ]
					$ELL = 0;
					$PC++;  //Move onto next instruction.
					sim_verbose_msg ("$XNL\t${DRED}TEST END LOOP${NORM}. Counter is now 0; exiting loop and continuing at next instruction '${DRED}$PC${NORM}'. Loop depth is now '${BLUE}$LD${NORM}'.");
				}else{
					array_push($LOOP_STACK,$loop_counter);				//Save the (decremented) value of the loop counter.
					array_push($loopstart_addresscheck_stack,$start_loop_address);  //Push the loop-start address back onto the stack.
					$LD++;  //Increment LD (after push).
					$ELL = 1;
					$PC=$start_loop_address; //Note that we jump back to the loop start address itself, not 1 inside the loop!
					sim_verbose_msg ("$XNL\t${DRED}TEST END LOOP${NORM}. Counter is now '$loop_counter'; returning to start of loop address at '${DRED}$PC${NORM}'. Loop depth is now '${BLUE}$LD${NORM}'.");
				}
				sim_delay($LENGTH);
				break;
				
			case 'never':		//NEVER opcode. This is dead-code that we should never reach.
				sim_output($OUTPUT);
				$PC++;
				$ELL=0;
				sim_delay($LENGTH);
				print_msg("\nError: encountered a ${DRED}NEVER${NORM} opcode at instruction '${BMAGENTA}$PC${NORM}'; this is identified by pb_parse as 'dead code' and program control should never reach this. ${RED}Simulation ended with error.${NORM}");
 				print_msg("Simulation had reached line '$PC'. This is $sourceline:\n\t$line");
				$EXIT_REASON="ENCOUNTERED_NEVER";   //Occurs when we hit a NEVER opcode.
				break 2;
				
			default:
				fatal_error ("simulation failed at pb_parse line ".__LINE__.": unknown instruction '$INSTR'.");  //Can't happen - unless we have added a new instruction to the instruction set.
				break 2;
		}

		if ($SIMULATION_USE_KEYPRESSES){		//Now, read a keypress for manual single/multi-steping. If necessary, advance the step-counter by more than one, executing
			if ($wait_already_had_one_keypress){	//the opcodes as normal, but supressing the output, and not doing the delay. (Also accept "Q" to quit).
				$wait_already_had_one_keypress=false;	//We already had one manual keypress to continue after a WAIT. Don't need another one!
			}else if ($ADVANCE_STEPS > 1){			//If we are skipping steps, count this one as duly skipped.
				$ADVANCE_STEPS--;
			}else{
				$ADVANCE_STEPS = read_keyboard_blocking_get_multistep();
			}
		}elseif (!$SIMULATION_USE_LOOPCHEAT){		//If Loopcheat off, (and not -k), may still need manual input to terminate the simulation, so listen for 'Q' having been pressed.
			$do_quit = read_keyboard_nonblock_check_quit();
		}
		if (($do_quit == "quit") or ($ADVANCE_STEPS == "quit")){
			print_msg($XNL."${AMBER}Quitting simulation, on request.${NORM}");
			$EXIT_REASON="TERMINATED_BY_USER";
			break;
		}
		if (($SIMULATION_STEP_LIMIT) and ($STEP +1 >= $SIMULATION_STEP_LIMIT)){
			print_msg($XNL."${AMBER}Quitting simulation, after reaching step_limit (-u) of $SIMULATION_STEP_LIMIT.${NORM}");
			$fp_pbsim && fwrite ($fp_pbsim, "//Simulation stopped at step-limit of $SIMULATION_STEP_LIMIT steps.\n");
			//VCD files can't be commented, or we would do the same with $fp_vcd
			$EXIT_REASON="REACHED_STEP_LIMIT";
			break;
		}elseif ((($PBSIM_FILE) or ($VCD_FILE)) and ($STEP == 999999) and (!$QUIET)){  //Helpful hint (really a notice, but print_notice() puts the leading "Notice" before the "\n").
			print_msg($XNL1."Hint: Simulation Replay Log still not terminated after 1000 000 instructions. Use -u next time?\n");
		}

		$STEP++;				//Next step of simulation.
		//[PC has already been modified; when we go round this loop again, it takes effect. Here is our rising-edge clock!]
	}
	//[Simulation loop is finished]


	//The pulseblaster has stopped, either on encountering a STOP, or we know we reached a repeat in an infinite loop (and don't need to bother any more), or an error occurred.
	$simulation_end_time=get_s_us_rel();	//Get simulation end time.
	$simulation_in_process=false;
	sim_verbose_msg("");
	print_msg ("\n${BLUE}...Finished simulation of pulseblaster hardware.${NORM}");

	$simulation_run_time= round($simulation_end_time - $simulation_start_time,2);
	if ($wait_correction){
		$wait_txt=" (including ". - round($wait_correction/1000000, 2) ." seconds waiting in WAIT opcode (or single-stepping), pending keypress)";
	}
	$inst_per_sec = round ($STEP/($simulation_run_time+0.000001), 2);
	
	if ($SIMULATION_REALTIME){			//If we're trying to be in realtime, print the simulation runtime information. How well did we do on average?
		print_msg("Simulation run time was: $simulation_run_time seconds$wait_txt, at an average of $inst_per_sec instructions/second.\n");
	}

	//Did we use all the instructions? Maybe some of them never got visited?
	$unused_instructions=false;
	for ($i=0;$i<$number_of_code_lines;$i++){
		if ($instruction_visited[$i]==0){
			$info=identify_line($lines_array[$i]);				//Identify the corresponding file and line number in source code
			$sourceline="$info[sourcefile_colour], line $info[sourcelinenum_colour]";
			$trunc=trim($info['trunc']);
			$pieces=preg_split("#//#",$trunc,2);
			if (count($pieces)==1){ 
				$pieces[1]='';
			}
			$more_txt ='';
			if (strpos($info['ibs'], "zeroloop_skip")){   //Was this because of zeroloop or "never"?
				$more_txt = "[${AMBER}Zeroloop$NORM]";
			}elseif ($opcodes_array[$i] == "never"){
				$more_txt = "[${AMBER}Never$NORM]";
			}
			$unused_instructions.="\tADDR:".str_pad($i,2)." SRC:$sourceline INSTR:$BLUE".str_pad(trim($pieces[0]),30)."$NORM  //$more_txt $pieces[1]\n";
		}
	}

	//Print results of the simulation. We are guaranteed to reach this point (regardless of the 'halting problem'), provided that $SIMULATION_USE_LOOPCHEAT is TRUE.
	switch ($EXIT_REASON){
		case 'INFINITE_LOOP':		//We got into an infinite loop. Everything is fine, and we also know that the stack/loop depths are OK
			if ($unused_instructions!==false){
				print_notice("not all instructions were executed. Program execution never reaches these redundant instructions:\n$unused_instructions");
			}
			print_msg("${GREEN}Simulation was successful${NORM}. The pulseblaster program will work fine (infinite loop, will never terminate). Note: we have also tested OK for stack and loop depth.");
			break;

		case 'INFINITE_LOOP_STACKBUG':  //We got into an infinite loop, but we know that the stack depth has increased. This will ultimately exceed the max stack depth.
			fatal_error("simulation proved that the program will fail. It works in an infinite loop, but each iteration increases the stack depth. Exiting now.");
			break;

		case 'INFINITE_LOOP_LOOPBUG':	//We got into an infinite loop, but we know that the loop depth has increased. This will ultimately exceed the max loop depth.
			fatal_error("simulation proved that the program will fail. It works in an infinite loop, but each iteration increases the loop (nest) depth. Exiting now.");
			break;

		case 'STOPPED':			//We reached a STOP instruction. We're happy.
			if ($unused_instructions!==false){
				print_notice("not all instructions were executed. Program execution never reaches the following redundant instructions:\n$unused_instructions");
			}
			print_msg("${GREEN}Simulation was successful${NORM}. The pulseblaster program will work fine (and will terminate at STOP). Note: we have also tested OK for stack and loop depth.");
			break;

		case 'RAN_PAST_END':		//Ran past the end of the code. Fatal. Don't even try to program this into the pulseblaster.
			fatal_error("simulation proved that the program will fail. It runs past the end of the code, without ever encountering a STOP. Exiting now.");
			break;

		case 'STACK_DEPTH_EXCEEDED':	//Too many nested calls.
			fatal_error("simulation proved that the program will fail. It exceeds the maximum stack depth for nested subroutine calls. Exiting now.");
			break;

		case 'NON_EXISTENT_RETURN':	//Tried to return, without call.
			fatal_error("simulation proved that the program will fail. It attempts to return from a non-existent call, with an empty stack. Exiting now.");
			break;

		case 'LOOP_DEPTH_EXCEEDED':	//Too many nested loops.
			fatal_error("simulation proved that the program will fail. It exceeds the maximum loop depth for nested loops. Exiting now.");
			break;

		case 'NON_EXISTENT_ENDLOOP':	//Tried to endloop, without having been in a loop.
			fatal_error("simulation proved that the program will fail. It attempts to end a loop which has not been started, with an empty stack. Exiting now.");
			break;

 		case 'WRONG_LOOP_STARTADDR':    //Occurs when ARG points to the wrong start of loop, or simply not to a loop at all.
			fatal_error("simulation proved that the program will fail. The ARG of an ENDLOOP does not point back to the correct LOOP. Either the loop is mis-nested, or the ARG doesn't point to a start of loop at all. Exiting now.");
			break;
			
		case 'ENCOUNTERED_NEVER':
			fatal_error("simulation proved that the program will fail. Somehow we encountered a 'never' opcode, though this is supposed to be dead code which program-flow never reaches. Exiting now.");
			break;
		
		case 'TERMINATED_BY_USER':
			print_msg("Full simulation was terminated by user before its end. No bugs have been spotted so far, but it is {$RED}advisable${NORM} to run the optimised simulation (just plain -s) in order to be sure.");
			break;
		case 'REACHED_STEP_LIMIT':
			print_msg("Full simulation was automatically terminated before its end, after reaching $SIMULATION_STEP_LIMIT steps. No bugs have been spotted so far, but it is {$RED}advisable${NORM} to run the optimised simulation (just plain -s) in order to be sure.");
			break;
		default:
			fatal_error("unknown exit status '$EXIT_REASON'."); //Can't happen (we hope)
			break;
	}

	//If we get this far, we're very happy!!!   :-)
	//There are (believed to be) no syntax errors at this point. We have (I hope!) detected every possible detectable error.  :-)
	//Of course, we can't read the programmer's mind. So if the programmer has called the wrong function, or has replaced a 2 by a 3, then there is no way we could tell.
	print_msg ("The pulseblaster program will work. Any remaining bugs are believed to be (undetectable) logic errors which are the responsibility of the .$SOURCE_EXTN file author!\n");   //i.e. Do what I mean!
	debug_print_msg("\n");
}

//--------------------------------------------------------------------------------------------------------------
//OUTPUT TO FILE - in structure defined by doc/vliw.txt
//Each line contains 4 fields: OUTPUT (hex), LENGTH (hex): OPCODE (string), ARG(hex) and an optional COMMENT (string).
//THese are delimited by whitespace. Output and ARG (and rarely, LENGTH) may sometimes be '-', i.e. explicitly $NA.  pb_prog recognises '-' as zero.

$output_contents= <<<EOT
//This file was auto-generated by $binary_name, from source file '$SOURCE_FILE' on date $date.
//Generated for a model $HEADER[PB_VERSION] pulseblaster, with a $HEADER[PB_CLOCK_MHZ] MHz ($HEADER[PB_TICK_NS] ns) clock and $HEADER[PB_MEMORY] words of memory.
//Do not edit this file; edit the original .$SOURCE_EXTN file and re-generate it with pb_parse.

//OUTPUT     OPCODE      ARG        LENGTH	 //COMMENT.  [ADR=address; LBL=label; SRC=linenum in source; CMT=comment]

EOT;

$chunks_array=explode("\n",$output_contents);	//Double-check that the lines in the intro above are not too long for pb_prog's buffer.
foreach ($chunks_array as $line) {		//Should never happen!
	if  (strlen($line) > $HEADER["VLIWLINE_MAXLEN"] -2){
		fatal_error("parser tried to preface vliw file by a comment with an excessive length (> ".($HEADER["VLIWLINE_MAXLEN"] -1) ." characters). This is too large for $ASSEMBLER's buffer.");
	}
}

debug_print_msg("\n################### ${BLUE}WRITE OUT VLIW and OTHER FILES (AND COMPARE WITH SOURCE)${NORM} ##########################################");
vdebug_print_msg("Here are the ${DBLUE}source lines{$NORM} with ${CYAN}line numbers{$NORM} and the corresponding lines of finished ${MAGENTA}vliw output${NORM}:\n");
if (!$VERBOSE_DEBUG){
	debug_print_msg("Note: verbose debugging (-v) is disabled, enable it to print even more information.");
}

for ($i=0;$i<$number_of_code_lines;$i++){
	$output=$outputs_array[$i];			//OUTPUT is a hexadecimal number (3 bytes) (or $NA (i.e. "-") if not defined.)
	$opcode=($opcodes_array[$i]);			//OPCODE is a string
	$arg=$args_array[$i];				//ARG is a hex number, (or $NA (i.e. "-") if not defined.)
	$length=$lengths_array[$i];			//LENGTH is a float, representing a hexadecimal integer (4 bytes)  (or $NA (i.e. "-") if not defined.)
	$comment=$comments_array[$i];			//Comment (string).
	$label=$labels_array[$i];			//The label, if there was one.

 	//Output in .vliw format. printf spacing is for human-readability. We can't do a single printf, since $NA isn't hex.
	if ($output===$NA){				//Output value 0x......, or '-' if $NA.   Hex is the natural format for this.
		$output=sprintf("% -12s",$NA);
		$output_dbg=sprintf("% -20s",$NA);	//[one more tab in the debug output, for nicer alignment.]
	}else{
		$output=sprintf("0x% -10x",$output);
		$output_dbg=sprintf("0x% -18x",$output);
	}

	$opcode = str_pad($opcode,11);			//Opcode: string
	$arg = str_pad($arg, 10);			//Arg: Decimal value, or '-' if $NA   (Decimal is the natural format for arg; not hex)

	#$length=sprintflx($length,12,$i);		//Delay length: 0x........, or '-' if $NA.  Use sprintflx() because this value may not fit in a sprintf()'s integer.
	$length=str_pad($length,12);			//Actually, it's clearer formatted in decimal.
	$output_line="$output $opcode $arg $length ";
	$output_line_dbg=$output_dbg.$output_line;

	if (strlen($output_line) > $HEADER["VLIWLINE_MAXLEN"] -2){ //If output line (without comments or \n) is too long for pb_prog's buffer, this is fatal.
		fatal_error("output line $i is too long (".strlen($output_line)." characters) for $ASSEMBLER. The max length is defined in $HEADER_BINARY by VLIWLINE_MAXLEN=$HEADER[VLIWLINE_MAXLEN].  Line $i is:\n\t$output_line\n");
	}

	$info=identify_line("//$comment");		//Extract the source-line number from the comment, and restore the comment to what it was.
	$comment= ltrim($info['trunc']," /");		//Note: for brevity, we don't include the filename here. Squeeze spaces.
	$sourcelinenum=$info['sourcelinenum'];
	$comment =   "//ADR:".sprintf("0x% -3x",$i)." SRC:".str_pad($sourcelinenum,4)." LBL:".str_pad($label,10)."   CMT:$comment"; //In the comment field, include the ADDRESS, the LABEL (if there was one), the source-linenum, and the original COMMENT.
	$comment_dbg="//ADR:".sprintf("0x% -3x",$i)." SRC:".str_pad($sourcelinenum,4)." LBL:".str_pad($label,10)."   CMT:..."; //Shorter version, for screen.

	$output_line_dbg=$output_line_dbg.$comment_dbg."\n";  //Shorter version
	$output_line.=$comment."\n";	//Comment, and newline.

	if (strlen ($output_line) > $HEADER["VLIWLINE_MAXLEN"] -1){  //If output line (with comments and \n) is too long for pb_prog's buffer, trim it and warn.
		$output_line=substr($output_line,0,$HEADER["VLIWLINE_MAXLEN"] -2);
		rtrim($output_line,'/');	//In case we end up with a trailing '/' (not a '//'), we need to remove it. (Removing both // would do no harm).
		$output_line.="\n";	//re-append the \n
		vdebug_print_msg("trimmed some of the comment off line $i, so that it would fit within VLIWLINE_MAXLEN for $ASSEMBLER.");
	}

	$output_contents.=$output_line;		//Append to output file.
	if ($i%10==9){
		$output_contents.="\n";		//After every 10th line, insert an extra newline for readability.
	}

	//FOR DEBUG, print out each source-code line, followed by the output line. Also, print any spare comments.
	if (array_key_exists ($i, $spare_comments_array) and $spare_comments_array[$i]){
		$spare_comments=explode("\n",$spare_comments_array[$i]);	//Supress the boring PARSER_IBS messages.
		$printme='';
		foreach ($spare_comments as $scomment){
			$info=identify_line("//$scomment");
			$interesting=trim($info['line']);
			if ($interesting){
				$printme.="\t$interesting\n";
			}
		}
		if($printme){
			vdebug_print_msg("Before line $i:\n$printme");
		}
	}

	$label_indent =  ($labels_array[$i])? "":"\t"; //Nicer formatting: alignment with labels.
	$info=identify_line($lines_array[$i]);  //Get the identification from each line.
	vdebug_print_msg("Source line $i ($info[sourcefile_colour], line $info[sourcelinenum_colour]) and its output:\n$label_indent${DBLUE}$info[tidied_colour]{$NORM}\n\t${MAGENTA}".trim($output_line_dbg)."${NORM}\n");
}

//at end, there may be one more spare comment remaining.
if (array_key_exists ($i, $spare_comments_array) and $spare_comments_array[$i]){
	vdebug_print_msg("After line ".($i-1).":\n\t$spare_comments_array[$i]");
}
$output_contents.="\n//END OF FILE\n";  //For information only.

//--------------------------------------------------------------------------------------------------------------
//WRITE OUTPUT TO FILE, ASSEMBLE, and EXIT.

if (!fwrite ($fp_out, $output_contents)){	//Note: if we have already experienced a fatal error, and don't get here, $OUTPUT_FILE will be empty. pb_prog will detect it.
	fatal_error("could not write output to file '$OUTPUT_FILE'.");
}else{
	debug_print_msg("Output has been written to file '$OUTPUT_FILE'.");
	$outputs_assembly_txt = "output file '$OUTPUT_FILE' ";
}

if ($DO_ASSEMBLY){				//Do the assembly, and output to $BINARY_FILE. Fatal error if this fails.
	$cmd = "$ASSEMBLER $OUTPUT_FILE $BINARY_FILE 2>&1";
	debug_print_msg("Now Assmbling the binary. Running command: $cmd");
	unset($output);
	$lastline = exec ($cmd,$output,$retval);	//Do the assembly
	if ($retval != 0){
		$DEBUG=true;  //on failure, be verbose.
	}
	debug_print_msg("Command returned value $retval. Assembler output was:");
	foreach ($output as $line){
		debug_print_msg(" $ASSEMBLER: $line");
	}
	if ($retval != 0){
		fatal_error("Error assembling binary. Command '$cmd' exited with error '$retval'.");
	}
	debug_print_msg("Assembled binary has been written to file '$BINARY_FILE'. (View it with 'xxd', or program with $PROGRAMMER).");
	$outputs_assembly_txt .= "and executable file '$BINARY_FILE' ";
}

if ($PBSIM_FILE){
	fwrite ($fp_pbsim, "//End of file.\n");
	debug_print_msg("Simulation replay log has been written to file '$PBSIM_FILE'.");
	$outputs_assembly_txt .= "and simulation replay-log file '$PBSIM_FILE' ";
}

if ($VCD_FILE){
	#There is no vcd footer.
	debug_print_msg("Value change dump has been written to file '$VCD_FILE'.");
	$outputs_assembly_txt .= "and value change dump file '$VCD_FILE' ";
}

fclose($fp_out);					//Fclose. (Not strictly necessary). On Linux, this implicitly releases any locks.
$fp_pbsim && fclose($fp_pbsim);
$fp_vcd && fclose($fp_vcd);
$fp_sofifo && fclose($fp_sofifo);

//Print summary.
$muted_txt="";
if (($QUIET) and ($number_of_notices >0)){	//Warn about missing notices, in QUIET mode.
	$muted_txt .= "${AMBER}$number_of_notices notice".(($number_of_notices>1)?"s":"")."${NORM} suppressed by '-q'. ";
}
if ($number_of_silences >0){
	$muted_txt .="${AMBER}$number_of_silences silence".(($number_of_silences>1)?"s":"")."${NORM} suppressed by '@', use '-S' to scream. ";
}
$number_of_warnings_sim = $number_of_warnings - $number_of_warnings_pre_sim;
if ($number_of_warnings_sim > 0){
	$muted_txt .= "$number_of_warnings_sim simulation warning(s) occurred. ";
}
$muted_txt = trim($muted_txt);
if ($muted_txt){
	$muted_txt = "($muted_txt) ";
}
$perf_txt = "Compiled $sloc_count sloc to $number_of_code_lines vliws in $parser_run_time seconds.";
if ($DO_SIMULATION){
	$perf_txt.=" Simulated $STEP instructions in $simulation_run_time seconds.";
}

if ($number_of_warnings_pre_sim == 0){	//Count warnings; ignore notices. Only count non-simulation warnings here.
	print_msg("${GREEN}Finished successfully${NORM}, and with no parser warnings! $muted_txt$perf_txt");
}else{
	print_msg("\n${GREEN}Finished OK${NORM}, but with ${RED}$number_of_warnings_pre_sim parser warning".(($number_of_warnings_pre_sim>1)?"s":"")."${NORM}. $muted_txt$perf_txt"); //Leading newline, to separate this text from the last warning.
}

if (!$QUIET){
	print_msg("Generated ${outputs_assembly_txt}for a PulseBlaster (model $HEADER[PB_VERSION], $HEADER[PB_CLOCK_MHZ] MHz clock, $HEADER[PB_MEMORY] words). Now use $ASSEMBLER/$PROGRAMMER.");
}
print_msg("");

exit ($EXIT_SUCCESS);  //If we get to here, we're happy :-)
?>
