<?php
/* Calculate the clock stepping process to get to 4 pixels with arbitrary coordinates */

/* Example invocation */
// php clock_calc.php CLOCK_CALC 0x1 0x2 0x4 0x8 0x10  0x20 0x40 0x80  0 511  33 81  202 109  76 343

/* Algorithm: split up into single and double-clocks. Then sort. Eg:
      0  times:  double_step (all bits, 1111 1111 )
      16 times:  double_step (bits 0xfe,1111 1110 )
      22 times:  double_step (bits 0xfa,1111 1010 )
      2  times:  double_step (bits 0xba,1011 1010 )
      14 times:  double_step (bits 0xb2,1011 0010 )
      47 times:  double_step (bits 0x92,1001 0010 )
      70 times:  double_step (bits 0x82,1000 0010 )
      84 times:  double_step (bits 0x2, 0000 0010 )
      once: 	 single_step (bits 0xae 1010 1110 )
*/

/* The output is a #define, suitable for the move_by_calculated_steps() macro, to be included in the pbsrc file, and then parsed. */

//Validate args */
if ($argc != 18){
	fprintf (STDERR, "Error: Wrong number of args. Require 17, got ".($argc -1).". Args should be the definition_name, 8 data-lines, then the 8 coordinates. Read source for help: $argv[0].\n"); //don't count argv[0].
	exit (1);
}
for ($i=2;$i<18;$i++){
	if ( (!is_numeric($argv[$i])) or ($argv[$i] < 0)  ){
		fprintf (STDERR, "Error: Args must be non-negative integers. $argv[$i] is invalid.\n"); 
		exit(1);
	}
	$argv[$i] = intval($argv[$i]); // + 0);
}

/* Input data: 4 quadrants, Bit (which wire to clock), (x,y) positions to seek to */
$NAME = $argv[1];

$q0_x_bit = $argv[2];
$q0_y_bit = $argv[3];

$q1_x_bit = $argv[4];
$q1_y_bit = $argv[5];

$q2_x_bit = $argv[6];
$q2_y_bit = $argv[7];

$q3_x_bit = $argv[8];
$q3_y_bit = $argv[9];

$q0_x = $argv[10];
$q0_y = $argv[11];

$q1_x = $argv[12];
$q1_y = $argv[13];

$q2_x = $argv[14];
$q2_y = $argv[15];

$q3_x = $argv[16];
$q3_y = $argv[17];


/* Do calculations. */
$bits   = array ($q0_x_bit, $q0_y_bit, $q1_x_bit, $q1_y_bit, $q2_x_bit, $q2_y_bit, $q3_x_bit, $q3_y_bit);	//The bits.
$all_bits = ($q0_x_bit | $q0_y_bit | $q1_x_bit | $q1_y_bit | $q2_x_bit | $q2_y_bit | $q3_x_bit | $q3_y_bit);	//All bits (logical or)
$coords  = array ($q0_x, $q0_y, $q1_x, $q1_y, $q2_x, $q2_y, $q3_x, $q3_y);					//The output counts. Note: no special care need be given to (x,y) pairings.

$singles = array();	//number of single steps
$doubles = array();	//number of double steps
$sbits   = $bits;
$dbits   = $bits;

for ($i=0;$i<8;$i++){
	$singles[] = $coords[$i] %2;
	$doubles[] = ($coords[$i] - ($coords[$i] %2) )/2;
}

array_multisort($doubles, SORT_ASC, $dbits);	//Sort doubles, parallel sort dbits
array_multisort($singles, SORT_ASC, $sbits);

$prev_total = 0;
$off_bits = 0;
$single_bits = 0;
for ($i=0;$i<8;$i++){			//find the deltas from one to the next.
	$x = $doubles[$i];		//which bits do we need to clock?
	$doubles[$i] -= $prev_total;
	$prev_total  = $x;

	$x = $dbits[$i];
	$dbits[$i] = $all_bits & (~$off_bits);
	$off_bits |= $x;

	if ($singles[$i]){
		$single_bits |= $sbits[$i];
	}
}

/* Print output. This is a single #define of $NAME to a comma-separated list of numbers. (considered as valid .pbsrc code). */
printf ("#define $NAME ");
for ($i=0;$i<8;$i++){
	printf ("%d, 0x%x, ", $doubles[$i], $dbits[$i]);
}
printf ("0x%x\n", $single_bits);


/* Must exit 0 on success. */
exit (0);
?>
