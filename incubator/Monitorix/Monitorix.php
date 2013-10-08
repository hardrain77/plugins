<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2013 by i-MSCP Team
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
 * @subpackage  Monitorix
 * @copyright   2010-2013 by i-MSCP Team
 * @author      Sascha Bay <info@space2place.de>
 * @link        http://www.i-mscp.net i-MSCP Home Site
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GPL v2
 */

/**
 * @category    iMSCP
 * @package     iMSCP_Plugin
 * @subpackage  Monitorix
 * @author      Sascha Bay <info@space2place.de>
 */
class iMSCP_Plugin_Monitorix extends iMSCP_Plugin_Action
{
	/**
	 * @var array Routes
	 */
	protected $routes = array();

	/**
	 * Register a callback for the given event(s).
	 *
	 * @param iMSCP_Events_Manager_Interface $controller
	 */
	public function register(iMSCP_Events_Manager_Interface $controller)
	{
		$controller->registerListener(
			array(
				iMSCP_Events::onBeforeActivatePlugin,
				iMSCP_Events::onBeforePluginsRoute,
				iMSCP_Events::onAdminScriptStart,
			),
			$this
		);
	}

	/**
	 * onBeforeActivatePlugin event listener
	 *
	 * @param iMSCP_Events_Event $event
	 */
	public function onBeforeActivatePlugin($event)
	{
		/** @var iMSCP_Config_Handler_File $cfg */
		$cfg = iMSCP_Registry::get('config');
		
		if($event->getParam('action') == 'install') {
			if($cfg->Version != 'Git Master' && $cfg->BuildDate <= 20130723) {
				set_page_message(
					tr('Your i-MSCP version is not compatible with this plugin. Try with a newer version'), 'error'
				);
				
				$event->stopPropagation(true);
			}
		}
	}
	
	/**
	 * onBeforePluginsRoute listener
	 *
	 * @return void
	 */
	public function onBeforePluginsRoute()
	{
		$pluginName = $this->getName();

		$this->routes = array(
			'/admin/monitorix.php' => PLUGINS_PATH . '/' . $pluginName . '/frontend/monitorix.php',
			'/admin/monitorixgraphics.php' => PLUGINS_PATH . '/' . $pluginName . '/frontend/monitorixgraphics.php'
		);
	}

	/**
	 * onAdminScriptStart listener
	 *
	 * @return void
	 */
	public function onAdminScriptStart()
	{
		$this->setupNavigation();
	}

	/**
	 * Get routes
	 *
	 * @return array
	 */
	public function getRoutes()
	{
		return $this->routes;
	}

	/**
	 * Inject Monitorix links into the navigation object
	 */
	protected function setupNavigation()
	{
		if (iMSCP_Registry::isRegistered('navigation')) {
			/** @var Zend_Navigation $navigation */
			$navigation = iMSCP_Registry::get('navigation');

			if (($page = $navigation->findOneBy('uri', '/admin/server_statistic.php'))) {
				$page->addPage(
					array(
						'label' => tohtml(tr('Monitorix')),
						'uri' => '/admin/monitorix.php',
						'title_class' => 'stats'
					)
				);
			}
		}
	}
}
