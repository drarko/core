<?php

/**
 * ownCloud
 *
 * @author Jakob Sack
 * @copyright 2011 Jakob Sack kde@jakobsack.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Connector\Sabre;

use OC\Connector\Sabre\Exception\EntityTooLarge;
use OC\Connector\Sabre\Exception\FileLocked;
use OC\Connector\Sabre\Exception\UnsupportedMediaType;
use OCA\Files_Encryption\Exception\EncryptionException;
use OCP\Files\EntityTooLargeException;
use OCP\Files\InvalidContentException;
use OCP\Files\InvalidPathException;
use OCP\Files\LockNotAcquiredException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Util;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotImplemented;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\IFile;
use Sabre\DAV\URLUtil;

class File extends Node implements IFile {

	/**
	 * Updates the data
	 *
	 * The data argument is a readable stream resource.
	 *
	 * After a successful put operation, you may choose to return an ETag. The
	 * etag must always be surrounded by double-quotes. These quotes must
	 * appear in the actual string you're returning.
	 *
	 * Clients may use the ETag from a PUT request to later on make sure that
	 * when they update the file, the contents haven't changed in the mean
	 * time.
	 *
	 * If you don't plan to store the file byte-by-byte, and you return a
	 * different object on a subsequent GET you are strongly recommended to not
	 * return an ETag, and just return null.
	 *
	 * @param resource $data
	 * @throws Forbidden
	 * @throws UnsupportedMediaType
	 * @throws BadRequest
	 * @throws Exception
	 * @throws EntityTooLarge
	 * @throws ServiceUnavailable
	 * @return string|null
	 */
	public function put($data) {
		try {
			if ($this->info && $this->fileView->file_exists($this->path) &&
				!$this->info->isUpdateable()) {
				throw new Forbidden();
			}
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable($e->getMessage());
		}

		// throw an exception if encryption was disabled but the files are still encrypted
		if (\OC_Util::encryptedFiles()) {
			throw new ServiceUnavailable();
		}

		$fileName = basename($this->path);
		if (!Util::isValidFileName($fileName)) {
			throw new BadRequest();
		}

		// chunked handling
		if (isset($_SERVER['HTTP_OC_CHUNKED'])) {
			return $this->createFileChunked($data);
		}

		// mark file as partial while uploading (ignored by the scanner)
		$partFilePath = $this->path . '.ocTransferId' . rand() . '.part';

		try {
			$putOkay = $this->fileView->file_put_contents($partFilePath, $data);
			if ($putOkay === false) {
				\OC_Log::write('webdav', '\OC\Files\Filesystem::file_put_contents() failed', \OC_Log::ERROR);
				$this->fileView->unlink($partFilePath);
				// because we have no clue about the cause we can only throw back a 500/Internal Server Error
				throw new Exception('Could not write file contents');
			}
		} catch (NotPermittedException $e) {
			// a more general case - due to whatever reason the content could not be written
			throw new Forbidden($e->getMessage());

		} catch (EntityTooLargeException $e) {
			// the file is too big to be stored
			throw new EntityTooLarge($e->getMessage());

		} catch (InvalidContentException $e) {
			// the file content is not permitted
			throw new UnsupportedMediaType($e->getMessage());

		} catch (InvalidPathException $e) {
			// the path for the file was not valid
			// TODO: find proper http status code for this case
			throw new Forbidden($e->getMessage());
		} catch (LockNotAcquiredException $e) {
			// the file is currently being written to by another process
			throw new FileLocked($e->getMessage(), $e->getCode(), $e);
		} catch (EncryptionException $e) {
			throw new Forbidden($e->getMessage());
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable($e->getMessage());
		}

		try {
			// if content length is sent by client:
			// double check if the file was fully received
			// compare expected and actual size
			if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['REQUEST_METHOD'] !== 'LOCK') {
				$expected = $_SERVER['CONTENT_LENGTH'];
				$actual = $this->fileView->filesize($partFilePath);
				if ($actual != $expected) {
					$this->fileView->unlink($partFilePath);
					throw new BadRequest('expected filesize ' . $expected . ' got ' . $actual);
				}
			}

			// rename to correct path
			try {
				$renameOkay = $this->fileView->rename($partFilePath, $this->path);
				$fileExists = $this->fileView->file_exists($this->path);
				if ($renameOkay === false || $fileExists === false) {
					\OC_Log::write('webdav', '\OC\Files\Filesystem::rename() failed', \OC_Log::ERROR);
					$this->fileView->unlink($partFilePath);
					throw new Exception('Could not rename part file to final file');
				}
			}
			catch (LockNotAcquiredException $e) {
				// the file is currently being written to by another process
				throw new FileLocked($e->getMessage(), $e->getCode(), $e);
			}

			// allow sync clients to send the mtime along in a header
			$mtime = \OC_Request::hasModificationTime();
			if ($mtime !== false) {
				if($this->fileView->touch($this->path, $mtime)) {
					header('X-OC-MTime: accepted');
				}
			}
			$this->refreshInfo();
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable($e->getMessage());
		}

		return '"' . $this->info->getEtag() . '"';
	}

	/**
	 * Returns the data
	 *
	 * @return string|resource
	 * @throws Forbidden
	 * @throws ServiceUnavailable
	 */
	public function get() {

		//throw exception if encryption is disabled but files are still encrypted
		if (\OC_Util::encryptedFiles()) {
			throw new ServiceUnavailable();
		} else {
			try {
				return $this->fileView->fopen(ltrim($this->path, '/'), 'rb');
			} catch (EncryptionException $e) {
				throw new Forbidden($e->getMessage());
			} catch (StorageNotAvailableException $e) {
				throw new ServiceUnavailable($e->getMessage());
			}
		}

	}

	/**
	 * Delete the current file
	 *
	 * @return void
	 * @throws Forbidden
	 * @throws ServiceUnavailable
	 */
	public function delete() {
		if (!$this->info->isDeletable()) {
			throw new Forbidden();
		}

		try {
			if (!$this->fileView->unlink($this->path)) {
				// assume it wasn't possible to delete due to permissions
				throw new Forbidden();
			}
		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable($e->getMessage());
		}

		// remove properties
		$this->removeProperties();

	}

	/**
	 * Returns the size of the node, in bytes
	 *
	 * @return int|float
	 */
	public function getSize() {
		return $this->info->getSize();
	}

	/**
	 * Returns the mime-type for a file
	 *
	 * If null is returned, we'll assume application/octet-stream
	 *
	 * @return mixed
	 */
	public function getContentType() {
		$mimeType = $this->info->getMimetype();

		// PROPFIND needs to return the correct mime type, for consistency with the web UI
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'PROPFIND' ) {
			return $mimeType;
		}
		return \OC_Helper::getSecureMimeType($mimeType);
	}

	/**
	 * @param resource $data
	 * @return null|string
	 * @throws BadRequest
	 * @throws Exception
	 * @throws NotImplemented
	 * @throws ServiceUnavailable
	 */
	private function createFileChunked($data)
	{
		list($path, $name) = URLUtil::splitPath($this->path);

		$info = \OC_FileChunking::decodeName($name);
		if (empty($info)) {
			throw new NotImplemented();
		}
		$chunk_handler = new \OC_FileChunking($info);
		$bytesWritten = $chunk_handler->store($info['index'], $data);

		//detect aborted upload
		if (isset ($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'PUT' ) {
			if (isset($_SERVER['CONTENT_LENGTH'])) {
				$expected = $_SERVER['CONTENT_LENGTH'];
				if ($bytesWritten != $expected) {
					$chunk_handler->remove($info['index']);
					throw new BadRequest(
						'expected filesize ' . $expected . ' got ' . $bytesWritten);
				}
			}
		}

		if ($chunk_handler->isComplete()) {

			try {
				// we first assembly the target file as a part file
				$partFile = $path . '/' . $info['name'] . '.ocTransferId' . $info['transferid'] . '.part';
				$chunk_handler->file_assemble($partFile);

				// here is the final atomic rename
				$targetPath = $path . '/' . $info['name'];
				$renameOkay = $this->fileView->rename($partFile, $targetPath);
				$fileExists = $this->fileView->file_exists($targetPath);
				if ($renameOkay === false || $fileExists === false) {
					\OC_Log::write('webdav', '\OC\Files\Filesystem::rename() failed', \OC_Log::ERROR);
					// only delete if an error occurred and the target file was already created
					if ($fileExists) {
						$this->fileView->unlink($targetPath);
					}
					throw new Exception('Could not rename part file assembled from chunks');
				}

				// allow sync clients to send the mtime along in a header
				$mtime = \OC_Request::hasModificationTime();
				if ($mtime !== false) {
					if($this->fileView->touch($targetPath, $mtime)) {
						header('X-OC-MTime: accepted');
					}
				}

				$info = $this->fileView->getFileInfo($targetPath);
				return $info->getEtag();
			} catch (StorageNotAvailableException $e) {
				throw new ServiceUnavailable($e->getMessage());
			}
		}

		return null;
	}
}
