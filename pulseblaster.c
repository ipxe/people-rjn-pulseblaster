#include <linux/module.h>
#include <linux/kernel.h>
#include <linux/pci.h>
#include <linux/init.h>
#include "pulseblaster.h"

/** Pulseblaster driver name */
#define PB_NAME "pulseblaster"

/** Pulseblaster class */
static struct class *pb_class;

/** A Pulseblaster device */
struct pulseblaster {
	/** I/O port base address */
	unsigned long iobase;
	/** Class device */
	struct device *dev;
	/** Device access semaphore */
	struct semaphore sem;
	/** Programming address counter */
	loff_t offset;
};

/** Automatically stop devices on module load */
static int autostop = 0;

/**
 * Send command to device
 *
 * @v pb		Pulseblaster device
 * @v command		Command register
 * @v data		Data value
 * @ret rc		Return status code
 */
static int pb_cmd ( struct pulseblaster *pb, unsigned int command,
		    unsigned int data ) {

	printk ( "%s: cmd 0x%02x data 0x%02x\n",
		 dev_name ( pb->dev ), command, data );
	return 0;
}

/**
 * Stop program
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_stop ( struct pulseblaster *pb ) {
	return pb_cmd ( pb, PB_DEVICE_RESET, 0 );
}

/**
 * Start program
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_start ( struct pulseblaster *pb ) {
	return pb_cmd ( pb, PB_DEVICE_START, 0 );
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
	return pb_cmd ( pb, PB_SELECT_BPW, bpw );
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
	return pb_cmd ( pb, PB_SELECT_DEVICE, dev );
}

/**
 * Clear address counter
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_clear_address_counter ( struct pulseblaster *pb ) {
	return pb_cmd ( pb, PB_CLEAR_ADDRESS_COUNTER, 0 );
}

/**
 * Strobe output clock signal
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_strobe ( struct pulseblaster *pb ) {
	return pb_cmd ( pb, PB_FLAG_STROBE, 0 );
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
	return pb_cmd ( pb, PB_DATA_TRANSFER, data );
}

/**
 * Mark programming as finished
 *
 * @v pb		Pulseblaster device
 * @ret rc		Return status code
 */
static inline int pb_cmd_finished ( struct pulseblaster *pb ) {
	return pb_cmd ( pb, PB_PROGRAMMING_FINISHED, 0 );
}

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
		printk ( "%s: cannot perform out-of-order write to 0x%llx "
			 "while at 0x%llx\n", dev_name ( pb->dev ),
			 off, pb->offset );
		return -ENOTSUPP;
	}
	for ( ; len ; len--, buf++, pb->offset++ ) {
		if ( ( rc = pb_cmd_transfer ( pb, *buf ) ) != 0 )
			return rc;
	}
	return 0;
}

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
	__ATTR ( start, ( S_IWUSR | S_IRUGO ), NULL, pb_attr_start ),
	__ATTR ( stop, ( S_IWUSR | S_IRUGO ), NULL, pb_attr_stop ),
	__ATTR ( arm, ( S_IWUSR | S_IRUGO ), NULL, pb_attr_arm ),
};

/** Pulseblaster program attribute */
static struct bin_attribute dev_attr_program = {
	.attr = {
		.name = "program",
		.mode = ( S_IWUSR | S_IRUGO ),
	},
	.size = 32768 * PB_WORDSIZE,
	.write = pb_attr_program,
};

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

	/* Enable PCI device */
	if ( ( rc = pci_enable_device ( pci ) ) != 0 )
		goto err_enable_device;

	/* Request regions */
	if ( ( rc = pci_request_regions ( pci, PB_NAME ) ) != 0 )
		goto err_request_regions;

	/* Set I/O base address */
	pb->iobase = pci_resource_start ( pci, 0 );

	/* Create class device */
	pb->dev = device_create ( pb_class, &pci->dev, 0, pb,
				  PB_NAME "%d", pbidx++ );
	if ( IS_ERR ( pb->dev ) ) {
		rc = PTR_ERR ( pb->dev );
		goto err_device_create;
	}
	printk ( "%s: I/O at %04lx\n", dev_name ( pb->dev ), pb->iobase );

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

/** Pulseblaster PCI IDs */
static struct pci_device_id pb_pci_tbl[] = {
	{ 0x1217, 0x7130, PCI_ANY_ID, PCI_ANY_ID, 0, 0, 0 },
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
