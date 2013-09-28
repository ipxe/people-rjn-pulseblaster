/* This is pb_zero.c  It initialises the PulseBlaster outputs to all zeros. Special case of pb_init.c
 * This is useful when resetting the entire circuit, since it avoids accidentally powering the Device Under Test.
 * This is Free Software, released under the GNU GPL, version 3 or later.
 */

#define  pb_zero 1

#include "pb_init.c"
