<?php
//This is the PHP part of execinc-test.pbsrc

//Anything on STDERR is passed directly through.
fprintf (STDERR, "#execinc: ARGs are: ".implode(",  ",$argv)."\n");

//Args are here.
$x = $argv[1];
$y = $argv[2];


//Do calculations.
$d = $y;
$e = $x * $y;

//Print output, as #define, on stdout.
echo "#define  D  $d\n";
echo "#define  E  $e\n";

//Exit 0 on success.
exit (0);
?>
