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

use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\URLUtil;

class FilesPlugin extends ServerPlugin {

	// namespace
	const NS_OWNCLOUD = 'http://owncloud.org/ns';

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

		$server->xmlNamespaces[self::NS_OWNCLOUD] = 'oc';
		$server->protectedProperties[] = '{' . self::NS_OWNCLOUD . '}id';
		$server->protectedProperties[] = '{' . self::NS_OWNCLOUD . '}permissions';
		$server->protectedProperties[] = '{' . self::NS_OWNCLOUD . '}size';

		$this->server = $server;
		$this->server->subscribeEvent('beforeGetProperties', array($this, 'beforeGetProperties'));
		$this->server->subscribeEvent('afterBind', array($this, 'sendFileIdHeader'));
		$this->server->subscribeEvent('afterWriteContent', array($this, 'sendFileIdHeader'));
	}

	/**
	 * Adds all ownCloud-specific properties
	 *
	 * @param string $path
	 * @param INode $node
	 * @param array $requestedProperties
	 * @param array $returnedProperties
	 * @return void
	 */
	public function beforeGetProperties($path, INode $node, array &$requestedProperties, array &$returnedProperties) {

		if ($node instanceof Node) {

			$fileIdPropertyName = '{' . self::NS_OWNCLOUD . '}id';
			$permissionsPropertyName = '{' . self::NS_OWNCLOUD . '}permissions';
			if (array_search($fileIdPropertyName, $requestedProperties)) {
				unset($requestedProperties[array_search($fileIdPropertyName, $requestedProperties)]);
			}
			if (array_search($permissionsPropertyName, $requestedProperties)) {
				unset($requestedProperties[array_search($permissionsPropertyName, $requestedProperties)]);
			}

			/** @var $node Node */
			$fileId = $node->getFileId();
			if (!is_null($fileId)) {
				$returnedProperties[200][$fileIdPropertyName] = $fileId;
			}

			$permissions = $node->getDavPermissions();
			if (!is_null($permissions)) {
				$returnedProperties[200][$permissionsPropertyName] = $permissions;
			}
		}

		if ($node instanceof Directory) {
			$sizePropertyName = '{' . self::NS_OWNCLOUD . '}size';

			/** @var $node Directory */
			$returnedProperties[200][$sizePropertyName] = $node->getSize();
		}
	}

	/**
	 * @param string $filePath
	 * @param INode $node
	 * @throws \Sabre\DAV\Exception\BadRequest
	 */
	public function sendFileIdHeader($filePath, INode $node = null) {
		// chunked upload handling
		if (isset($_SERVER['HTTP_OC_CHUNKED'])) {
			list($path, $name) = URLUtil::splitPath($filePath);
			$info = \OC_FileChunking::decodeName($name);
			if (!empty($info)) {
				$filePath = $path . '/' . $info['name'];
			}
		}

		// we get the node for the given $filePath here because in case of afterCreateFile $node is the parent folder
		if (!$this->server->tree->nodeExists($filePath)) {
			return;
		}
		$node = $this->server->tree->getNodeForPath($filePath);
		if ($node instanceof Node) {
			$fileId = $node->getFileId();
			if (!is_null($fileId)) {
				$this->server->httpResponse->setHeader('OC-FileId', $fileId);
			}
			$eTag = $node->getETag();
			if (!is_null($eTag)) {
				$this->server->httpResponse->setHeader('OC-ETag', $eTag);
			}
		}
	}

}
