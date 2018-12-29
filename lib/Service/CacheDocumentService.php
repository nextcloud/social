<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;


class CacheDocumentService {


	use TArrayTools;


	/** @var IAppData */
	private $appData;

	/** @var CurlService */
	private $curlService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CacheService constructor.
	 *
	 * @param IAppData $appData
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IAppData $appData, CurlService $curlService, ConfigService $configService,
		MiscService $miscService
	) {
		$this->appData = $appData;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $url
	 *
	 * @param string $mime
	 *
	 * @return string
	 * @throws CacheContentMimeTypeException
	 * @throws MalformedArrayException
	 * @throws NotPermittedException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestServerException
	 * @throws RequestResultSizeException
	 */
	public function saveRemoteFileToCache(string $url, &$mime = '') {

		$filename = sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff), mt_rand(0, 0xfff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);

		// creating a path aa/bb/cc/dd/ from the filename aabbccdd-0123-[...]
		$path = chunk_split(substr($filename, 0, 8), 2, '/');

		try {
			$folder = $this->appData->getFolder($path);
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder($path);
		}

		$content = $this->retrieveContent($url);

		// To get the mime type, we create a temp file
		$tmpFile = tmpfile();
		$tmpPath = stream_get_meta_data($tmpFile)['uri'];
		fwrite($tmpFile, $content);
		$mime = mime_content_type($tmpPath);
		fclose($tmpFile);

		$this->filterMimeTypes($mime);

		$cache = $folder->newFile($filename);
		$cache->putContent($content);

		return $path . $filename;
	}


	/**
	 *
	 * @param string $mime
	 *
	 * @throws CacheContentMimeTypeException
	 */
	public function filterMimeTypes(string $mime) {

		$allowedMimeType = [
			'image/jpeg',
			'image/gif',
			'image/png'
		];

		if (in_array($mime, $allowedMimeType)) {
			return;
		}

		throw new CacheContentMimeTypeException();
	}

	/**
	 * @param string $path
	 *
	 * @return ISimpleFile
	 * @throws CacheContentException
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getContentFromCache(string $path) {
		if ($path === '') {
			throw new CacheDocumentDoesNotExistException();
		}

		$pos = strrpos($path, '/');
		$dir = substr($path, 0, $pos);
		$filename = substr($path, $pos + 1);

		try {
			$file = $this->appData->getFolder($dir)
								  ->getFile($filename);

			return $file;
		} catch (Exception $e) {
			throw new CacheContentException();
		}
	}


	/**
	 * @param string $url
	 *
	 * @return string
	 * @throws MalformedArrayException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestServerException
	 * @throws RequestResultSizeException
	 */
	public function retrieveContent(string $url) {
		$url = parse_url($url);
		$this->mustContains(['path', 'host'], $url);
		$request = new Request($url['path'], Request::TYPE_GET, true);
		$request->setAddress($url['host']);

		$content = $this->curlService->request($request);

		return $content;
	}

}

