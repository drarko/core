<?php
/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Connector\Sabre;

use OC\Files\FileInfo;
use OC\Files\Filesystem;
use OC\Files\Mount\Manager;
use OC\Files\Mount\MoveableMount;
use OC\Files\View;
use OCP\Files\StorageInvalidException;
use OCP\Files\StorageNotAvailableException;
use OCP\Util;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\ICollection;
use Sabre\DAV\URLUtil;

class ObjectTree extends \Sabre\DAV\ObjectTree {

	/**
	 * @var View
	 */
	protected $fileView;

	/**
	 * @var Manager
	 */
	protected $mountManager;

	/**
	 * Creates the object
	 *
	 * This method expects the rootObject to be passed as a parameter
	 */
	public function __construct() {
	}

	/**
	 * @param ICollection $rootNode
	 * @param View $view
	 * @param Manager $mountManager
	 */
	public function init(ICollection $rootNode, View $view, Manager $mountManager) {
		$this->rootNode = $rootNode;
		$this->fileView = $view;
		$this->mountManager = $mountManager;
	}

	/**
	 * Returns the INode object for the requested path
	 *
	 * @param string $path
	 * @throws ServiceUnavailable
	 * @throws NotFound
	 * @return \Sabre\DAV\INode
	 */
	public function getNodeForPath($path) {
		if (!$this->fileView) {
			throw new ServiceUnavailable('filesystem not setup');
		}

		$path = trim($path, '/');
		if (isset($this->cache[$path])) {
			return $this->cache[$path];
		}

		// Is it the root node?
		if (!strlen($path)) {
			return $this->rootNode;
		}

		if (pathinfo($path, PATHINFO_EXTENSION) === 'part') {
			// read from storage
			$absPath = $this->fileView->getAbsolutePath($path);
			list($storage, $internalPath) = Filesystem::resolvePath('/' . $absPath);
			if ($storage) {
				/**
				 * @var \OC\Files\Storage\Storage $storage
				 */
				$scanner = $storage->getScanner($internalPath);
				// get data directly
				$data = $scanner->getData($internalPath);
				$info = new FileInfo($absPath, $storage, $internalPath, $data);
			} else {
				$info = null;
			}
		} else {
			// read from cache
			try {
				$info = $this->fileView->getFileInfo($path);
			} catch (StorageNotAvailableException $e) {
				throw new ServiceUnavailable('Storage not available');
			} catch (StorageInvalidException $e){
				throw new NotFound('Storage ' . $path . ' is invalid');
			}
		}

		if (!$info) {
			throw new NotFound('File with name ' . $path . ' could not be located');
		}

		if ($info->getType() === 'dir') {
			$node = new Directory($this->fileView, $info);
		} else {
			$node = new File($this->fileView, $info);
		}

		$this->cache[$path] = $node;
		return $node;

	}

	/**
	 * Moves a file from one location to another
	 *
	 * @param string $sourcePath The path to the file which should be moved
	 * @param string $destinationPath The full destination path, so not just the destination parent node
	 * @throws BadRequest
	 * @throws ServiceUnavailable
	 * @throws Forbidden
	 * @return int
	 */
	public function move($sourcePath, $destinationPath) {
		if (!$this->fileView) {
			throw new ServiceUnavailable('filesystem not setup');
		}

		$sourceNode = $this->getNodeForPath($sourcePath);
		if ($sourceNode instanceof ICollection and $this->nodeExists($destinationPath)) {
			throw new Forbidden('Could not copy directory ' . $sourceNode . ', target exists');
		}
		list($sourceDir,) = URLUtil::splitPath($sourcePath);
		list($destinationDir,) = URLUtil::splitPath($destinationPath);

		$isMovableMount = false;
		$sourceMount = $this->mountManager->find($this->fileView->getAbsolutePath($sourcePath));
		$internalPath = $sourceMount->getInternalPath($this->fileView->getAbsolutePath($sourcePath));
		if ($sourceMount instanceof MoveableMount && $internalPath === '') {
			$isMovableMount = true;
		}

		try {
			// check update privileges
			if (!$this->fileView->isUpdatable($sourcePath) && !$isMovableMount) {
				throw new Forbidden();
			}
			if ($sourceDir !== $destinationDir) {
				if (!$this->fileView->isCreatable($destinationDir)) {
					throw new Forbidden();
				}
				if (!$this->fileView->isDeletable($sourcePath) && !$isMovableMount) {
					throw new Forbidden();
				}
			}

			$fileName = basename($destinationPath);
			if (!Util::isValidFileName($fileName)) {
				throw new BadRequest();
			}

			$renameOkay = $this->fileView->rename($sourcePath, $destinationPath);
			if (!$renameOkay) {
				throw new Forbidden('');
			}
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable($e->getMessage());
		}

		// update properties
		$query = \OC_DB::prepare('UPDATE `*PREFIX*properties` SET `propertypath` = ?'
			. ' WHERE `userid` = ? AND `propertypath` = ?');
		$query->execute(array(Filesystem::normalizePath($destinationPath), \OC_User::getUser(),
			Filesystem::normalizePath($sourcePath)));

		$this->markDirty($sourceDir);
		$this->markDirty($destinationDir);

	}

	/**
	 * Copies a file or directory.
	 *
	 * This method must work recursively and delete the destination
	 * if it exists
	 *
	 * @param string $source
	 * @param string $destination
	 * @throws ServiceUnavailable
	 * @return void
	 */
	public function copy($source, $destination) {
		if (!$this->fileView) {
			throw new ServiceUnavailable('filesystem not setup');
		}

		try {
			if ($this->fileView->is_file($source)) {
				$this->fileView->copy($source, $destination);
			} else {
				$this->fileView->mkdir($destination);
				$dh = $this->fileView->opendir($source);
				if (is_resource($dh)) {
					while (($subNode = readdir($dh)) !== false) {

						if ($subNode == '.' || $subNode == '..') continue;
						$this->copy($source . '/' . $subNode, $destination . '/' . $subNode);

					}
				}
			}
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable($e->getMessage());
		}

		list($destinationDir,) = URLUtil::splitPath($destination);
		$this->markDirty($destinationDir);
	}
}
