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

/** Pulseblaster register addresses */
enum pulseblaster_register {
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
enum pulseblaster_device {
	PB_PROGRAM_MEMORY = 0x00,
};

/** Pulseblaster PCI devices */
enum pulseblaster_type {
	PB_OLD_AMCC = 1,
};

struct pulseblaster;

/** Pulseblaster device operations */
struct pulseblaster_operations {
	/**
	 * Write byte to device
	 *
	 * @pb:			Pulseblaster device
	 * @address:		Register address
	 * @data:		Data value
	 */
	int (*writeb)(struct pulseblaster *pb, unsigned int address,
		      unsigned int data);
};

/** A Pulseblaster device */
struct pulseblaster {
	/** Device name */
	char name[16];
	/** I/O port base address */
	unsigned long iobase;
	/** Class device */
	struct device *dev;
	/** Device access semaphore */
	struct semaphore sem;
	/** Programming address counter */
	loff_t offset;
	/** Operations */
	struct pulseblaster_operations *op;
};

/**
 * Write byte to device
 *
 * @pb:			Pulseblaster device
 * @address:		Register address
 * @data:		Data value
 */
static inline int pb_writeb(struct pulseblaster *pb, unsigned int address,
			    unsigned int data) {
	return pb->op->writeb(pb, address, data);
}

#endif /* _PULSEBLASTER_H */
