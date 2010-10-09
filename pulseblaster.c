#include <linux/module.h>
#include <linux/kernel.h>
#include <linux/pci.h>
#include <linux/init.h>

/** Pulseblaster driver name */
#define PB_NAME "pulseblaster"

/** Pulseblaster instruction word size */
#define PB_WORDSIZE 10

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
};

/**
 * Program Pulseblaster device
 *
 */
static ssize_t pulseblaster_program ( struct kobject *kobj,
				      struct bin_attribute *attr,
				      char *buf, loff_t off, size_t size ) {
	struct device *dev = container_of ( kobj, struct device, kobj );
	struct pulseblaster *pb = dev_get_drvdata ( dev );
	int rc;

	/* Lock device */
	if ( ( rc = down_interruptible ( &pb->sem ) ) != 0 )
		goto err_down;

	printk ( "%s: program %#04zx bytes at %#04llx\n",
		 dev_name ( dev ), size, off );

	/* Unlock device and return */
	up ( &pb->sem );
	return size;

	up ( &pb->sem );
 err_down:
	return rc;
}

/** Pulseblaster program device attribute */
static struct bin_attribute dev_attr_program = {
	.attr = {
		.name = "program",
		.mode = ( S_IWUSR | S_IRUGO ),
	},
	.size = 32768 * PB_WORDSIZE,
	.write = pulseblaster_program,
};

/**
 * Initialise Pulseblaster device
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

	/* Create program attribute */
	if ( ( rc = device_create_bin_file ( pb->dev,
					     &dev_attr_program ) ) != 0 ) {
		goto err_device_create_bin_file;
	}

	printk ( "%s: I/O at %04lx\n", dev_name ( pb->dev ), pb->iobase );
	pci_set_drvdata ( pci, pb );
	return 0;

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
 * Remove Pulseblaster device
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

module_init(pb_module_init);
module_exit(pb_module_exit);

MODULE_AUTHOR ( "Michael Brown <mbrown@fensystems.co.uk>" );
MODULE_DESCRIPTION ( "SpinCore PulseBlaster driver" );
MODULE_LICENSE ( "GPL" );
MODULE_VERSION ( "0.1" );
MODULE_DEVICE_TABLE ( pci, pb_pci_tbl );
