<?php

namespace OC\Connector\Sabre;

use OCP\Files\FileInfo;
use OCP\Files\StorageNotAvailableException;
use Sabre\DAV\Exception\InsufficientStorage;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\URLUtil;

/**
 * This plugin check user quota and deny creating files when they exceeds the quota.
 *
 * @author Sergio Cambra
 * @copyright Copyright (C) 2012 entreCables S.L. All rights reserved.
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class QuotaPlugin extends ServerPlugin {

	/**
	 * @var \OC\Files\View
	 */
	private $view;

	/**
	 * Reference to main server object
	 *
	 * @var Server
	 */
	private $server;

	/**
	 * @param \OC\Files\View $view
	 */
	public function __construct($view) {
		$this->view = $view;
	}

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the requires event subscriptions.
	 *
	 * @param Server $server
	 * @return void
	 */
	public function initialize(Server $server) {

		$this->server = $server;

		$server->subscribeEvent('beforeWriteContent', array($this, 'checkQuota'), 10);
		$server->subscribeEvent('beforeCreateFile', array($this, 'checkQuota'), 10);
	}

	/**
	 * This method is called before any HTTP method and validates there is enough free space to store the file
	 *
	 * @param string $uri
	 * @param null $data
	 * @throws InsufficientStorage
	 * @return bool
	 */
	public function checkQuota($uri, $data = null) {
		$length = $this->getLength();
		if ($length) {
			if (substr($uri, 0, 1) !== '/') {
				$uri = '/' . $uri;
			}
			list($parentUri, $newName) = URLUtil::splitPath($uri);
			$req = $this->server->httpRequest;
			if ($req->getHeader('OC-Chunked')) {
				$info = \OC_FileChunking::decodeName($newName);
				$chunkHandler = new \OC_FileChunking($info);
				// subtract the already uploaded size to see whether
				// there is still enough space for the remaining chunks
				$length -= $chunkHandler->getCurrentSize();
			}
			$freeSpace = $this->getFreeSpace($parentUri);
			if ($freeSpace !== FileInfo::SPACE_UNKNOWN && $length > $freeSpace) {
				if (isset($chunkHandler)) {
					$chunkHandler->cleanup();
				}
				throw new InsufficientStorage();
			}
		}
		return true;
	}

	public function getLength() {
		$req = $this->server->httpRequest;
		$length = $req->getHeader('X-Expected-Entity-Length');
		if (!$length) {
			$length = $req->getHeader('Content-Length');
		}

		$ocLength = $req->getHeader('OC-Total-Length');
		if ($length && $ocLength) {
			return max($length, $ocLength);
		}

		return $length;
	}

	/**
	 * @param string $parentUri
	 * @return mixed
	 * @throws ServiceUnavailable
	 */
	public function getFreeSpace($parentUri) {
		try {
			$freeSpace = $this->view->free_space($parentUri);
			return $freeSpace;
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable($e->getMessage());
		}
	}
}
