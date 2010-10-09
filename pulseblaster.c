#include <linux/module.h>
#include <linux/kernel.h>
#include <linux/pci.h>
#include <linux/init.h>

/** Pulseblaster driver name */
#define PB_NAME "pulseblaster"

/** A Pulseblaster device */
struct pulseblaster {
	/** I/O port base address */
	unsigned long iobase;
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
	struct pulseblaster *pb;
	int rc;

	/* Allocate and initialise structure */
	pb = kmalloc ( sizeof ( *pb ), GFP_KERNEL );
	if ( ! pb ) {
		rc = -ENOMEM;
		goto err_alloc;
	}

	/* Enable PCI device */
	if ( ( rc = pci_enable_device ( pci ) ) != 0 )
		goto err_enable_device;

	/* Request regions */
	if ( ( rc = pci_request_regions ( pci, PB_NAME ) ) != 0 )
		goto err_request_regions;

	/* Set I/O base address */
	pb->iobase = pci_resource_start ( pci, 0 );

	printk ( PB_NAME " at %04lx\n", pb->iobase );
	pci_set_drvdata ( pci, pb );
	return 0;

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
	return pci_register_driver ( &pb_pci_driver );
}

/**
 * Remove Pulseblaster module
 *
 */
static void __exit pb_module_exit ( void ) {
	pci_unregister_driver ( &pb_pci_driver );
}

module_init(pb_module_init);
module_exit(pb_module_exit);

MODULE_AUTHOR ( "Michael Brown <mbrown@fensystems.co.uk>" );
MODULE_DESCRIPTION ( "SpinCore PulseBlaster driver" );
MODULE_LICENSE ( "GPL" );
MODULE_VERSION ( "0.1" );
MODULE_DEVICE_TABLE ( pci, pb_pci_tbl );
