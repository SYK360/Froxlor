<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    API
 * @since      0.10.0
 *
 */
class Admins extends ApiCommand implements ResourceEntity
{

	/**
	 * lists all admin entries
	 *
	 * @access admin
	 * @throws Exception
	 * @return array count|list
	 */
	public function listing()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			$this->logger()->logAction(ADM_ACTION, LOG_NOTICE, "[API] list admins");
			$result_stmt = Database::prepare("
				SELECT *
				FROM `" . TABLE_PANEL_ADMINS . "`
				ORDER BY `loginname` ASC
			");
			Database::pexecute($result_stmt, null, true, true);
			$result = array();
			while ($row = $result_stmt->fetch(PDO::FETCH_ASSOC)) {
				$result[] = $row;
			}
			return $this->response(200, "successfull", array(
				'count' => count($result),
				'list' => $result
			));
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * return an admin entry by either id or loginname
	 *
	 * @param int $id
	 *        	optional, the admin-id
	 * @param string $loginname
	 *        	optional, the loginname
	 *        	
	 * @access admin
	 * @throws Exception
	 * @return array
	 */
	public function get()
	{
		$id = $this->getParam('id', true, 0);
		$ln_optional = ($id <= 0 ? false : true);
		$loginname = $this->getParam('loginname', $ln_optional, '');
		
		if ($this->isAdmin() && ($this->getUserDetail('change_serversettings') == 1 || ($this->getUserDetail('adminid') == $id || $this->getUserDetail('loginname') == $loginname))) {
			$result_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_ADMINS . "`
				WHERE " . ($id > 0 ? "`adminid` = :idln" : "`loginname` = :idln"));
			$params = array(
				'idln' => ($id <= 0 ? $loginname : $id)
			);
			$result = Database::pexecute_first($result_stmt, $params, true, true);
			if ($result) {
				$this->logger()->logAction(ADM_ACTION, LOG_NOTICE, "[API] get admin '" . $result['loginname'] . "'");
				return $this->response(200, "successfull", $result);
			}
			$key = ($id > 0 ? "id #" . $id : "loginname '" . $loginname . "'");
			throw new Exception("Admin with " . $key . " could not be found", 404);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * create a new admin user
	 *
	 * @access admin
	 * @throws Exception
	 * @return array
	 */
	public function add()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			
			// required parameters
			$name = $this->getParam('name');
			$email = $this->getParam('email');
			
			// parameters
			$def_language = $this->getParam('def_language', true, Settings::Get('panel.standardlanguage'));
			$custom_notes = $this->getParam('custom_notes', true, '');
			$custom_notes_show = $this->getParam('custom_notes_show', true, 0);
			$password = $this->getParam('admin_password', true, '');
			$loginname = $this->getParam('new_loginname', true, '');
			
			$diskspace = $this->getUlParam('diskspace', 'diskspace_ul', true, 0);
			$traffic = $this->getUlParam('traffic', 'traffic_ul', true, 0);
			$customers = $this->getUlParam('customers', 'customers_ul', true, 0);
			$domains = $this->getUlParam('domains', 'domains_ul', true, 0);
			$subdomains = $this->getUlParam('subdomains', 'subdomains_ul', true, 0);
			$emails = $this->getUlParam('emails', 'emails_ul', true, 0);
			$email_accounts = $this->getUlParam('email_accounts', 'email_accounts_ul', true, 0);
			$email_forwarders = $this->getUlParam('email_forwarders', 'email_forwarders_ul', true, 0);
			$email_quota = $this->getUlParam('email_quota', 'email_quota_ul', true, 0);
			$ftps = $this->getUlParam('ftps', 'ftps_ul', true, 0);
			$tickets = $this->getUlParam('tickets', 'tickets_ul', true, 0);
			$mysqls = $this->getUlParam('mysqls', 'mysqls_ul', true, 0);
			
			$customers_see_all = $this->getParam('customers_see_all', true, 0);
			$domains_see_all = $this->getParam('domains_see_all', true, 0);
			$tickets_see_all = $this->getParam('tickets_see_all', true, 0);
			$caneditphpsettings = $this->getParam('caneditphpsettings', true, 0);
			$change_serversettings = $this->getParam('change_serversettings', true, 0);
			$ipaddress = $this->getParam('ipaddress', true, - 1);
			
			// validation
			$name = validate($name, 'name', '', '', array(), true);
			$idna_convert = new idna_convert_wrapper();
			$email = $idna_convert->encode(validate($email, 'email', '', '', array(), true));
			$def_language = validate($def_language, 'default language', '', '', array(), true);
			$custom_notes = validate(str_replace("\r\n", "\n", $custom_notes), 'custom_notes', '/^[^\0]*$/', '', array(), true);
			
			if (Settings::Get('system.mail_quota_enabled') != '1') {
				$email_quota = - 1;
			}
			
			if (Settings::Get('ticket.enabled') != '1') {
				$tickets = - 1;
			}
			
			$password = validate($password, 'password', '', '', array(), true);
			// only check if not empty,
			// cause empty == generate password automatically
			if ($password != '') {
				$password = validatePassword($password, true);
			}
			
			$diskspace = $diskspace * 1024;
			$traffic = $traffic * 1024 * 1024;
			
			// Check if the account already exists
			// do not check via api as we skip any permission checks for this task
			$loginname_check_stmt = Database::prepare("
				SELECT `loginname` FROM `" . TABLE_PANEL_CUSTOMERS . "` WHERE `loginname` = :login
			");
			$loginname_check = Database::pexecute_first($loginname_check_stmt, array(
				'login' => $loginname
			), true, true);
			
			// Check if an admin with the loginname already exists
			// do not check via api as we skip any permission checks for this task
			$loginname_check_admin_stmt = Database::prepare("
				SELECT `loginname` FROM `" . TABLE_PANEL_ADMINS . "` WHERE `loginname` = :login
			");
			$loginname_check_admin = Database::pexecute_first($loginname_check_admin_stmt, array(
				'login' => $loginname
			), true, true);
			
			if ($loginname == '') {
				standard_error(array(
					'stringisempty',
					'myloginname'
				), '', true);
			} elseif (strtolower($loginname_check['loginname']) == strtolower($loginname) || strtolower($loginname_check_admin['loginname']) == strtolower($loginname)) {
				standard_error('loginnameexists', $loginname, true);
			} // Accounts which match systemaccounts are not allowed, filtering them
			elseif (preg_match('/^' . preg_quote(Settings::Get('customer.accountprefix'), '/') . '([0-9]+)/', $loginname)) {
				standard_error('loginnameissystemaccount', Settings::Get('customer.accountprefix'), true);
			} elseif (! validateUsername($loginname)) {
				standard_error('loginnameiswrong', $loginname, true);
			} elseif ($name == '') {
				standard_error(array(
					'stringisempty',
					'myname'
				), '', true);
			} elseif ($email == '') {
				standard_error(array(
					'stringisempty',
					'emailadd'
				), '', true);
			} elseif (! validateEmail($email)) {
				standard_error('emailiswrong', $email, true);
			} else {
				
				if ($customers_see_all != '1') {
					$customers_see_all = '0';
				}
				
				if ($domains_see_all != '1') {
					$domains_see_all = '0';
				}
				
				if ($caneditphpsettings != '1') {
					$caneditphpsettings = '0';
				}
				
				if ($change_serversettings != '1') {
					$change_serversettings = '0';
				}
				
				if ($tickets_see_all != '1') {
					$tickets_see_all = '0';
				}
				
				if ($password == '') {
					$password = generatePassword();
				}
				
				$_theme = Settings::Get('panel.default_theme');
				
				$ins_data = array(
					'loginname' => $loginname,
					'password' => makeCryptPassword($password),
					'name' => $name,
					'email' => $email,
					'lang' => $def_language,
					'change_serversettings' => $change_serversettings,
					'customers' => $customers,
					'customers_see_all' => $customers_see_all,
					'domains' => $domains,
					'domains_see_all' => $domains_see_all,
					'caneditphpsettings' => $caneditphpsettings,
					'diskspace' => $diskspace,
					'traffic' => $traffic,
					'subdomains' => $subdomains,
					'emails' => $emails,
					'accounts' => $email_accounts,
					'forwarders' => $email_forwarders,
					'quota' => $email_quota,
					'ftps' => $ftps,
					'tickets' => $tickets,
					'tickets_see_all' => $tickets_see_all,
					'mysqls' => $mysqls,
					'ip' => empty($ipaddress) ? "" : (is_array($ipaddress) && $ipaddress > 0 ? json_encode($ipaddress) : - 1),
					'theme' => $_theme,
					'custom_notes' => $custom_notes,
					'custom_notes_show' => $custom_notes_show
				);
				
				$ins_stmt = Database::prepare("
					INSERT INTO `" . TABLE_PANEL_ADMINS . "` SET
					`loginname` = :loginname,
					`password` = :password,
					`name` = :name,
					`email` = :email,
					`def_language` = :lang,
					`change_serversettings` = :change_serversettings,
					`customers` = :customers,
					`customers_see_all` = :customers_see_all,
					`domains` = :domains,
					`domains_see_all` = :domains_see_all,
					`caneditphpsettings` = :caneditphpsettings,
					`diskspace` = :diskspace,
					`traffic` = :traffic,
					`subdomains` = :subdomains,
					`emails` = :emails,
					`email_accounts` = :accounts,
					`email_forwarders` = :forwarders,
					`email_quota` = :quota,
					`ftps` = :ftps,
					`tickets` = :tickets,
					`tickets_see_all` = :tickets_see_all,
					`mysqls` = :mysqls,
					`ip` = :ip,
					`theme` = :theme,
					`custom_notes` = :custom_notes,
					`custom_notes_show` = :custom_notes_show
				");
				Database::pexecute($ins_stmt, $ins_data, true, true);
				
				$adminid = Database::lastInsertId();
				$ins_data['adminid'] = $adminid;
				$this->logger()->logAction(ADM_ACTION, LOG_WARNING, "[API] added admin '" . $loginname . "'");
				
				// get all admin-data for return-array
				$result = $this->apiCall('Admins.get', array(
					'id' => $adminid
				));
				return $this->response(200, "successfull", $result);
			}
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * update an admin user by given id or loginname
	 *
	 * @param int $id
	 *        	optional, the admin-id
	 * @param string $loginname
	 *        	optional, the loginname
	 *        	
	 * @access admin
	 * @throws Exception
	 * @return array
	 */
	public function update()
	{
		if ($this->isAdmin()) {
			
			$id = $this->getParam('id', true, 0);
			$ln_optional = ($id <= 0 ? false : true);
			$loginname = $this->getParam('loginname', $ln_optional, '');

			$result = $this->apiCall('Admins.get', array(
				'id' => $id,
				'loginname' => $loginname
			));
			$id = $result['adminid'];
			
			if ($this->getUserDetail('change_serversettings') == 1 || $result['adminid'] == $this->getUserDetail('adminid')) {
				// parameters
				$name = $this->getParam('name', true, $result['name']);
				$idna_convert = new idna_convert_wrapper();
				$email = $this->getParam('email', true, $idna_convert->decode($result['email']));
				$password = $this->getParam('admin_password', true, '');
				$def_language = $this->getParam('def_language', true, $result['def_language']);
				$custom_notes = $this->getParam('custom_notes', true, $result['custom_notes']);
				$custom_notes_show = $this->getParam('custom_notes_show', true, $result['custom_notes_show']);
				$theme = $this->getParam('theme', true, $result['theme']);
				
				// you cannot edit some of the details of yourself
				if ($result['adminid'] == $this->getUserDetail('adminid')) {
					$deactivated = $result['deactivated'];
					$customers = $result['customers'];
					$domains = $result['domains'];
					$subdomains = $result['subdomains'];
					$emails = $result['emails'];
					$email_accounts = $result['email_accounts'];
					$email_forwarders = $result['email_forwarders'];
					$email_quota = $result['email_quota'];
					$ftps = $result['ftps'];
					$tickets = $result['tickets'];
					$mysqls = $result['mysqls'];
					$tickets_see_all = $result['tickets_see_all'];
					$customers_see_all = $result['customers_see_all'];
					$domains_see_all = $result['domains_see_all'];
					$caneditphpsettings = $result['caneditphpsettings'];
					$change_serversettings = $result['change_serversettings'];
					$diskspace = $result['diskspace'];
					$traffic = $result['traffic'];
					$ipaddress = ($result['ip'] != - 1 ? json_decode($result['ip'], true) : - 1);
				} else {
					$deactivated = $this->getParam('deactivated', true, $result['deactivated']);
					
					$dec_places = Settings::Get('panel.decimal_places');
					$diskspace = $this->getUlParam('diskspace', 'diskspace_ul', true, round($result['diskspace'] / 1024, $dec_places));
					$traffic = $this->getUlParam('traffic', 'traffic_ul', true, round($result['traffic'] / (1024 * 1024), $dec_places));
					$customers = $this->getUlParam('customers', 'customers_ul', true, $result['customers']);
					$domains = $this->getUlParam('domains', 'domains_ul', true, $result['domains']);
					$subdomains = $this->getUlParam('subdomains', 'subdomains_ul', true, $result['subdomains']);
					$emails = $this->getUlParam('emails', 'emails_ul', true, $result['emails']);
					$email_accounts = $this->getUlParam('email_accounts', 'email_accounts_ul', true, $result['email_accounts']);
					$email_forwarders = $this->getUlParam('email_forwarders', 'email_forwarders_ul', true, $result['email_forwarders']);
					$email_quota = $this->getUlParam('email_quota', 'email_quota_ul', true, $result['email_quota']);
					$ftps = $this->getUlParam('ftps', 'ftps_ul', true, $result['ftps']);
					$tickets = $this->getUlParam('tickets', 'tickets_ul', true, $result['tickets']);
					$mysqls = $this->getUlParam('mysqls', 'mysqls_ul', true, $result['mysqls']);
					
					$customers_see_all = $this->getParam('customers_see_all', true, $result['customers_see_all']);
					$domains_see_all = $this->getParam('domains_see_all', true, $result['domains_see_all']);
					$tickets_see_all = $this->getParam('tickets_see_all', true, $result['tickets_see_all']);
					$caneditphpsettings = $this->getParam('caneditphpsettings', true, $result['caneditphpsettings']);
					$change_serversettings = $this->getParam('change_serversettings', true, $result['change_serversettings']);
					$ipaddress = $this->getParam('ipaddress', true, ($result['ip'] != - 1 ? json_decode($result['ip'], true) : - 1));
					
					$diskspace = $diskspace * 1024;
					$traffic = $traffic * 1024 * 1024;
				}
				
				// validation
				$name = validate($name, 'name', '', '', array(), true);
				$idna_convert = new idna_convert_wrapper();
				$email = $idna_convert->encode(validate($email, 'email', '', '', array(), true));
				$def_language = validate($def_language, 'default language', '', '', array(), true);
				$custom_notes = validate(str_replace("\r\n", "\n", $custom_notes), 'custom_notes', '/^[^\0]*$/', '', array(), true);
				$theme = validate($theme, 'theme', '', '', array(), true);
				$password = validate($password, 'password', '', '', array(), true);
				
				if (Settings::Get('system.mail_quota_enabled') != '1') {
					$email_quota = - 1;
				}
				
				if (Settings::Get('ticket.enabled') != '1') {
					$tickets = - 1;
				}
				
				if (empty($theme)) {
					$theme = Settings::Get('panel.default_theme');
				}
				
				if ($name == '') {
					standard_error(array(
						'stringisempty',
						'myname'
					), '', true);
				} elseif ($email == '') {
					standard_error(array(
						'stringisempty',
						'emailadd'
					), '', true);
				} elseif (! validateEmail($email)) {
					standard_error('emailiswrong', $email, true);
				} else {
					
					if ($deactivated != '1') {
						$deactivated = '0';
					}
					
					if ($customers_see_all != '1') {
						$customers_see_all = '0';
					}
					
					if ($domains_see_all != '1') {
						$domains_see_all = '0';
					}
					
					if ($caneditphpsettings != '1') {
						$caneditphpsettings = '0';
					}
					
					if ($change_serversettings != '1') {
						$change_serversettings = '0';
					}
					
					if ($tickets_see_all != '1') {
						$tickets_see_all = '0';
					}
					
					if ($password != '') {
						$password = validatePassword($password, true);
						$password = makeCryptPassword($password);
					} else {
						$password = $result['password'];
					}
					
					// check if a resource was set to something lower
					// than actually used by the admin/reseller
					$res_warning = "";
					if ($customers != $result['customers'] && $customers != - 1 && $customers < $result['customers_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'customers');
					}
					if ($domains != $result['domains'] && $domains != - 1 && $domains < $result['domains_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'domains');
					}
					if ($diskspace != $result['diskspace'] && ($diskspace / 1024) != - 1 && $diskspace < $result['diskspace_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'diskspace');
					}
					if ($traffic != $result['traffic'] && ($traffic / 1024 / 1024) != - 1 && $traffic < $result['traffic_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'traffic');
					}
					if ($emails != $result['emails'] && $emails != - 1 && $emails < $result['emails_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'emails');
					}
					if ($email_accounts != $result['email_accounts'] && $email_accounts != - 1 && $email_accounts < $result['email_accounts_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'email accounts');
					}
					if ($email_forwarders != $result['email_forwarders'] && $email_forwarders != - 1 && $email_forwarders < $result['email_forwarders_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'email forwarders');
					}
					if ($email_quota != $result['email_quota'] && $email_quota != - 1 && $email_quota < $result['email_quota_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'email quota');
					}
					if ($ftps != $result['ftps'] && $ftps != - 1 && $ftps < $result['ftps_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'ftps');
					}
					if ($tickets != $result['tickets'] && $tickets != - 1 && $tickets < $result['tickets_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'tickets');
					}
					if ($mysqls != $result['mysqls'] && $mysqls != - 1 && $mysqls < $result['mysqls_used']) {
						$res_warning .= sprintf($this->lng['error']['setlessthanalreadyused'], 'mysqls');
					}
					
					if (! empty($res_warning)) {
						throw new Exception($res_warning, 406);
					}
					
					$upd_data = array(
						'password' => $password,
						'name' => $name,
						'email' => $email,
						'lang' => $def_language,
						'change_serversettings' => $change_serversettings,
						'customers' => $customers,
						'customers_see_all' => $customers_see_all,
						'domains' => $domains,
						'domains_see_all' => $domains_see_all,
						'caneditphpsettings' => $caneditphpsettings,
						'diskspace' => $diskspace,
						'traffic' => $traffic,
						'subdomains' => $subdomains,
						'emails' => $emails,
						'accounts' => $email_accounts,
						'forwarders' => $email_forwarders,
						'quota' => $email_quota,
						'ftps' => $ftps,
						'tickets' => $tickets,
						'tickets_see_all' => $tickets_see_all,
						'mysqls' => $mysqls,
						'ip' => empty($ipaddress) ? "" : (is_array($ipaddress) && $ipaddress > 0 ? json_encode($ipaddress) : - 1),
						'deactivated' => $deactivated,
						'custom_notes' => $custom_notes,
						'custom_notes_show' => $custom_notes_show,
						'theme' => $theme,
						'adminid' => $id
					);
					
					$upd_stmt = Database::prepare("
						UPDATE `" . TABLE_PANEL_ADMINS . "` SET
						`password` = :password,
						`name` = :name,
						`email` = :email,
						`def_language` = :lang,
						`change_serversettings` = :change_serversettings,
						`customers` = :customers,
						`customers_see_all` = :customers_see_all,
						`domains` = :domains,
						`domains_see_all` = :domains_see_all,
						`caneditphpsettings` = :caneditphpsettings,
						`diskspace` = :diskspace,
						`traffic` = :traffic,
						`subdomains` = :subdomains,
						`emails` = :emails,
						`email_accounts` = :accounts,
						`email_forwarders` = :forwarders,
						`email_quota` = :quota,
						`ftps` = :ftps,
						`tickets` = :tickets,
						`tickets_see_all` = :tickets_see_all,
						`mysqls` = :mysqls,
						`ip` = :ip,
						`deactivated` = :deactivated,
						`custom_notes` = :custom_notes,
						`custom_notes_show` = :custom_notes_show,
						`theme` = :theme
						WHERE `adminid` = :adminid
					");
					Database::pexecute($upd_stmt, $upd_data, true, true);
					$this->logger()->logAction(ADM_ACTION, LOG_INFO, "[API] edited admin '" . $result['loginname'] . "'");
					
					// get all admin-data for return-array
					$result = $this->apiCall('Admins.get', array(
						'id' => $result['adminid']
					));
					return $this->response(200, "successfull", $result);
				}
			}
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * delete a admin entry by either id or loginname
	 *
	 * @param int $id
	 *        	optional, the admin-id
	 * @param string $loginname
	 *        	optional, the loginname
	 *        	
	 * @access admin
	 * @throws Exception
	 * @return array
	 */
	public function delete()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			$id = $this->getParam('id', true, 0);
			$ln_optional = ($id <= 0 ? false : true);
			$loginname = $this->getParam('loginname', $ln_optional, '');

			$result = $this->apiCall('Admins.get', array(
				'id' => $id,
				'loginname' => $loginname
			));
			$id = $result['adminid'];
			
			// don't be stupid
			if ($id == $this->getUserDetail('adminid')) {
				standard_error('youcantdeleteyourself', '', true);
			}
			
			// delete admin
			$del_stmt = Database::prepare("
				DELETE FROM `" . TABLE_PANEL_ADMINS . "` WHERE `adminid` = :adminid
			");
			Database::pexecute($del_stmt, array(
				'adminid' => $id
			), true, true);

			// delete the traffic-usage
			$del_stmt = Database::prepare("
				DELETE FROM `" . TABLE_PANEL_TRAFFIC_ADMINS . "` WHERE `adminid` = :adminid
			");
			Database::pexecute($del_stmt, array(
				'adminid' => $id
			), true, true);

			// delete the diskspace usage
			$del_stmt = Database::prepare("
				DELETE FROM `" . TABLE_PANEL_DISKSPACE_ADMINS . "` WHERE `adminid` = :adminid
			");
			Database::pexecute($del_stmt, array(
				'adminid' => $id
			), true, true);

			// set admin-id of the old admin's customer to current admins
			$upd_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
				`adminid` = :userid WHERE `adminid` = :adminid
			");
			Database::pexecute($upd_stmt, array(
				'userid' => $this->getUserDetail('adminid'),
				'adminid' => $id
			), true, true);

			// set admin-id of the old admin's domains to current admins
			$upd_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_DOMAINS . "` SET
				`adminid` = :userid WHERE `adminid` = :adminid
			");
			Database::pexecute($upd_stmt, array(
				'userid' => $this->getUserDetail('adminid'),
				'adminid' => $id
			), true, true);

			// delete old admin's api keys if exists (no customer keys)
			$upd_stmt = Database::prepare("
				DELETE FROM `" . TABLE_API_KEYS . "` WHERE
				`adminid` = :adminid AND `customerid` = '0'
			");
			Database::pexecute($upd_stmt, array(
				'adminid' => $id
			), true, true);

			// set admin-id of the old admin's api-keys to current admins
			$upd_stmt = Database::prepare("
				UPDATE `" . TABLE_API_KEYS . "` SET
				`adminid` = :userid WHERE `adminid` = :adminid
			");
			Database::pexecute($upd_stmt, array(
				'userid' => $this->getUserDetail('adminid'),
				'adminid' => $id
			), true, true);

			$this->logger()->logAction(ADM_ACTION, LOG_WARNING, "[API] deleted admin '" . $result['loginname'] . "'");
			updateCounters();
			return $this->response(200, "successfull", $result);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * unlock a locked admin by either id or loginname
	 *
	 * @param int $id
	 *        	optional, the admin-id
	 * @param string $loginname
	 *        	optional, the loginname
	 *        	
	 * @access admin
	 * @throws Exception
	 * @return array
	 */
	public function unlock()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			$id = $this->getParam('id', true, 0);
			$ln_optional = ($id <= 0 ? false : true);
			$loginname = $this->getParam('loginname', $ln_optional, '');
			
			$result = $this->apiCall('Admins.get', array(
				'id' => $id,
				'loginname' => $loginname
			));
			$id = $result['adminid'];
			
			$result_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_ADMINS . "` SET
				`loginfail_count` = '0'
				WHERE `adminid`= :id
			");
			Database::pexecute($result_stmt, array(
				'id' => $id
			), true, true);
			// set the new value for result-array
			$result['loginfail_count'] = 0;
			
			$this->logger()->logAction(ADM_ACTION, LOG_WARNING, "[API] unlocked admin '" . $result['loginname'] . "'");
			return $this->response(200, "successfull", $result);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	/**
	 * increase resource-usage
	 *
	 * @param int $customerid
	 * @param string $resource
	 * @param string $extra
	 *        	optional, default empty
	 */
	public static function increaseUsage($adminid = 0, $resource = null, $extra = '')
	{
		self::updateResourceUsage(TABLE_PANEL_ADMINS, 'adminid', $adminid, '+', $resource, $extra);
	}

	/**
	 * decrease resource-usage
	 *
	 * @param int $customerid
	 * @param string $resource
	 * @param string $extra
	 *        	optional, default empty
	 */
	public static function decreaseUsage($adminid = 0, $resource = null, $extra = '')
	{
		self::updateResourceUsage(TABLE_PANEL_ADMINS, 'adminid', $adminid, '-', $resource, $extra);
	}
}