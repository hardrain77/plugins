<?php
/**
 * i-MSCP SpamAssassin plugin
 * Copyright (C) 2014 Sascha Bay <info@space2place.de>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

$spamAssassinDbName = iMSCP_Registry::get('config')->DATABASE_NAME . '_spamassassin';

return array(
	'up' => "
		CREATE TABLE IF NOT EXISTS ". $spamAssassinDbName .".`bayes_token` (
			`id` int(11) NOT NULL DEFAULT '0',
			`token` char(5) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
			`spam_count` int(11) NOT NULL DEFAULT '0',
			`ham_count` int(11) NOT NULL DEFAULT '0',
			`atime` int(11) NOT NULL DEFAULT '0',
			PRIMARY KEY (`id`,`token`),
			KEY `bayes_token_idx1` (`id`,`atime`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
	",
	'down' => ''
);
