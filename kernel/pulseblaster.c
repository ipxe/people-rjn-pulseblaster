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
#include <linux/sched.h>
#include "pulseblaster.h"

/** Pulseblaster driver name */
#define PB_NAME "pulseblaster"

/** Pulseblaster class */
static struct class *pb_class;

/** Automatically stop devices on module load */
static int autostop;

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
#define PB_OLD_AMCC_MAX_RETRIES 10

/**
 * Write to old AMCC bridge output register
 *
 * @pb:			Pulseblaster device
 * @data:		Data value
 */
static inline void pb_old_amcc_out(struct pulseblaster *pb, unsigned int data)
{
	outb(data, (pb->iobase + PB_OLD_AMCC_OUT));
}

/**
 * Read from old AMCC bridge input register
 *
 * @pb:			Pulseblaster device
 */
static inline unsigned int pb_old_amcc_in(struct pulseblaster *pb)
{
	return inb(pb->iobase + PB_OLD_AMCC_IN);
}

/**
 * Wait for device to reach specified state
 *
 * @pb:			Pulseblaster device
 * @state:		Desired state
 */
static int pb_old_amcc_wait(struct pulseblaster *pb, unsigned int state)
{
	uint8_t data;
	unsigned int retries;

	for (retries = 0 ; retries <= PB_OLD_AMCC_MAX_RETRIES ; retries++) {
		data = pb_old_amcc_in(pb);
		if ((data & 0x07) == state) {
			if (retries) {
				printk(KERN_INFO "%s: needed %d retries to "
				       "reach state 0x%02x\n",
				       pb->name, retries, state);
			}
			return 0;
		}
		pb_old_amcc_out(pb, (data << 4));
		msleep_interruptible(1);
		if (signal_pending(current))
			return -EINTR;
	}

	printk(KERN_ERR "%s: bridge stuck waiting for state 0x%02x\n",
	       pb->name, state);
	return -ETIMEDOUT;
}

/**
 * Write byte to device
 *
 * @pb:			Pulseblaster device
 * @address:		Register address
 * @data:		Data value
 */
static int pb_old_amcc_writeb(struct pulseblaster *pb, unsigned int address,
			      unsigned int data)
{
	struct {
		uint8_t out;
		uint8_t wait;
	} seq[4];
	unsigned int i;
	int rc;

	/* Check device is idle */
	rc = pb_old_amcc_wait(pb, 0x07);
	if (rc)
		return rc;

	/* Construct data sequence */
	seq[0].out = (0x30 | ((address >> 4) & 0x0f));
	seq[0].wait = 0x00;
	seq[1].out = (0x00 | ((address >> 0) & 0x0f));
	seq[1].wait = 0x01;
	seq[2].out = (0x10 | ((data >> 4) & 0x0f));
	seq[2].wait = 0x02;
	seq[3].out = (0x20 | ((data >> 0) & 0x0f));
	seq[3].wait = 0x07;

	/* Write out data */
	for (i = 0 ; i < ARRAY_SIZE(seq) ; i++) {
		pb_old_amcc_out(pb, seq[i].out);
		rc = pb_old_amcc_wait(pb, seq[i].wait);
		if (rc)
			return rc;
	}

	return 0;
}

/** Old AMCC bridge type */
static struct pulseblaster_type pb_old_amcc_type = {
	.name	= "pbd02pc",
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
 * @pb:			Pulseblaster device
 */
static inline int pb_cmd_stop(struct pulseblaster *pb)
{
	return pb_writeb(pb, PB_DEVICE_RESET, 0);
}

/**
 * Start program
 *
 * @pb:			Pulseblaster device
 */
static inline int pb_cmd_start(struct pulseblaster *pb)
{
	return pb_writeb(pb, PB_DEVICE_START, 0);
}

/**
 * Select number of bytes per word
 *
 * @pb:			Pulseblaster device
 * @bpw:		Number of bytes per word
 */
static inline int pb_cmd_select_bpw(struct pulseblaster *pb, unsigned int bpw)
{
	return pb_writeb(pb, PB_SELECT_BPW, bpw);
}

/**
 * Select device to program
 *
 * @pb:			Pulseblaster device
 * @dev:		Device to program
 */
static inline int pb_cmd_select_device(struct pulseblaster *pb,
				       unsigned int dev)
{
	return pb_writeb(pb, PB_SELECT_DEVICE, dev);
}

/**
 * Clear address counter
 *
 * @pb:			Pulseblaster device
 */
static inline int pb_cmd_clear_address_counter(struct pulseblaster *pb)
{
	return pb_writeb(pb, PB_CLEAR_ADDRESS_COUNTER, 0);
}

/**
 * Strobe output clock signal
 *
 * @pb:			Pulseblaster device
 */
static inline int pb_cmd_strobe(struct pulseblaster *pb)
{
	return pb_writeb(pb, PB_FLAG_STROBE, 0);
}

/**
 * Transfer data
 *
 * @pb:			Pulseblaster device
 * @data:		Data to transfer
 */
static inline int pb_cmd_transfer(struct pulseblaster *pb, unsigned int data)
{
	return pb_writeb(pb, PB_DATA_TRANSFER, data);
}

/**
 * Mark programming as finished
 *
 * @pb:			Pulseblaster device
 */
static inline int pb_cmd_finished(struct pulseblaster *pb)
{
	return pb_writeb(pb, PB_PROGRAMMING_FINISHED, 0);
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
 * @pb:			Pulseblaster device
 */
static int pb_write_enable(struct pulseblaster *pb)
{
	int rc;

	rc = pb_cmd_stop(pb);
	if (rc)
		return rc;
	rc = pb_cmd_select_bpw(pb, PB_WORDSIZE);
	if (rc)
		return rc;
	rc = pb_cmd_select_device(pb, PB_PROGRAM_MEMORY);
	if (rc)
		return rc;
	rc = pb_cmd_clear_address_counter(pb);
	if (rc)
		return rc;
	pb->offset = 0;

	return 0;
}

/**
 * Arm program
 *
 * @pb:			Pulseblaster device
 */
static int pb_arm(struct pulseblaster *pb)
{
	int rc;

	if (pb->offset == 0) {
		rc = pb_write_enable(pb);
		if (rc)
			return rc;
	}
	rc = pb_cmd_finished(pb);
	if (rc)
		return rc;
	pb->offset = 0;

	return 0;
}

/**
 * Start program
 *
 * @pb:			Pulseblaster device
 */
static int pb_start(struct pulseblaster *pb)
{
	int rc;

	rc = pb_arm(pb);
	if (rc)
		return rc;
	rc = pb_cmd_start(pb);
	if (rc)
		return rc;

	return 0;
}

/**
 * Stop program
 *
 * @pb:			Pulseblaster device
 */
static int pb_stop(struct pulseblaster *pb)
{
	int rc;

	if (pb->offset != 0) {
		rc = pb_cmd_finished(pb);
		if (rc)
			return rc;
		pb->offset = 0;
	}
	rc = pb_cmd_stop(pb);
	if (rc)
		return rc;

	return 0;
}

/**
 * Program device
 *
 * @pb:			Pulseblaster device
 * @buf:		Data buffer
 * @off:		Starting offset
 * @len:		Length of data
 */
static int pb_program(struct pulseblaster *pb, char *buf, loff_t off,
		      size_t len)
{
	int rc;

	if (off == 0) {
		rc = pb_write_enable(pb);
		if (rc)
			return rc;
	}
	if (off != pb->offset) {
		printk(KERN_ERR "%s: cannot perform out-of-order write to "
		       "0x%llx while at 0x%llx\n", pb->name, off, pb->offset);
		return -ENOTSUPP;
	}
	for (; len ; len--, buf++, pb->offset++) {
		rc = pb_cmd_transfer(pb, *buf);
		if (rc)
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
 * @kobj:		Kernel object
 * @attr:		Attribute
 * @buf:		Data buffer
 * @off:		Starting offset
 * @len:		Length of data
 * @handle:		Attribute handler
 */
static ssize_t pb_attr_bin_write(struct kobject *kobj,
				 struct bin_attribute *attr __maybe_unused,
				 char *buf, loff_t off, size_t len,
				 int (*handle)(struct pulseblaster *pb,
					       char *buf, loff_t off,
					       size_t len))
{
	struct device *dev = container_of(kobj, struct device, kobj);
	struct pulseblaster *pb = dev_get_drvdata(dev);
	int rc;

	/* Lock device */
	rc = down_interruptible(&pb->sem);
	if (rc)
		goto err_down;

	/* Handle attribute */
	rc = handle(pb, buf, off, len);
	if (rc)
		goto err_handle;

	/* Unlock device and return */
	up(&pb->sem);
	return len;

 err_handle:
	up(&pb->sem);
 err_down:
	return rc;
}

/**
 * Write to button attribute
 *
 * @dev:		Device
 * @attr:		Attribute
 * @buf:		Data buffer
 * @len:		Length of data buffer
 * @handle:		Attribute handler
 */
static ssize_t pb_attr_button_write(struct device *dev,
				    struct device_attribute *attr
					__maybe_unused,
				    const char *buf, size_t len,
				    int (*handle)(struct pulseblaster *pb))
{
	struct pulseblaster *pb = dev_get_drvdata(dev);
	unsigned long val;
	int rc;

	/* Lock device */
	rc = down_interruptible(&pb->sem);
	if (rc)
		goto err_down;

	/* Parse attribute */
	rc = strict_strtoul(buf, 0, &val);
	if (rc)
		goto err_strtoul;

	/* Handle attribute */
	if (val != 0) {
		rc = handle(pb);
		if (rc)
			goto err_handle;
	}

	/* Unlock device and return */
	up(&pb->sem);
	return len;

 err_handle:
 err_strtoul:
	up(&pb->sem);
 err_down:
	return rc;
}

/**
 * Read from type attribute
 *
 * @dev:		Device
 * @attr:		Attribute
 * @buf:		Data buffer
 */
static ssize_t pb_attr_type_read(struct device *dev,
				 struct device_attribute *attr __maybe_unused,
				 char *buf)
{
	struct pulseblaster *pb = dev_get_drvdata(dev);

	return sprintf(buf, "%s", pb->type->name);
}

/**
 * Write to start attribute
 *
 * @dev:		Device
 * @attr:		Attribute
 * @buf:		Data buffer
 * @len:		Length of data buffer
 */
static ssize_t pb_attr_start_write(struct device *dev,
				   struct device_attribute *attr,
				   const char *buf, size_t len)
{
	return pb_attr_button_write(dev, attr, buf, len, pb_start);
}

/**
 * Write to stop attribute
 *
 * @dev:		Device
 * @attr:		Attribute
 * @buf:		Data buffer
 * @len:		Length of data buffer
 */
static ssize_t pb_attr_stop_write(struct device *dev,
				  struct device_attribute *attr,
				  const char *buf, size_t len)
{
	return pb_attr_button_write(dev, attr, buf, len, pb_stop);
}

/**
 * Write to arm attribute
 *
 * @dev:		Device
 * @attr:		Attribute
 * @buf:		Data buffer
 * @len:		Length of data buffer
 */
static ssize_t pb_attr_arm_write(struct device *dev,
				 struct device_attribute *attr,
				 const char *buf, size_t len)
{
	return pb_attr_button_write(dev, attr, buf, len, pb_arm);
}

/**
 * Write to program attribute
 *
 * @kobj:		Kernel object
 * @attr:		Attribute
 * @buf:		Data buffer
 * @off:		Starting offset
 * @len:		Length of data
 */
static ssize_t pb_attr_program_write(struct kobject *kobj,
				     struct bin_attribute *attr,
				     char *buf, loff_t off, size_t len)
{
	return pb_attr_bin_write(kobj, attr, buf, off, len, pb_program);
}

/** Pulseblaster simple attributes */
static struct device_attribute pb_dev_attrs[] = {
	__ATTR(type, S_IRUGO, pb_attr_type_read, NULL),
	__ATTR(start, S_IWUSR, NULL, pb_attr_start_write),
	__ATTR(stop, S_IWUSR, NULL, pb_attr_stop_write),
	__ATTR(arm, S_IWUSR, NULL, pb_attr_arm_write),
};

/** Pulseblaster program attribute */
static struct bin_attribute dev_attr_program = {
	.attr = {
		.name = "program",
		.mode = S_IWUSR,
	},
	.write = pb_attr_program_write,
};

/*****************************************************************************
 *
 * Power management
 *
 *****************************************************************************
 */

/**
 * Suspend device
 *
 * @pci:		PCI device
 * @state:		Power state
 */
static int __maybe_unused pb_suspend(struct pci_dev *pci, pm_message_t state)
{
	struct pulseblaster *pb = pci_get_drvdata(pci);

	/* Prepare to suspend PCI device */
	pci_save_state(pci);
	pci_disable_device(pci);
	pci_set_power_state(pci, pci_choose_state(pci, state));

	/* Reset state that will be destroyed by powering off */
	pb->offset = 0;

	return 0;
}

/**
 * Resume device
 *
 * @pci:		PCI device
 */
static int __maybe_unused pb_resume(struct pci_dev *pci)
{
	int rc;

	/* Restore PCI device */
	pci_set_power_state(pci, PCI_D0);
	rc = pci_enable_device(pci);
	if (rc)
		return rc;
	pci_restore_state(pci);

	return 0;
}

/*****************************************************************************
 *
 * Device probe and remove
 *
 *****************************************************************************
 */

/**
 * Identify device
 *
 * @pb:			Pulseblaster device
 * @type:		Pulseblaster device type
 */
static int __devinit pb_identify(struct pulseblaster *pb,
				 enum pulseblaster_type_key type)
{
	switch (type) {
	case PB_OLD_AMCC:
		pb->type = &pb_old_amcc_type;
		break;
	default:
		printk(KERN_ERR "%s: unknown type %d\n", pb->name, type);
		return -ENOTSUPP;
	}

	return 0;
}

/**
 * Initialise device
 *
 * @pci:		PCI device
 * @id:			PCI device ID
 */
static int __devinit pb_probe(struct pci_dev *pci,
			      const struct pci_device_id *id)
{
	static unsigned int pbidx;
	struct pulseblaster *pb;
	int rc;

	/* Allocate and initialise structure */
	pb = kzalloc(sizeof(*pb), GFP_KERNEL);
	if (!pb) {
		rc = -ENOMEM;
		goto err_alloc;
	}
	sema_init(&pb->sem, 1);
	snprintf(pb->name, sizeof(pb->name), PB_NAME "%d", pbidx++);

	/* Enable PCI device */
	rc = pci_enable_device(pci);
	if (rc)
		goto err_enable_device;

	/* Request regions */
	rc = pci_request_regions(pci, PB_NAME);
	if (rc)
		goto err_request_regions;

	/* Set I/O base address */
	pb->iobase = pci_resource_start(pci, 0);

	/* Identify device */
	rc = pb_identify(pb, id->driver_data);
	if (rc)
		goto err_identify;

	/* Create class device */
	pb->dev = device_create(pb_class, &pci->dev, 0, pb, pb->name);
	if (IS_ERR(pb->dev)) {
		rc = PTR_ERR(pb->dev);
		goto err_device_create;
	}
	printk(KERN_INFO "%s: I/O at 0x%04lx\n", pb->name, pb->iobase);

	/* Create program attribute */
	rc = device_create_bin_file(pb->dev, &dev_attr_program);
	if (rc)
		goto err_device_create_bin_file;

	/* Stop device, if autostop is enabled */
	if (autostop) {
		rc = pb_cmd_stop(pb);
		if (rc)
			goto err_autostop;
	}

	pci_set_drvdata(pci, pb);
	return 0;

 err_autostop:
	device_remove_bin_file(pb->dev, &dev_attr_program);
 err_device_create_bin_file:
	device_unregister(pb->dev);
 err_device_create:
 err_identify:
	pci_release_regions(pci);
 err_request_regions:
	pci_disable_device(pci);
 err_enable_device:
	kfree(pb);
 err_alloc:
	return rc;
}

/**
 * Remove device
 *
 * @pci:		PCI device
 */
static void __devexit pb_remove(struct pci_dev *pci)
{
	struct pulseblaster *pb = pci_get_drvdata(pci);

	device_remove_bin_file(pb->dev, &dev_attr_program);
	device_unregister(pb->dev);
	pci_release_regions(pci);
	pci_disable_device(pci);
	kfree(pb);
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
	.remove		= __devexit_p(pb_remove),
#ifdef CONFIG_PM
	.suspend	= pb_suspend,
	.resume		= pb_resume,
#endif /* CONFIG_PM */
};

/**
 * Initialise Pulseblaster module
 *
 */
static int __init pb_module_init(void)
{
	int rc;

	/* Register class */
	pb_class = class_create(THIS_MODULE, PB_NAME);
	if (IS_ERR(pb_class)) {
		rc = PTR_ERR(pb_class);
		goto err_class_create;
	}
	pb_class->dev_attrs = pb_dev_attrs;

	/* Register PCI driver */
	rc = pci_register_driver(&pb_pci_driver);
	if (rc)
		goto err_pci_register_driver;

	return 0;

	pci_unregister_driver(&pb_pci_driver);
 err_pci_register_driver:
	class_destroy(pb_class);
 err_class_create:
	return rc;
}

/**
 * Remove Pulseblaster module
 *
 */
static void __exit pb_module_exit(void)
{
	pci_unregister_driver(&pb_pci_driver);
	class_destroy(pb_class);
}

module_init(pb_module_init);
module_exit(pb_module_exit);

module_param(autostop, int, 0);
MODULE_PARM_DESC(autostop, "Automatically stop device on module load");

MODULE_AUTHOR("Michael Brown <mbrown@fensystems.co.uk>");
MODULE_DESCRIPTION("SpinCore PulseBlaster driver");
MODULE_LICENSE("GPL");
MODULE_VERSION("0.1");
MODULE_DEVICE_TABLE(pci, pb_pci_tbl);
