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
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301, USA.
 */

#ifndef _PULSEBLASTER_COMPAT_H
#define _PULSEBLASTER_COMPAT_H

#include <linux/version.h>

/* On kernels earlier than 2.6.35, there is no "filp" argument in the
 * bin_attribute write() method.
 */
#if LINUX_VERSION_CODE < KERNEL_VERSION(2,6,35)
#define pb_attr_program_write(filp, kobj, attr, buf, off, len) \
	pb_attr_program_write(kobj, attr, buf, off, len)
#define pb_attr_bin_write(filp, kobj, attr, buf, off, len, handle) \
	pb_attr_bin_write(kobj, attr, buf, off, len, handle)
#endif /* 2.6.35 */

#endif /* _PULSEBLASTER_COMPAT_H */
