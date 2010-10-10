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

#include <linux/module.h>
#include <linux/kernel.h>
#include <linux/pci.h>
#include <linux/init.h>
#include "pulseblaster.h"

/** Pulseblaster driver name */
#define PB_NAME "pulseblaster"

/** Pulseblaster class */
static struct class *pb_class;

/** Automatically stop devices on module load */
static int autostop = 0;

/*****************************************************************************
 *
 * Old AMCC bridge protocol
 *
 *****************************************************************************
 *
 * This protocol is not documented anywhere except in source code form.
 *
 */

/** Old AMCC bridge registers */
enum pulseblaster_old_amcc_register {
	PB_OLD_AMCC_OUT	= 0x0c,
	PB_OLD_AMCC_IN	= 0x1c,
};

/** Maximum number of retry attempts */
#define PB_OLD_AMCC_MAX_RETRIES 100

/**
 * Write to old AMCC bridge output register
 *
 * @v pb		Pulseblaster device
 * @v data		Data value
 */
static inline void pb_old_amcc_out ( struct pulseblaster *pb,
				     unsigned int data ) {
	outb ( data, ( pb->iobase + PB_OLD_AMCC_OUT ) );
}

/**
 * Read from old AMCC bridge input register
 *
 * @v pb		Pulseblaster device
 * @ret data		Data value
 */
static inline unsigned int pb_old_amcc_in ( struct pulseblaster *pb ) {
	return ( inb ( pb->iobase + PB_OLD_AMCC_IN ) );
}

/**
 * Wait for device to reach specified state
 *
 * @v pb		Pulseblaster device
 * @v state		Desired state
 * @ret rc		Return status code
 */
static int pb_old_amcc_wait ( struct pulseblaster *pb, unsigned int state ) {
	uint8_t data;
	unsigned int retries;

	for ( retries = 0 ; retries <= PB_OLD_AMCC_MAX_RETRIES ; retries++ ) {
		data = pb_old_amcc_in ( pb );
		if ( ( data & 0x07 ) == state ) {
			if ( retries ) {
				printk ( KERN_INFO "%s: needed %d retries\n",
					 pb->name, retries );
			}
			return 0;
		}
		pb_old_amcc_out ( pb, ( data << 4 ) );
	}
	printk ( KERN_ERR "%s: bridge stuck waiting for state %d\n",
		 pb->name, state );
	return -ETIMEDOUT;
}

/**
 * Write byte to device
 *
 * @v pb		Pulseblaster device
 * @v address		Register address
 * @v data		Data value
 * @ret rc		Return status code
 */
static int pb_old_amcc_writeb ( struct pulseblaster *pb, unsigned int address,
				unsigned int data ) {
	struct {
		uint8_t out;
		uint8_t wait;
	} seq[4];
	unsigned int i;
	int rc;

	/* Check device is idle */
	if ( ( rc = pb_old_amcc_wait ( pb, 0x07 ) ) != 0 )
		return rc;

	/* Construct data sequence */
	seq[0].out = ( 0x30 | ( ( address >> 4 ) & 0x0f ) );
	seq[0].wait = 0x00;
	seq[1].out = ( 0x00 | ( ( address >> 0 ) & 0x0f ) );
	seq[1].wait = 0x01;
	seq[2].out = ( 0x10 | ( ( data >> 4 ) & 0x0f ) );
	seq[2].wait = 0x02;
	seq[3].out = ( 0x20 | ( ( data >> 0 ) & 0x0f ) );
	seq[3].wait = 0x07;

	/* Write out data */
	for ( i = 0 ; i < ARRAY_SIZE ( seq ) ; i++ ) {
		pb_old_amcc_out ( pb, seq[i].out );
		if ( ( rc = pb_old_amcc_wait ( pb, seq[i].wait ) ) != 0 )
			return rc;
	}

	return 0;
}

/** Old AMCC bridge protocol */
static struct pulseblaster_operations pb_old_amcc_op = {
	.writeb	= pb_old_amcc_writeb,
};

/*****************************************************************************
 *
 * Command primitives
 *
 *****************************************************************************
 */

/**
 * Stop program
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_stop ( struct pulseblaster *pb ) {
	return pb_writeb ( pb, PB_DEVICE_RESET, 0 );
}

/**
 * Start program
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_start ( struct pulseblaster *pb ) {
	return pb_writeb ( pb, PB_DEVICE_START, 0 );
}

/**
 * Select number of bytes per word
 *
 * @v pb		Pulseblaster device
 * @v bpw		Number of bytes per word
 * @ret rc		Return status code
 */
static inline int pb_cmd_select_bpw ( struct pulseblaster *pb,
				      unsigned int bpw ) {
	return pb_writeb ( pb, PB_SELECT_BPW, bpw );
}

/**
 * Select device to program
 *
 * @v pb		Pulseblaster device
 * @v dev		Device to program
 * @ret rc		Return status code
 */
static inline int pb_cmd_select_device ( struct pulseblaster *pb,
					 unsigned int dev ) {
	return pb_writeb ( pb, PB_SELECT_DEVICE, dev );
}

/**
 * Clear address counter
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_clear_address_counter ( struct pulseblaster *pb ) {
	return pb_writeb ( pb, PB_CLEAR_ADDRESS_COUNTER, 0 );
}

/**
 * Strobe output clock signal
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_strobe ( struct pulseblaster *pb ) {
	return pb_writeb ( pb, PB_FLAG_STROBE, 0 );
}

/**
 * Transfer data
 *
 * @v pb		Pulseblaster device
 * @v data		Data to transfer
 * @ret rc		Return status code
 */
static inline int pb_cmd_transfer ( struct pulseblaster *pb,
				    unsigned int data ) {
	return pb_writeb ( pb, PB_DATA_TRANSFER, data );
}

/**
 * Mark programming as finished
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_finished ( struct pulseblaster *pb ) {
	return pb_writeb ( pb, PB_PROGRAMMING_FINISHED, 0 );
}

/*****************************************************************************
 *
 * High-level commands
 *
 *****************************************************************************
 */

/**
 * Prepare for programming
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static int pb_write_enable ( struct pulseblaster *pb ) {
	int rc;

	if ( ( rc = pb_cmd_stop ( pb ) ) != 0 )
		return rc;
	if ( ( rc = pb_cmd_select_bpw ( pb, PB_WORDSIZE ) ) != 0 )
		return rc;
	if ( ( rc = pb_cmd_select_device ( pb, PB_PROGRAM_MEMORY ) ) != 0 )
		return rc;
	if ( ( rc = pb_cmd_clear_address_counter ( pb ) ) != 0 )
		return rc;
	pb->offset = 0;

	return 0;
}

/**
 * Arm program
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static int pb_arm ( struct pulseblaster *pb ) {
	int rc;

	if ( pb->offset == 0 ) {
		if ( ( rc = pb_write_enable ( pb ) ) != 0 )
			return rc;
	}
	if ( ( rc = pb_cmd_finished ( pb ) ) != 0 )
		return rc;
	pb->offset = 0;

	return 0;
}

/**
 * Start program
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static int pb_start ( struct pulseblaster *pb ) {
	int rc;

	if ( ( rc = pb_arm ( pb ) ) != 0 )
		return rc;
	if ( ( rc = pb_cmd_start ( pb ) ) != 0 )
		return rc;

	return 0;
}

/**
 * Stop program
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static int pb_stop ( struct pulseblaster *pb ) {
	int rc;

	if ( pb->offset != 0 ) {
		if ( ( rc = pb_cmd_finished ( pb ) ) != 0 )
			return rc;
		pb->offset = 0;
	}
	if ( ( rc = pb_cmd_stop ( pb ) ) != 0 )
		return rc;

	return 0;
}

/**
 * Program device
 *
 * @v pb		Pulseblaster device
 * @v buf		Data buffer
 * @v off		Starting offset
 * @v len		Length of data
 * @ret rc		Return status code
 */
static int pb_program ( struct pulseblaster *pb, char *buf, loff_t off,
			size_t len ) {
	int rc;

	if ( off == 0 ) {
		if ( ( rc = pb_write_enable ( pb ) ) != 0 )
			return rc;
	}
	if ( off != pb->offset ) {
		printk ( KERN_ERR "%s: cannot perform out-of-order write to "
			 "0x%llx while at 0x%llx\n",
			 pb->name, off, pb->offset );
		return -ENOTSUPP;
	}
	for ( ; len ; len--, buf++, pb->offset++ ) {
		if ( ( rc = pb_cmd_transfer ( pb, *buf ) ) != 0 )
			return rc;
	}
	return 0;
}

/*****************************************************************************
 *
 * Sysfs attributes
 *
 *****************************************************************************
 */

/**
 * Write to binary attribute
 *
 * @v kobj		Kernel object
 * @v attr		Attribute
 * @v buf		Data buffer
 * @v off		Starting offset
 * @v len		Length of data
 * @v handle		Attribute handler
 * @ret len		Length written, or negative error
 */
static ssize_t pb_attr_bin ( struct kobject *kobj,
			     struct bin_attribute *attr,
			     char *buf, loff_t off, size_t len,
			     int ( * handle ) ( struct pulseblaster *pb,
						char *buf, loff_t off,
						size_t len ) ) {
	struct device *dev = container_of ( kobj, struct device, kobj );
	struct pulseblaster *pb = dev_get_drvdata ( dev );
	int rc;

	/* Lock device */
	if ( ( rc = down_interruptible ( &pb->sem ) ) != 0 )
		goto err_down;

	/* Handle attribute */
	if ( ( rc = handle ( pb, buf, off, len ) ) != 0 )
		goto err_handle;

	/* Unlock device and return */
	up ( &pb->sem );
	return len;

 err_handle:
	up ( &pb->sem );
 err_down:
	return rc;
}

/**
 * Write to button attribute
 *
 * @v dev		Device
 * @v attr		Attribute
 * @v buf		Data buffer
 * @v len		Length of data buffer
 * @v handle		Attribute handler
 * @ret len		Length written, or negative error
 */
static ssize_t pb_attr_button ( struct device *dev,
				struct device_attribute *attr,
				const char *buf, size_t len,
				int ( * handle ) ( struct pulseblaster *pb ) ){
	struct pulseblaster *pb = dev_get_drvdata ( dev );
	unsigned long val;
	int rc;

	/* Lock device */
	if ( ( rc = down_interruptible ( &pb->sem ) ) != 0 )
		goto err_down;

	/* Parse attribute */
	if ( ( rc = strict_strtoul ( buf, 0, &val ) ) != 0 )
		goto err_strtoul;

	/* Handle attribute */
	if ( val != 0 ) {
		if ( ( rc = handle ( pb ) ) != 0 )
			goto err_handle;
	}

	/* Unlock device and return */
	up ( &pb->sem );
	return len;

 err_handle:
 err_strtoul:
	up ( &pb->sem );
 err_down:
	return rc;
}

/**
 * Write to start attribute
 *
 * @v dev		Device
 * @v attr		Attribute
 * @v buf		Data buffer
 * @v len		Length of data buffer
 * @ret len		Length written, or negative error
 */
static ssize_t pb_attr_start ( struct device *dev,
			       struct device_attribute *attr,
			       const char *buf, size_t len ) {
	return pb_attr_button ( dev, attr, buf, len, pb_start );
}

/**
 * Write to stop attribute
 *
 * @v dev		Device
 * @v attr		Attribute
 * @v buf		Data buffer
 * @v len		Length of data buffer
 * @ret len		Length written, or negative error
 */
static ssize_t pb_attr_stop ( struct device *dev,
			      struct device_attribute *attr,
			      const char *buf, size_t len ) {
	return pb_attr_button ( dev, attr, buf, len, pb_stop );
}

/**
 * Write to arm attribute
 *
 * @v dev		Device
 * @v attr		Attribute
 * @v buf		Data buffer
 * @v len		Length of data buffer
 * @ret len		Length written, or negative error
 */
static ssize_t pb_attr_arm ( struct device *dev,
			     struct device_attribute *attr,
			     const char *buf, size_t len ) {
	return pb_attr_button ( dev, attr, buf, len, pb_arm );
}

/**
 * Write to program attribute
 *
 * @v kobj		Kernel object
 * @v attr		Attribute
 * @v buf		Data buffer
 * @v off		Starting offset
 * @v len		Length of data
 * @ret len		Length written, or negative error
 */
static ssize_t pb_attr_program ( struct kobject *kobj,
				 struct bin_attribute *attr,
				 char *buf, loff_t off, size_t size ) {
	return pb_attr_bin ( kobj, attr, buf, off, size, pb_program );
}

/** Pulseblaster simple attributes */
static struct device_attribute pb_dev_attrs[] = {
	__ATTR ( start, S_IWUSR, NULL, pb_attr_start ),
	__ATTR ( stop, S_IWUSR, NULL, pb_attr_stop ),
	__ATTR ( arm, S_IWUSR, NULL, pb_attr_arm ),
};

/** Pulseblaster program attribute */
static struct bin_attribute dev_attr_program = {
	.attr = {
		.name = "program",
		.mode = S_IWUSR,
	},
	.write = pb_attr_program,
};

/*****************************************************************************
 *
 * Device probe and remove
 *
 *****************************************************************************
 */

/**
 * Identify device
 *
 * @v pb		Pulseblaster device
 * @v type		Pulseblaster device type
 * @ret rc		Return status code
 */
static int __devinit pb_identify ( struct pulseblaster *pb,
				   enum pulseblaster_type type ) {

	switch ( type ) {
	case PB_OLD_AMCC:
		pb->op = &pb_old_amcc_op;
		break;
	default:
		printk ( KERN_ERR "%s: unknown type %d\n", pb->name, type );
		return -ENOTSUPP;
	}

	return 0;
}

/**
 * Initialise device
 *
 * @v pci		PCI device
 * @v id		PCI device ID
 * @ret rc		Return status code
 */
static int __devinit pb_probe ( struct pci_dev *pci,
				const struct pci_device_id *id ) {
	static unsigned int pbidx = 0;
	struct pulseblaster *pb;
	int rc;

	/* Allocate and initialise structure */
	pb = kzalloc ( sizeof ( *pb ), GFP_KERNEL );
	if ( ! pb ) {
		rc = -ENOMEM;
		goto err_alloc;
	}
	sema_init ( &pb->sem, 1 );
	snprintf ( pb->name, sizeof ( pb->name ), PB_NAME "%d", pbidx++ );

	/* Enable PCI device */
	if ( ( rc = pci_enable_device ( pci ) ) != 0 )
		goto err_enable_device;

	/* Request regions */
	if ( ( rc = pci_request_regions ( pci, PB_NAME ) ) != 0 )
		goto err_request_regions;

	/* Set I/O base address */
	pb->iobase = pci_resource_start ( pci, 0 );

	/* Identify device */
	if ( ( rc = pb_identify ( pb, id->driver_data ) ) != 0 )
		goto err_identify;

	/* Create class device */
	pb->dev = device_create ( pb_class, &pci->dev, 0, pb, pb->name );
	if ( IS_ERR ( pb->dev ) ) {
		rc = PTR_ERR ( pb->dev );
		goto err_device_create;
	}
	printk ( KERN_INFO "%s: I/O at %04lx\n", pb->name, pb->iobase );

	/* Create program attribute */
	if ( ( rc = device_create_bin_file ( pb->dev,
					     &dev_attr_program ) ) != 0 ) {
		goto err_device_create_bin_file;
	}

	/* Stop device, if autostop is enabled */
	if ( autostop ) {
		if ( ( rc = pb_cmd_stop ( pb ) ) != 0 )
			goto err_autostop;
	}

	pci_set_drvdata ( pci, pb );
	return 0;

 err_autostop:
	device_remove_bin_file ( pb->dev, &dev_attr_program );
 err_device_create_bin_file:
	device_unregister ( pb->dev );
 err_device_create:
 err_identify:
	pci_release_regions ( pci );
 err_request_regions:
	pci_disable_device ( pci );
 err_enable_device:
	kfree ( pb );
 err_alloc:
	return rc;
}

/**
 * Remove device
 *
 * @v pci		PCI device
 */
static void __devexit pb_remove ( struct pci_dev *pci ) {
	struct pulseblaster *pb = pci_get_drvdata ( pci );

	device_remove_bin_file ( pb->dev, &dev_attr_program );
	device_unregister ( pb->dev );
	pci_release_regions ( pci );
	pci_disable_device ( pci );
	kfree ( pb );
}

/*****************************************************************************
 *
 * Driver load and unload
 *
 *****************************************************************************
 */

/** Pulseblaster PCI IDs */
static struct pci_device_id pb_pci_tbl[] = {
	{ 0x10e8, 0x5920, PCI_ANY_ID, PCI_ANY_ID, 0, 0, PB_OLD_AMCC },
	{ 0, }
};

/** Pulseblaster PCI driver */
static struct pci_driver pb_pci_driver = {
	.name		= PB_NAME,
	.id_table	= pb_pci_tbl,
	.probe		= pb_probe,
	.remove		= __devexit_p ( pb_remove ),
};

/**
 * Initialise Pulseblaster module
 *
 * @ret rc		Return status code
 */
static int __init pb_module_init ( void ) {
	int rc;

	/* Register class */
	pb_class = class_create ( THIS_MODULE, PB_NAME );
	if ( IS_ERR ( pb_class ) ) {
		rc = PTR_ERR ( pb_class );
		goto err_class_create;
	}
	pb_class->dev_attrs = pb_dev_attrs;

	/* Register PCI driver */
	if ( ( rc = pci_register_driver ( &pb_pci_driver ) ) != 0 )
		goto err_pci_register_driver;

	return 0;

	pci_unregister_driver ( &pb_pci_driver );
 err_pci_register_driver:
	class_destroy ( pb_class );
 err_class_create:
	return rc;
}

/**
 * Remove Pulseblaster module
 *
 */
static void __exit pb_module_exit ( void ) {
	pci_unregister_driver ( &pb_pci_driver );
	class_destroy ( pb_class );
}

module_init ( pb_module_init );
module_exit ( pb_module_exit );

module_param ( autostop, int, 0 );
MODULE_PARM_DESC ( autostop, "Automatically stop device on module load" );

MODULE_AUTHOR ( "Michael Brown <mbrown@fensystems.co.uk>" );
MODULE_DESCRIPTION ( "SpinCore PulseBlaster driver" );
MODULE_LICENSE ( "GPL" );
MODULE_VERSION ( "0.1" );
MODULE_DEVICE_TABLE ( pci, pb_pci_tbl );
