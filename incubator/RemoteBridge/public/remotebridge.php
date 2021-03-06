<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2016 by i-MSCP Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @category    iMSCP
 * @package     iMSCP_Plugin
 * @subpackage  RemoteBridge
 * @copyright   2010-2016 by i-MSCP Team
 * @author      Sascha Bay <info@space2place.de>
 * @author      Peter Ziergoebel <info@fisa4.de>
 * @author      Ninos Ego <me@ninosego.de>
 * @link        http://www.i-mscp.net i-MSCP Home Site
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GPL v2
 */

use iMSCP_Database as Database;
use iMSCP_Events as Events;
use iMSCP_Events_Aggregator as EventManager;
use iMSCP_Authentication as Authentication;
use iMSCP_Exception_Database as DatabaseException;
use iMSCP_pTemplate as TemplateEngine;
use iMSCP_Registry as Registry;

require_once 'imscp-lib.php';

include_once('remotebridge.user.php');
include_once('remotebridge.alias.php');
include_once('remotebridge.mail.php');
include_once('remotebridge.database.php');
include_once('remotebridge.dns.php');
include_once('remotebridge.domain.php');
include_once('remotebridge.ftp.php');

$filter = iMSCP_Registry::set(
	'bufferFilter',
	new iMSCP_Filter_Compress_Gzip(iMSCP_Filter_Compress_Gzip::FILTER_BUFFER)
);
$filter->compressionInformation = false;
ob_start(array($filter, iMSCP_Filter_Compress_Gzip::CALLBACK_NAME));

checkRemoteIpaddress($_SERVER['REMOTE_ADDR']);

if (isset($_POST['key']) && isset($_POST['data'])) {
	// checkiMSCP_Version();

	$postData = decryptPostData($_POST['key'], $_SERVER['REMOTE_ADDR'], $_POST['data']);
	$resellerId = checkResellerLoginData($postData['reseller_username'], $postData['reseller_password']);
	$_SESSION['user_id'] = isset($resellerId) ? $resellerId : '';
	$_SESSION['user_logged'] = isset($postData['reseller_username']) ? $postData['reseller_username'] : '';
	$_SESSION['user_email'] = isset($postData['email']) ? $postData['email'] : '';
	$_SESSION['user_type'] = 'User';


	$action = isset($postData['action']) ? $postData['action'] : 'default';

	switch ($action) {
		case 'get_users':
			getUserList($resellerId);
			break;

		case 'create_user':
			$resellerHostingPlan = (isset($postData['hosting_plan']))
				? checkResellerHostingPlan($resellerId, $postData['hosting_plan'], $resellerId) : array();
			$resellerIpaddress = checkResellerAssignedIP($resellerId);

			if (count($resellerHostingPlan) == 0) {
				checkLimitsPostData($postData, $resellerId);
			}

			createNewUser($resellerId, $resellerHostingPlan, $resellerIpaddress, $postData);
			break;

		case 'enable_user':
			if (empty($postData['domain'])) {
				logoutReseller();
				exit(
				createJsonMessage(
					array(
						'level' => 'Error',
						'message' => 'No domain in post data available.'
					)
				)
				);
			}

			enableUser($resellerId, $postData['domain']);
			break;

		case 'disable_user':
			if (empty($postData['domain'])) {
				logoutReseller();
				exit(
				createJsonMessage(
					array(
						'level' => 'Error',
						'message' => 'No domain in post data available.'
					)
				)
				);
			}

			disableUser($resellerId, $postData['domain']);
			break;

		case 'delete_user':
			if (empty($postData['domain'])) {
				logoutReseller();
				exit(
				createJsonMessage(
					array(
						'level' => 'Error',
						'message' => 'No domain in post data available.'
					)
				)
				);
			}

			deleteUser($resellerId, $postData['domain'], $postData['reseller_username']);
			break;
			
		case 'update_user':
			$resellerHostingPlan = (isset($postData['hosting_plan']))
				? checkResellerHostingPlan($resellerId, $postData['hosting_plan']) : array();

			$resellerIpaddress = checkResellerAssignedIP($resellerId);

			if (count($resellerHostingPlan) == 0) {
				checkLimitsPostData($postData, $resellerId);
			}

			updateUser($resellerId, $resellerHostingPlan, $resellerIpaddress, $postData);
			break;

	/*	case 'add_alias':
			$resellerIpaddress = checkResellerAssignedIP($resellerId);

			addAliasDomain($resellerId, $resellerIpaddress, $postData);
			break;
*/
		case 'get_mails':
			if (empty($postData['domain'])) {
				logoutReseller();
				exit(
				createJsonMessage(
					array(
						'level' => 'Error',
						'message' => 'No reseller name in post data available.'
					)
				)
				);
			}

			getMailList($resellerId, $postData['domain']);
			break;

		case 'add_mail':
			addMailAccount($resellerId, $postData);
			break;

		case 'check_mail':
			checkMail($postData);
			break;

		case 'delete_mail':
			deleteMail($resellerId, $postData);
			break;

		case 'add_subdomain':
			addSubDomain($resellerId, $postData);
			break;

		case 'check_subdomain':

			break;

/*		case 'collectusagedata':
			if (empty($postData['domain'])) {
				logoutReseller();
				exit(
				createJsonMessage(
					array(
						'level' => 'Error',
						'message' => 'No domain in post data available.'
					)
				)
				);
			}
			collectUsageData($resellerId, $postData['domain']);
			break;
*/
		case 'add_ftp':
			addFtpAccount($resellerId, $postData);
			break;

		case 'edit_ftp':
			editFtp($resellerId, $postData);
			break;

		case 'delete_ftp':
			deleteFtp($resellerId, $postData);
			break;

		case 'add_sql_db':
			addSqlDb($resellerId, $postData);
			break;

		case 'delete_sql_db':
			deleteSqlDb($resellerId, $postData);
			break;

		case 'get_sql_db':
			getSqlDb($postData);
			break;

		case 'add_sql_user':
			addSqlUser($resellerId, $postData);
			break;

		case 'edit_sql_user':
			editSqlUserPassword($resellerId, $postData);
			break;

		case 'delete_sql_user':
			deleteSqlUser($resellerId, $postData);
			break;

		case 'add_dns_record':
			addDnsRecord($resellerId, $postData);
			break;

		case 'edit_dns_record':
			editDnsRecord($resellerId, $postData);
			break;

		case 'delete_dns_record':
			deleteDnsRecord($resellerId, $postData);
			break;

		case 'get_dns_records':
			getDns($postData);
			break;

		default:
			exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => sprintf('This action: %s is not implemented.', $action)
				)
			)
			);
	}

	logoutReseller();
	exit;
}

/**
 * Check remote IP address
 *
 * @param string $ipaddress Remote IP address
 */
function checkRemoteIpaddress($ipaddress)
{
	$query = "SELECT COUNT(*) AS `cnt` FROM `remote_bridge` WHERE `bridge_ipaddress` = ? AND `bridge_status` = ?";
	$stmt = exec_query($query, array($ipaddress, 'ok'));

	if ($stmt->fields['cnt'] == 0) {
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => sprintf('Your IP address %s does not have access to the remote bridge.', $ipaddress)
				)
			)
		);
	}
}

/**
 * Decrypt POST data
 *
 * @param string $bridgeKey
 * @param string $ipaddress IP address
 * @param string $encryptedData Encrypted data
 * @return mixed
 */

function decryptPostData($bridgeKey, $ipaddress, $encryptedData)
{
	$resName = getResellerUsername($bridgeKey, $ipaddress);

	if ($resName === false) {
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => 'No data in your post vars available.'
				)
			)
		);
	}

	$decryptedData = @unserialize(
		rtrim(
			mcrypt_decrypt(
				MCRYPT_RIJNDAEL_256,
				md5($resName),
				base64_decode(strtr($encryptedData, '-_,', '+/=')),
				MCRYPT_MODE_CBC, md5(md5($resName))
			),
			"\0"
		)
	);

	if (count($decryptedData) == 0 || $decryptedData == '') {
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => 'No data in your post vars available.'
				)
			)
		);
	}

	return $decryptedData;
}

/**
 * Get reseller username
 *
 * @param string $bridgeKey Bridge key
 * @param string $ipaddress IP address
 * @return bool|string Reseller username on success, FALSE on failure
 */
function getResellerUsername($bridgeKey, $ipaddress)
{
	$query = "
		SELECT
			`t1`.*, `t2`.`admin_name`
		FROM
			`remote_bridge` AS `t1`
		INNER JOIN
			`admin` AS `t2` ON (`t2`.`admin_id` = `t1`.`bridge_admin_id`)
		WHERE
			`bridge_key` = ?
		AND
			`bridge_ipaddress` = ?
		AND
			`bridge_status` = ?
	";
	$stmt = exec_query($query, array($bridgeKey, $ipaddress, 'ok'));

	return ($stmt->fields['admin_name']) ? $stmt->fields['admin_name'] : false;
}

/**
 * Get reseller ID
 *
 * @param string $resellerName
 * @return bool|int Reseller ID on success, FALSE on failure
 */
function getResellerId($resellerName)
{
	$query = "
		SELECT
			`admin_id`
		FROM
			`admin` 
		WHERE
			`admin_name` = ?
		AND
			`admin_status` = ?
	";
	$stmt = exec_query($query, array($resellerName, 'ok'));

	return ($stmt->fields['admin_id']) ? $stmt->fields['admin_id'] : false;
}


/**
 * Check i-MSCP version
 *
 * @return void
 */
function checkiMSCP_Version()
{
	/** @var iMSCP_Config_Handler_File $cfg */
	$cfg = iMSCP_Registry::get('config');

	if (version_compare($cfg->Version, '1.2.17', '<')) {
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => sprintf(
						'iMSCP version %s is not compatible with the remote bridge. Try with a newest version.',
						$cfg->Version
					)
				)
			)
		);
	}
}

/**
 * Check reseller login data
 *
 * @param string $reseller_username Resller username
 * @param string $reseller_password Reseller password
 * @return mixed
 */
function checkResellerLoginData($reseller_username, $reseller_password)
{
	// Purge expired session
	do_session_timeout();

	$auth = Authentication::getInstance();
	
	// Init login process
//	init_login($auth->getEvents());

	$authResult = $auth->authenticate();

/*	if (!empty($reseller_username) && !empty($reseller_password)) {
		$result = $auth
			->setUsername(encode_idna(clean_input($reseller_username)))
			->setPassword(clean_input($reseller_password))->authenticate();
*/



/*		if (!$result->isValid()) {
			write_log(
				sprintf("Authentication via remote bridge failed. Reason: %s", format_message($result->getMessages())),
				E_USER_NOTICE
			);

			exit(
				createJsonMessage(
					array(
						'level' => 'Error',
						'message' => format_message($result->getMessages())
					)
				)
			);
		}
*/
/*+++++*/
		if($authResult->isValid()) {
				write_log(sprintf("%s logged in", $reseller_username), E_USER_NOTICE);
			} elseif(($messages = $authResult->getMessages())) {
				$messages = format_message($messages);
				set_page_message($messages, 'error');
				write_log(sprintf("Authentication failed. Reason: %s", $messages), E_USER_NOTICE);
		}
/*+++++*/
	/* } else {
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => 'Login data are missing.'
				)
			)
		);
	} */

	write_log(sprintf("%s logged in via remote bridge", $reseller_username), E_USER_NOTICE);

	return getResellerId($reseller_username);
}

/**
 * Check reseller hosting plan
 *
 * @param int $resellerId Reseller unique identifier
 * @param string $hosting_plan Hosting plan name
 * @return array
 */
function checkResellerHostingPlan($resellerId, $hosting_plan)
{

	$hosting_plan = urldecode($hosting_plan);
	$query = "SELECT * FROM  `hosting_plans` WHERE  `name` = ?  AND  `reseller_id` = ? AND  `status` = '1'";
	$param = array($hosting_plan, $resellerId);
	$stmt = exec_query($query, $param);

	$data = $stmt->fetchRow();
	$props = $data['props'];
	if (!$data) {
		logoutReseller();
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => sprintf('No such hosting plan named: %s.', $hosting_plan)
				)
			)
		);
	}

	$result = array_combine(
		array(
			'hp_php', 'hp_cgi', 'hp_sub', 'hp_als', 'hp_mail', 'hp_ftp', 'hp_sql_db',
			'hp_sql_user', 'hp_traff', 'hp_disk', 'hp_backup', 'hp_dns', 'hp_allowsoftware',
			'phpini_system', 'phpini_perm_allow_url_fopen', 'phpini_perm_display_errors',
			'phpini_perm_disable_functions','php_mail', 'phpini_post_max_size', 'phpini_upload_max_filesize',
			'phpini_max_execution_time', 'phpini_max_input_time', 'phpini_memory_limit',
			'external_mail', 'web_folder_protection', 'mailQuota'
		),
		array_pad(explode(';', $props), 26, 'no')
	);

	return $result;
}

/**
 * Check POST data limits
 *
 * @param array $postData POST data
 * @param int $resellerId Reseller unique identifier
 * @return bool
 */
function checkLimitsPostData($postData, $resellerId)
{
	$phpini = iMSCP_PHPini::getInstance();
	$phpini->loadResellerPermissions($resellerId);

	if (isset($postData['hp_mail'])) {
		if (!resellerHasFeature('mail') && $postData['hp_mail'] != '-1') {
			sendPostDataError('hp_mail', 'Your mail account limit is disabled');
		} elseif (!imscp_limit_check($postData['hp_mail'], -1)) {
			sendPostDataError('hp_mail', 'Incorrect mail accounts limit');
		}
	} else {
		sendPostDataError('hp_mail', 'Variable not available in your post data');
	}

	if (isset($postData['mail_quota'])) {
		$mailQuota = ($postData['mail_quota'] != '0') ? $postData['mail_quota'] * 1048576 : '0';

		if (!imscp_limit_check($mailQuota, null)) {
			sendPostDataError('mail_quota', 'Incorrect Email ' . $postData['mail_quota'] . ' quota');
		} elseif ($postData['hp_disk'] != '0' && $mailQuota > $postData['hp_disk']) {
			sendPostDataError('mail_quota', 'Email quota cannot be bigger than disk space limit.');
		} elseif ($postData['hp_disk'] != '0' && $mailQuota == '0') {
			sendPostDataError(
				'mail_quota', 'Email quota cannot be unlimited. Max value is ' . $postData['hp_disk'] . ' MiB.'
			);
		}
	} else {
		sendPostDataError('mail_quota', 'Variable not available in your post data');
	}

	if (isset($postData['external_mail'])) {
		if ($postData['external_mail'] != 'yes' && $postData['external_mail'] != 'no') {
			sendPostDataError('external_mail', 'Incorrect value. Only yes or no is allowed');
		} elseif (!resellerHasFeature('mail') && $postData['external_mail'] == 'yes') {
			sendPostDataError('external_mail', 'Your mail account limit is disabled');
		}
	} else {
		sendPostDataError('external_mail', 'Variable not available in your post data');
	}

	if (isset($postData['hp_ftp'])) {
		if (!resellerHasFeature('ftp') && $postData['hp_ftp'] != '-1') {
			sendPostDataError('hp_ftp', 'Your ftp account limit is disabled');
		} elseif (!imscp_limit_check($postData['hp_ftp'], -1)) {
			sendPostDataError('hp_ftp', 'Incorrect FTP accounts limit');
		}
	} else {
		sendPostDataError('hp_ftp', 'Variable not available in your post data');
	}

	if (isset($postData['hp_sql_db'])) {
		if (!resellerHasFeature('sql_db') && $postData['hp_sql_db'] != '-1') {
			sendPostDataError('hp_sql_db', 'Your SQL database limit is disabled');
		} elseif (!imscp_limit_check($postData['hp_sql_db'], -1)) {
			sendPostDataError('hp_sql_db', 'Incorrect SQL databases limit');
		}
	} else {
		sendPostDataError('hp_sql_db', 'Variable not available in your post data');
	}

	if (isset($postData['hp_sql_user'])) {
		if (!resellerHasFeature('sql_user') && $postData['hp_sql_user'] != '-1') {
			sendPostDataError('hp_sql_user', 'Your SQL user limit is disabled');
		} elseif (!imscp_limit_check($postData['hp_sql_user'], -1)) {
			sendPostDataError('hp_sql_user', 'Incorrect SQL users limit');
		}
	} else {
		sendPostDataError('hp_sql_db', 'Variable not available in your post data');
	}

	if (isset($postData['hp_sub'])) {
		if (!resellerHasFeature('subdomains') && $postData['hp_sub'] != '-1') {
			sendPostDataError('hp_sub', 'Your subdomains limit is disabled');
		} elseif (!imscp_limit_check($postData['hp_sub'], -1)) {
			sendPostDataError('hp_sub', 'Incorrect subdomains limit');
		}
	} else {
		sendPostDataError('hp_sub', 'Variable not available in your post data');
	}

	if (isset($postData['hp_traff'])) {
		if (!imscp_limit_check($postData['hp_traff'], null)) {
			sendPostDataError('hp_traff', 'Incorrect monthly traffic limit');
		}
	} else {
		sendPostDataError('hp_traff', 'Variable not available in your post data');
	}

	if (isset($postData['hp_disk'])) {
		if (!imscp_limit_check($postData['hp_disk'], null)) {
			sendPostDataError('hp_disk', 'Incorrect diskspace limit');
		}
	} else {
		sendPostDataError('hp_disk', 'Variable not available in your post data');
	}

	if (isset($postData['hp_als'])) {
		if (!resellerHasFeature('domain_aliases') && $postData['hp_als'] != '-1') {
			sendPostDataError('hp_als', 'Your domain aliases limit is disabled');
		} elseif (!imscp_limit_check($postData['hp_als'], -1)) {
			sendPostDataError('hp_als', 'Incorrect aliases limit');
		}
	} else {
		sendPostDataError('hp_als', 'Variable not available in your post data');
	}

	if (isset($postData['hp_php'])) {
		if ($postData['hp_php'] != 'yes' && $postData['hp_php'] != 'no') {
			sendPostDataError('hp_php', 'Incorrect value. Only yes or no is allowed');
		}
	} else {
		sendPostDataError('hp_php', 'Variable not available in your post data');
	}

	if (isset($postData['php_mail'])) {
		if ($postData['php_mail'] != 'yes' && $postData['php_mail'] != 'no') {
			sendPostDataError('php_mail', 'Incorrect value. Only yes or no is allowed');
		}
	} else {
		sendPostDataError('php_mail', 'Variable not available in your post data');
	}


	if (isset($postData['hp_cgi'])) {
		if ($postData['hp_cgi'] != 'yes' && $postData['hp_cgi'] != 'no') {
			sendPostDataError('hp_cgi', 'Incorrect value. Only yes or no is allowed');
		}
	} else {
		sendPostDataError('hp_cgi', 'Variable not available in your post data');
	}

	if (isset($postData['hp_backup'])) {
		if (
			$postData['hp_backup'] != 'no' && $postData['hp_backup'] != 'dmn' && $postData['hp_backup'] != 'sql' &&
			$postData['hp_backup'] != 'full'
		) {
			sendPostDataError('hp_backup', 'Incorrect value. Only no, dmn, sql or full is allowed');
		}
	} else {
		sendPostDataError('hp_backup', 'Variable not available in your post data');
	}

	if (isset($postData['hp_dns'])) {
		if ($postData['hp_dns'] != 'yes' && $postData['hp_dns'] != 'no') {
			sendPostDataError('hp_dns', 'Incorrect value. Only yes or no is allowed');
		}
	} else {
		sendPostDataError('hp_dns', 'Variable not available in your post data');
	}

	if (isset($postData['hp_allowsoftware'])) {
		if ($postData['hp_allowsoftware'] != 'yes' && $postData['hp_allowsoftware'] != 'no') {
			sendPostDataError('hp_allowsoftware', 'Incorrect value. Only yes or no is allowed');
		} elseif (!resellerHasFeature('aps') && $postData['hp_allowsoftware'] == 'yes') {
			sendPostDataError('hp_allowsoftware', 'Your aps installer permission is disabled');
		} elseif ($postData['hp_allowsoftware'] == 'yes' && $postData['hp_php'] == 'no') {
			sendPostDataError('hp_allowsoftware', 'The software installer require PHP, but it is disabled');
		}
	} else {
		sendPostDataError('hp_allowsoftware', 'Variable not available in your post data');
	}

	if (isset($postData['web_folder_protection'])) {
		if ($postData['web_folder_protection'] != 'yes' && $postData['web_folder_protection'] != 'no') {
			sendPostDataError('web_folder_protection', 'Incorrect value. Only yes or no is allowed');
		}
	} else {
		sendPostDataError('web_folder_protection', 'Variable not available in your post data');
	}

	if (isset($postData['phpini_system'])) {
		if ($postData['phpini_system'] != 'yes' && $postData['phpini_system'] != 'no') {
			sendPostDataError('phpini_system', 'Incorrect value. Only yes or no is allowed');
		} elseif (!$phpini->checkRePerm('phpiniSystem') && $postData['phpini_system'] == 'yes') {
			sendPostDataError('phpini_system', 'Your php editor permission is disabled');
		} elseif ($phpini->checkRePerm('phpiniSystem') && $postData['phpini_system'] == 'yes') {
			if (isset($postData['phpini_perm_allow_url_fopen'])) {
				if (!$phpini->checkRePerm('phpiniAllowUrlFopen')) {
					$phpini->setClPerm('phpiniAllowUrlFopen', clean_input($postData['phpini_perm_allow_url_fopen']));
				}
			} else {
				sendPostDataError('phpini_perm_allow_url_fopen', 'Variable not available in your post data');
			}

			if (isset($postData['phpini_perm_display_errors'])) {
				if (!$phpini->checkRePerm('phpiniDisplayErrors')) {
					$phpini->setClPerm('phpiniDisplayErrors', clean_input($postData['phpini_perm_display_errors']));
				}
			} else {
				sendPostDataError('phpini_perm_display_errors', 'Variable not available in your post data');
			}

			if (isset($postData['phpini_perm_disable_functions'])) {
				if (PHP_SAPI != 'apache2handler' && !$phpini->checkRePerm('phpiniDisableFunctions')) {
					$phpini->setClPerm('phpiniDisableFunctions', clean_input($postData['phpini_perm_disable_functions']));
				}
			} else {
				sendPostDataError('phpini_perm_display_errors', 'Variable not available in your post data');
			}

			if (
				isset($postData['phpinipostData_max_size']) &&
				(!$phpini->setDataWithPermCheck('phpiniPostMaxSize', $postData['phpinipostData_max_size']))
			) {
				$phpini->setData('phpiniPostMaxSize', $postData['phpinipostData_max_size'], false);
				sendPostDataError('phpinipostData_max_size', 'Value for the PHP this directive is out of range');
			}

			if (
				isset($postData['phpini_upload_max_filesize']) &&
				(!$phpini->setDataWithPermCheck('phpiniUploadMaxFileSize', $postData['phpini_upload_max_filesize']))
			) {
				$phpini->setData('phpiniUploadMaxFileSize', $postData['phpini_upload_max_filesize'], false);
				sendPostDataError('phpini_upload_max_filesize', 'Value for the PHP this directive is out of range');
			}

			if (
				isset($postData['phpini_max_execution_time']) &&
				(!$phpini->setDataWithPermCheck('phpiniMaxExecutionTime', $postData['phpini_max_execution_time']))
			) {
				$phpini->setData('phpiniMaxExecutionTime', $postData['phpini_max_execution_time'], false);
				sendPostDataError('phpini_max_execution_time', 'Value for the PHP this directive is out of range');
			}

			if (
				isset($postData['phpini_max_input_time']) &&
				(!$phpini->setDataWithPermCheck('phpiniMaxInputTime', $postData['phpini_max_input_time']))
			) {
				$phpini->setData('phpiniMaxInputTime', $postData['phpini_max_input_time'], false);
				sendPostDataError('phpini_max_input_time', 'Value for the PHP this directive is out of range');
			}

			if (
				isset($postData['phpini_memory_limit']) &&
				(!$phpini->setDataWithPermCheck('phpiniMemoryLimit', $postData['phpini_memory_limit']))
			) {
				$phpini->setData('phpiniMemoryLimit', $postData['phpini_memory_limit'], false);
				sendPostDataError('phpini_memory_limit', 'Value for the PHP this directive is out of range');
			}
		}
	} else {
		sendPostDataError('phpini_system', 'Variable not available in your post data');
	}

	return true;
}

/**
 * Send POST data error
 *
 * @param string $postVar POST variable name
 * @param string $errorMessage Error message
 */
function sendPostDataError($postVar, $errorMessage)
{
	logoutReseller();

	exit(
		createJsonMessage(
			array(
				'level' => 'Error',
				'message' => sprintf('Post variable: %s : %s.', $postVar, $errorMessage)
			)
		)
	);
}

/**
 * Check reseller assigned IP
 * @param $resellerId
 * @return mixed
 */
function checkResellerAssignedIP($resellerId)
{
	$query = "SELECT *  FROM  `reseller_props` WHERE `reseller_id` = ?";
	$stmt = exec_query($query, $resellerId);
	$data = $stmt->fetchRow();

	if (!$data) {
		logoutReseller();
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => 'Reseller does not have any IP address assigned.'
				)
			)
		);
	}

	$ips = explode(';', $data['reseller_ips']);

	if (array_key_exists('0', $ips)) {
		return $ips[0];
	} else {
		logoutReseller();
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => 'Cannot retrieve reseller IP address.'
				)
			)
		);
	}
}

/**
 * Logout reseller
 *
 * @return void
 */
function logoutReseller()
{
	$auth = Authentication::getInstance();

	if ($auth->hasIdentity()) {
		$adminName = $auth->getIdentity()->admin_name;
		$auth->unsetIdentity();
		write_log(sprintf("%s logged out from remote bridge", idn_to_utf8($adminName)), E_USER_NOTICE);
	}
}

/**
 * Check password syntax
 *
 * @param string $password Password
 * @param string $unallowedChars Regexp representing unallowed characters
 * @return void
 */
function remoteBridgecheckPasswordSyntax($password, $unallowedChars = '')
{
	/** @var $cfg iMSCP_Config_Handler_File */
	$cfg = iMSCP_Registry::get('config');

	$passwordLength = strlen($password);

	if ($cfg->PASSWD_CHARS < 6) {
		$cfg->PASSWD_CHARS = 6;
	} elseif ($cfg->PASSWD_CHARS > 30) {
		$cfg->PASSWD_CHARS = 30;
	}

	if ($passwordLength < $cfg->PASSWD_CHARS) {
		logoutReseller();
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => sprintf('Password is shorter than %s characters.', $cfg->PASSWD_CHARS)
				)
			)
		);
	} elseif ($passwordLength > 30) {
		logoutReseller();
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => 'Password cannot be greater than 30 characters.'
				)
			)
		);
	}

	if (!empty($unallowedChars) && preg_match($unallowedChars, $password)) {
		logoutReseller();
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => 'Password includes not permitted signs.'
				)
			)
		);
	}

	if ($cfg->PASSWD_STRONG && !(preg_match('/[0-9]/', $password) && preg_match('/[a-zA-Z]/', $password))) {
		logoutReseller();
		exit(
			createJsonMessage(
				array(
					'level' => 'Error',
					'message' => sprintf(
						'Password must be at least %s character long and contain letters and numbers to be valid.',
						$cfg->PASSWD_CHARS
					)
				)
			)
		);
	}
}

/**
 * Create JSON message
 *
 * @param $inputData
 * @return string
 */
function createJsonMessage($inputData)
{
	return json_encode($inputData);
}

/**
 * Get domain id by domain name
 *
 * @param $domain
 * @return string
 */
function getDomainIdByDomain($domain)
{
	$query = '
		SELECT
			domain_id
		FROM
			domain
		WHERE
			domain_name = ?
	';
	$stmt = exec_query($query, $domain);
	$domainId = $stmt->fields['domain_id'];
	return($domainId);

}

/**
 * Get domain admin id by domain id
 *
 * @param $domainId
 * @return string
 */
function getDomainAdminIdByDomainId($domainId)
{
	$query = '
		SELECT
			domain_admin_id
		FROM
			domain
		WHERE
			domain_id = ?
	';
	$stmt = exec_query($query, $domainId);
	$domainAdminId = $stmt->fields['domain_admin_id'];
	return($domainAdminId);
}

/**
 * Get database id
 *
 * @param int $domainId
 * @param string $dbName
 * @return int
 */
function getDbId($domainId, $dbName){
	$query = '
		SELECT
			sqld_id
		FROM
			sql_database
		WHERE
			domain_id = ?
		AND
			sqld_name = ?
	';
	$stmt = exec_query($query, array( $domainId, $dbName));
	$dbId = $stmt->fields['sqld_id'];
	return($dbId);
}
/**
 * Get database id
 *
 * @param int $domainId
 * @param string $dbName
 * @return int
 */
function getDbUserValues($sqlUser){
	$query = '
		SELECT
			sqlu_id,
			sqlu_host,
			sqlu_pass
		FROM
			sql_user
		WHERE
			sqlu_name = ?
	';
	$stmt = exec_query($query, $sqlUser);
	return array(
		$stmt->fields['sqlu_id'], $stmt->fields['sqlu_host'], $stmt->fields['sqlu_pass']
	);
}


exit(
createJsonMessage(
	array(
		'level' => 'Error',
		'message' => 'Direct access to remote bridge not allowed.'
	)
)
);
