/*
 * Copyright (C) 2010 Michael Brown <mbrown@fensystems.co.uk>.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of the
 * License, or any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 */

#ifndef _PULSEBLASTER_H
#define _PULSEBLASTER_H

/** Pulseblaster command registers */
enum pulseblaster_command {
	PB_DEVICE_RESET = 0x0,
	PB_DEVICE_START = 0x1,
	PB_SELECT_BPW = 0x2,
	PB_SELECT_DEVICE = 0x3,
	PB_CLEAR_ADDRESS_COUNTER = 0x4,
	PB_FLAG_STROBE = 0x5,
	PB_DATA_TRANSFER = 0x6,
	PB_PROGRAMMING_FINISHED = 0x7,
};

/** Pulseblaster instruction word size */
#define PB_WORDSIZE 10

/** Pulseblaster devices */
enum pulseblaster_devices {
	PB_PROGRAM_MEMORY = 0x00,
};

#endif /* _PULSEBLASTER_H */
