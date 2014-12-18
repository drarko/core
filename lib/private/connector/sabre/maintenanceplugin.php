<?php

/**
 * ownCloud
 *
 * @author Thomas Müller
 * @copyright 2013 Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL3
 */

namespace OC\Connector\Sabre;

use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

class MaintenancePlugin extends ServerPlugin {

	/**
	 * Reference to main server object
	 *
	 * @var Server
	 */
	private $server;

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the required event subscriptions.
	 *
	 * @param Server $server
	 * @return void
	 */
	public function initialize(Server $server) {

		$this->server = $server;
		$this->server->subscribeEvent('beforeMethod', array($this, 'checkMaintenanceMode'), 10);
	}

	/**
	 * This method is called before any HTTP method and returns http status code 503
	 * in case the system is in maintenance mode.
	 *
	 * @throws ServiceUnavailable
	 * @internal param string $method
	 * @return bool
	 */
	public function checkMaintenanceMode() {
		if (\OC_Config::getValue('maintenance', false)) {
			throw new ServiceUnavailable();
		}
		if (\OC::checkUpgrade(false)) {
			throw new ServiceUnavailable('Upgrade needed');
		}

		return true;
	}
}
