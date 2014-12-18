<?php

/**
 * ownCloud
 *
 * @author Vincent Petry
 * @copyright 2014 Vincent Petry <pvince81@owncloud.com>
 *
 * @license AGPL3
 */

namespace OC\Connector\Sabre;

use OCP\Util;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

class ExceptionLoggerPlugin extends ServerPlugin
{
	private $nonFatalExceptions = array(
		'Sabre\DAV\Exception\NotAuthenticated' => true,
		// the sync client uses this to find out whether files exist,
		// so it is not always an error, log it as debug
		'Sabre\DAV\Exception\NotFound' => true,
		// this one mostly happens when the same file is uploaded at
		// exactly the same time from two clients, only one client
		// wins, the second one gets "Precondition failed"
		'Sabre\DAV\Exception\PreconditionFailed' => true,
	);

	private $appName;

	/**
	 * @param string $loggerAppName app name to use when logging
	 */
	public function __construct($loggerAppName = 'webdav') {
		$this->appName = $loggerAppName;
	}

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

		$server->subscribeEvent('exception', array($this, 'logException'), 10);
	}

	/**
	 * Log exception
	 *
	 * @internal param Exception $e exception
	 */
	public function logException($e) {
		$exceptionClass = get_class($e);
		$level = Util::FATAL;
		if (isset($this->nonFatalExceptions[$exceptionClass])) {
			$level = Util::DEBUG;
		}
		Util::logException($this->appName, $e, $level);
	}
}
