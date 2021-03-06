###�i-MSCP RemoteBridge plugin v1.0.0 

Plugin providing an API which allows to manage i-MSCP accounts.

### LICENSE

Copyright (C) Sascha Bay <info@space2place.de>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

See [GPL v2](http://www.gnu.org/licenses/gpl-2.0.html "GPL v2")

### REQUIREMENTS

Plugin compatible with i-MSCP versions >= 1.2.17

** For security reasons, it is currently not recommend to use this plugin in production environment. USE IT ONLY FOR TESTS **

### INSTALLATION

	- Login into the panel as admin and go to the plugin management interface
	- Upload the RemoteBridge plugin archive (.zip or .tar.gz)
	- Activate the plugin
	- Login into the panel as reseller, and create a Bridge key add a server ipaddress which should have access to the remote bridge. (it is also possible to use ipv6)
	- Add the url http(s)://admin.server.example.org:8080/remotebridge.php to your website where you want to manage i-MSCP accounts from

### UPDATE

** Plugin upload and update **

	- Login into the panel as admin and go to the plugin management interface
	- Upload the RemoteBridge plugin archive
	- Update the plugin list through the plugin interface

### How to send data to the remote bridge (examples also available in sample folder)

	function dataEncryption($dataToEncrypt, $ResellerUsername) {
		return strtr(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($ResellerUsername), serialize($dataToEncrypt), MCRYPT_MODE_CBC, md5(md5($ResellerUsername)))), '+/=', '-_,');
	}
	$bridgeKey = '';
	$ResellerUsername = '';
	$ResellerPassword = '';

	$dataToEncrypt = array(
			'action'                => '',
			'reseller_username'     => $ResellerUsername,
			'reseller_password'     => $ResellerPassword,
			'bridge_key'            => $bridgeKey,
			'hosting_plan'			=> '',
			'admin_pass'            => '',
			'email'                 => '',
			'domain'                => ''
	);

	$ch = curl_init('http(s)://admin.server.example.org:8080/remotebridge.php');
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, 'key='.$bridgeKey.'&data='.dataEncryption($dataToEncrypt, $ResellerUsername));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	$httpResponse = curl_exec($ch);
	echo $httpResponse;
	curl_close($ch);

### Post data variables which are available / required

**1.** bridgeKey (required)

	- This is your own bridge key
	
**2.** data (required)

	- This is a encrypted data array
	
### Encrypted array variables which are available / required

**1.** action (required) (you can find samples for all working actions in "sample" folder)

	- This and more actions are available: get_users, create_user, enable_user, disable_user, delete_user, update_user, add_alias, get_mails, add_mail

**1.1.** action get_users

	- Gets all i-MSCP accounts

**1.2.** action create_user

	- Creates a new i-MSCP account

**1.3.** action enable_user

	- Enables an existing i-MSCP account

**1.4.** action disable_user

	- Disables an existing i-MSCP account

**1.5.** action delete_user

	- Deletes an existing i-MSCP account

**1.6.** action update_user

	- Updates an existing i-MSCP account

**1.7.** action add_alias

	- Adds a new domain alias to an existing i-MSCP account

**1.8.** action get_mails

	- Gets all mail accounts of an existing i-MSCP account

**1.9.** action add_mail

	- Adds a new mail account to an existing i-MSCP account

**1.10.** action collectusagedata (NOT WORKING)

	- Collects all usage data of an existing i-MSCP account
	
**1.11.** action add_sql_db

	- Adds a new database to an existing i-MSCP account

**1.12.** action delete_sql_db

	- Deletes a database from an existing i-MSCP account

**1.13.** action get_sql_db

	- Gets a list of database from an existing i-MSCP account

**1.14.** action add_sql_user

	- Adds a new database user to an existing i-MSCP account

**1.15.** action delete_sql_user

	- Deletes a database user from an existing i-MSCP account

**1.16.** action edit_sql_user_pass

	- Edits a database user password for an existing i-MSCP account

**1.17.** action add_ftp

	- Adds a FTP account

**1.18.** action edit_ftp

	- Edits a FTP account

**1.19.** action delete_ftp

	- Deletes FTP account

**1.20.** action add_dns_record

	- Adds DNS record

**1.21.** action add_dns_record

	- Edits DNS record

**1.22.** action add_dns_record

	- Deletes DNS record

**-- Depending of the action there are different required postData values --**
**-- in next release you will find the possible values in the "possible_values.txt" file --** 

**2.** reseller_username (required)

	- value: Username of the reseller account

**3.** reseller_password (required)

	- value: Password of the reseller account
	
**4.** domain (required)

	- This will be later the new login of the i-MSCP panel

**5.** admin_pass (required for some actions)

	- Password for the new login of the i-MSCP panel

**6.** email (required for some actions)

	- Emailadress for the new login of the i-MSCP panel

**7.** hosting_plan (required if you want to use hosting plans to create a user)

	- value: string of the hosting plan name

**7.1.** hp_mail (required if hosting_plan not set)

	- value: -1 (disabled), 0 (unlimited) or a number > 0
	
**7.2.** hp_ftp (required if hosting_plan not set)

	- value: -1 (disabled), 0 (unlimited) or a number > 0

**7.3.** hp_traff (required if hosting_plan not set)

	- value: 0 (unlimited) or a number > 0 in MB

**7.4.** hp_sql_db (required if hosting_plan not set)

	- value: -1 (disabled), 0 (unlimited) or a number > 0

**7.5.** hp_sql_user (required if hosting_plan not set)

	- value: -1 (disabled), 0 (unlimited) or a number > 0

**7.6.** hp_sub (required if hosting_plan not set)

	- value: -1 (disabled), 0 (unlimited) or a number > 0

**7.7.** hp_disk (required if hosting_plan not set)

	- value: 0 (unlimited) or a number > 0 in MB

**7.8.** hp_als (required if hosting_plan not set)

	- value: -1 (disabled), 0 (unlimited) or a number > 0

**7.9.** hp_php (required if hosting_plan not set)

	- value: yes or no

**7.10.** hp_cgi (required if hosting_plan not set)

	- value: yes or no

**7.11.** hp_backup (required if hosting_plan not set)

	- value: no, dmn, sql or full

**7.12.** hp_dns (required if hosting_plan not set)

	- value: yes or no

**7.13.** hp_allowsoftware (required if hosting_plan not set)

	- value: yes or no (php must enabled if you set this value to yes)
	
**7.14.** external_mail (required if hosting_plan not set)

	- value: yes or no (hp_mail does not set to hp_mail -1)

**7.15.** web_folder_protection (required if hosting_plan not set)

	- value: yes or no

**7.16.** phpini_system (required if hosting_plan not set)

	- value: yes or no

**7.17.** phpini_perm_allow_url_fopen (required if hosting_plan not set)

	- value: yes or no

**7.18.** phpini_perm_display_errors (required if hosting_plan not set)

	- value: yes or no

**7.19.** phpini_perm_disable_functions (required if hosting_plan not set)

	- value: yes or no

**7.20.** phpini_post_max_size (required if hosting_plan not set)

	- value: numeric in MB
	
**7.21.** phpini_upload_max_filesize (required if hosting_plan not set)

	- value: numeric in MB

**7.22.** phpini_max_execution_time (required if hosting_plan not set)

	- value: numeric in seconds

**7.23.** phpini_max_input_time (required if hosting_plan not set)

	- value: numeric in seconds

**7.24.** phpini_memory_limit (required if hosting_plan not set)

	- value: numeric in MB

**8.** alias_domains

	- (must be an array), array('alias1.tld', 'alias2.tld')

**9.** mail_quota

	- value: numeric in MB, 0 (unlimited)

### Customer data variable which are available

	- fname: first name
	- lname: last name
	- firm: company
	- zip: zipcode
	- city: city
	- state: state
	- country: country
	- phone: phone number
	- fax: fax number
	- street1: street
	- street2: additional street informations
	- gender: value can be "U=unknown, F=female, M=male"

### You can find a class file with sample postData values for some actions in sample folder.

### AUTHORS AND CONTRIBUTORS

 * Sascha Bay <info@space2place.de> (Author)
 * Peter Zierg�bel <info@fisa4.de> (Contributor)
 * Ninos Ego <me@ninosego.de> (Contributor)

**Thank you for using this plugin.**

KNOWN ISSUES
Currently it is only possible to add or update a user if you use a hosting plan. 
Update will come soon.	
