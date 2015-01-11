<?php
/**
 * ownCloud - galleryplus
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Olivier Paroz <owncloud@interfasys.ch>
 * @author Robin Appelman <icewind@owncloud.com>
 *
 * @copyright Olivier Paroz 2014-2015
 * @copyright Robin Appelman 2012-2015
 */

namespace OCA\GalleryPlus\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IPreview;

use OCP\AppFramework\Http;

use OCA\GalleryPlus\Http\ImageResponse;
use OCA\GalleryPlus\Utility\SmarterLogger;

/**
 * Generates previews
 *
 * @todo On OC8.1, replace \OC\Preview with OC::$server->getPreviewManager()
 *
 * @package OCA\GalleryPlus\Service
 */
class PreviewService extends Service {

	/**
	 * @type EnvironmentService
	 */
	private $environmentService;
	/**
	 * @type mixed
	 */
	private $previewManager;
	/**
	 * @type bool
	 */
	private $animatedPreview = true;
	/**
	 * @type bool
	 */
	private $keepAspect = true;
	/**
	 * @type bool
	 */
	private $base64Encode = false;
	/**
	 * @type bool
	 */
	private $download = false;

	/**
	 * Constructor
	 *
	 * @param string $appName
	 * @param EnvironmentService $environmentService
	 * @param SmarterLogger $logger
	 * @param IPreview $previewManager
	 */
	public function __construct(
		$appName,
		EnvironmentService $environmentService,
		SmarterLogger $logger,
		IPreview $previewManager
	) {
		parent::__construct($appName, $logger);

		$this->environmentService = $environmentService;
		$this->previewManager = $previewManager;
	}

	/**
	 * @param string $image
	 * @param int $maxX
	 * @param int $maxY
	 * @param bool $keepAspect
	 *
	 * @return string[] preview data
	 */
	public function createThumbnails($image, $maxX, $maxY, $keepAspect) {
		$this->animatedPreview = false;
		$this->base64Encode = true;
		$this->keepAspect = $keepAspect;

		return $this->createPreview($image, $maxX, $maxY);
	}


	/**
	 * Sends either a large preview of the requested file or the original file
	 * itself
	 *
	 * @param string $image
	 * @param int $maxX
	 * @param int $maxY
	 *
	 * @return ImageResponse
	 */
	public function showPreview($image, $maxX, $maxY) {
		$preview = $this->createPreview($image, $maxX, $maxY);

		return new ImageResponse($preview['data'], $preview['status']);
	}

	/**
	 * Downloads the requested file
	 *
	 * @param string $image
	 *
	 * @return ImageResponse
	 */
	public function downloadPreview($image) {
		$this->download = true;

		return $this->showPreview($image, null, null);
	}

	/**
	 * Creates an array containing everything needed to render a preview in the
	 * browser
	 *
	 * If the browser can use the file as-is or if we're asked to send it
	 * as-is, then we simply let the browser download the file, straight from
	 * Files
	 *
	 * Some files are base64 encoded. Explicitly for files which are downloaded
	 * (cached Thumbnails, SVG, GIFs) and via __toStrings for the previews
	 * which
	 * are instances of \OC_Image
	 *
	 * We check that the preview returned by the Preview class can be used by
	 * the browser. If not, we send the mime icon and change the status code so
	 * that the client knows that the process failed
	 *
	 * @todo Get the max size from the settings
	 *
	 * @param string $image path to the image, relative to the user folder
	 * @param int $maxX asked width for the preview
	 * @param int $maxY asked height for the preview
	 *
	 * @return array preview data
	 */
	private function createPreview($image, $maxX = 0, $maxY = 0) {
		$env = $this->environmentService->getEnv();
		$owner = $env['owner'];
		/** @type Folder $folder */
		$folder = $env['folder'];
		$imagePathFromFolder = $env['relativePath'] . $image;
		/** @type File $file */
		$file = $this->getResource($folder, $imagePathFromFolder);

		// FIXME: Private API, but can't use the PreviewManager yet as it's incomplete
		$preview = new \OC\Preview($owner, 'files', $imagePathFromFolder);
		$previewRequired = $this->previewRequired($file, $preview);
		if ($previewRequired) {
			$perfectPreview = $this->preparePreview($owner, $file, $preview, $maxX, $maxY);
		} else {
			$perfectPreview = $this->prepareDownload($file, $image);
		}
		$perfectPreview['preview'] = $this->base64EncodeCheck($perfectPreview['preview']);

		return $this->packagePreview($perfectPreview, $image);
	}

	/**
	 * Prepares the response to send back to the client
	 *
	 * We're creating the array first so that we can log the elements before sending the response
	 *
	 * @param array $perfectPreview
	 * @param string $image
	 *
	 * @return array
	 */
	private function packagePreview($perfectPreview, $image) {
		$perfectPreview['path'] = $image;
		$response = array(
			'data'   => $perfectPreview,
			'status' => $perfectPreview['status']
		);

		/*$this->logger->debug(
			"[PreviewService] PREVIEW Path : {path} / size: {size} / mime: {mimetype} / status: {status}",
			array(
				'path'     => $response['data']['path'],
				'mimetype' => $response['data']['mimetype'],
				'status'   => $response['status']
			)
		);*/

		return $response;
	}

	/**
	 * Decides if we should download the file instead of generating a preview
	 *
	 * @param File $file
	 * @param \OC\Preview $preview
	 *
	 * @return bool
	 */
	private function previewRequired($file, $preview) {
		$svgPreviewRequired = $this->isSvgPreviewRequired($file, $preview);
		$gifPreviewRequired = $this->isGifPreviewRequired($file);

		return $svgPreviewRequired && $gifPreviewRequired && !$this->download;
	}

	/**
	 * Decides if we should download the SVG or generate a preview
	 *
	 * @param File $file
	 * @param \OC\Preview $preview
	 *
	 * @return bool
	 */
	private function isSvgPreviewRequired($file, $preview) {
		$mime = $file->getMimeType();

		/**
		 * SVGs are downloaded if the SVG converter is disabled
		 * Files of any media type are downloaded if requested by the client
		 */
		if ($mime === 'image/svg+xml' && !$preview->isMimeSupported($mime)) {
			return false;
		}

		return true;
	}

	/**
	 * Decides if we should download the GIF or generate a preview
	 *
	 * @param File $file
	 *
	 * @return bool
	 */
	private function isGifPreviewRequired($file) {
		$animatedPreview = $this->animatedPreview;
		$mime = $file->getMimeType();
		$animatedGif = $this->isGifAnimated($file);

		/**
		 * GIFs are downloaded if they're animated and we want to show
		 * animations
		 */
		if ($mime === 'image/gif' && $animatedPreview && $animatedGif) {
			return false;
		}

		return true;
	}

	/**
	 * Tests if a GIF is animated
	 *
	 * An animated gif contains multiple "frames", with each frame having a
	 * header made up of:
	 *    * a static 4-byte sequence (\x00\x21\xF9\x04)
	 *    * 4 variable bytes
	 *    * a static 2-byte sequence (\x00\x2C) (Photoshop uses \x00\x21)
	 *
	 * We read through the file until we reach the end of the file, or we've
	 * found at least 2 frame headers
	 *
	 * @link http://php.net/manual/en/function.imagecreatefromgif.php#104473
	 *
	 * @param File $file
	 *
	 * @return bool
	 */
	private function isGifAnimated($file) {
		$fileHandle = $file->fopen('rb');
		$count = 0;
		while (!feof($fileHandle) && $count < 2) {
			$chunk = fread($fileHandle, 1024 * 100); //read 100kb at a time
			$count += preg_match_all(
				'#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk, $matches
			);
		}

		fclose($fileHandle);

		return $count > 1;
	}

	/**
	 * Returns a preview based on OC's preview class and our custom methods
	 *
	 * We don't throw an exception when the preview generator fails,
	 * instead, until the Preview class is fixed, we send the mime
	 * icon along with a 415 error code.
	 *
	 * @fixme setKeepAspect is missing from public interface.
	 *     https://github.com/owncloud/core/issues/12772
	 *
	 * @param string $owner
	 * @param File $file
	 * @param \OC\Preview $preview
	 * @param int $maxX
	 * @param int $maxY
	 *
	 * @return array
	 */
	private function preparePreview($owner, $file, $preview, $maxX, $maxY) {
		$preview->setMaxX($maxX);
		$preview->setMaxY($maxY);
		$preview->setScalingUp(false); // TODO: Need to read from settings
		$preview->setKeepAspect($this->keepAspect);
		$this->logger->debug("[PreviewService] Generating a new preview");
		/** @type \OC_Image $previewData */
		$previewData = $preview->getPreview();
		if ($previewData->valid()) {
			$perfectPreview = $this->previewValidator($owner, $file, $preview, $maxX, $maxY);
		} else {
			$this->logger->debug("[PreviewService] ERROR! Did not get a preview");
			$perfectPreview = array(
				'preview' => $this->getMimeIcon($file),
				'status'  => Http::STATUS_UNSUPPORTED_MEDIA_TYPE
			);
		}
		$perfectPreview['mimetype'] = 'image/png'; // Previews are always sent as PNG

		return $perfectPreview;
	}

	/**
	 * Returns the data needed to make a file available for download
	 *
	 * @param File $file
	 * @param string $image
	 *
	 * @return array
	 */
	private function prepareDownload($file, $image) {
		$this->logger->debug("[PreviewService] Downloading file {file} as-is", ['file' => $image]);

		return array(
			'preview' => $file->getContent(),
			'mimetype' => $file->getMimeType(),
			'status'  => Http::STATUS_OK
		);
	}

	/**
	 * Makes sure we return previews of the asked dimensions and fix the cache
	 * if necessary
	 *
	 * The Preview class of OC7 sometimes return previews which are either
	 * wider or smaller than the asked dimensions. This happens when one of the
	 * original dimension is smaller than what is asked for
	 *
	 * @param string $owner
	 * @param File $file
	 * @param \OC\Preview $preview
	 * @param int $maxX
	 * @param int $maxY
	 *
	 * @return array<resource,int>
	 */
	private function previewValidator($owner, $file, $preview, $maxX, $maxY) {
		$previewData = $preview->getPreview();
		$previewX = $previewData->width();
		$previewY = $previewData->height();
		$minWidth = 200; // Only fixing the square thumbnails
		if (($previewX > $maxX
			 || ($previewX < $maxX || $previewY < $maxY)
				&& $maxX === $minWidth)
		) {
			$fixedPreview = $this->fixPreview($previewData, $maxX, $maxY);
			$previewData = $this->fixPreviewCache($owner, $file, $preview, $fixedPreview);
		}

		return array(
			'preview' => $previewData,
			'status' => Http::STATUS_OK
		);
	}


	/**
	 * Makes a preview fit in the asked dimension and fills the empty space
	 *
	 * @param \OC_Image $previewData
	 * @param int $maxX
	 * @param int $maxY
	 *
	 * @return resource
	 */
	private function fixPreview($previewData, $maxX, $maxY) {
		$previewWidth = $previewData->width();
		$previewHeight = $previewData->height();

		$fixedPreview = imagecreatetruecolor($maxX, $maxY); // Creates the canvas
		imagealphablending($fixedPreview, false); // We make the background transparent
		$transparency = imagecolorallocatealpha($fixedPreview, 0, 0, 0, 127);
		imagefill($fixedPreview, 0, 0, $transparency);
		imagesavealpha($fixedPreview, true);

		$newDimensions = $this->calculateNewDimensions($previewWidth, $previewHeight, $maxX, $maxY);

		imagecopyresampled(
			$fixedPreview, $previewData->resource(), $newDimensions['newX'], $newDimensions['newY'],
			0, 0, $newDimensions['newWidth'], $newDimensions['newHeight'],
			$previewWidth, $previewHeight
		);

		return $fixedPreview;
	}

	/**
	 * Calculates the new dimensions so that it fits in the dimensions requested by the client
	 *
	 * @link https://stackoverflow.com/questions/3050952/resize-an-image-and-fill-gaps-of-proportions-with-a-color
	 *
	 * @param int $previewWidth
	 * @param int $previewHeight
	 * @param int $maxX
	 * @param int $maxY
	 *
	 * @return array
	 */
	private function calculateNewDimensions($previewWidth, $previewHeight, $maxX, $maxY) {
		if (($previewWidth / $previewHeight) >= ($maxX / $maxY)) {
			$newWidth = $maxX;
			$newHeight = $previewHeight * ($maxX / $previewWidth);
			$newX = 0;
			$newY = round(abs($maxY - $newHeight) / 2);
		} else {
			$newWidth = $previewWidth * ($maxY / $previewHeight);
			$newHeight = $maxY;
			$newX = round(abs($maxX - $newWidth) / 2);
			$newY = 0;
		}

		return array(
			'newX'      => $newX,
			'newY'      => $newY,
			'newWidth'  => $newWidth,
			'newHeight' => $newHeight,
		);
	}

	/**
	 * Fixes the preview cache by replacing the broken thumbnail with ours
	 *
	 * @param string $owner
	 * @param File $file
	 * @param \OC\Preview $preview
	 * @param resource $fixedPreview
	 *
	 * @return mixed
	 */
	private function fixPreviewCache($owner, $file, $preview, $fixedPreview) {
		$fixedPreviewObject = new \OC_Image($fixedPreview); // FIXME: Private API
		$previewData = $preview->getPreview();

		// Get the location where the broken thumbnail is stored
		// FIXME: Private API
		$thumbPath =
			\OC::$SERVERROOT . '/data/' . $owner . '/' . $preview->isCached($file->getId());

		// Caching it for next time
		if ($fixedPreviewObject->save($thumbPath)) {
			$previewData = $fixedPreviewObject->data();
		}

		return $previewData;
	}

	/**
	 * Returns the media type icon when the server fails to generate a preview
	 *
	 * It's not more efficient for the browser to download the mime icon
	 * directly and won't be necessary once the Preview class sends the mime
	 * icon when it can't generate a proper preview
	 * https://github.com/owncloud/core/pull/12546
	 *
	 * @param File $file
	 *
	 * @return \OC_Image
	 */
	private function getMimeIcon($file) {
		$mime = $file->getMimeType();
		$iconData = new \OC_Image(); // FIXME: Private API

		// FIXME: private API
		$image = \OC::$SERVERROOT . mimetype_icon($mime);
		// OC8 version
		//$image = $this->serverRoot() . \OCP\Template::mimetype_icon($mime);

		$iconData->loadFromFile($image);

		return $iconData;
	}

	/**
	 * Returns base64 encoded data of a preview
	 *
	 * @param \OC_Image|string $previewData
	 *
	 * @return \OC_Image|string
	 */
	private function base64EncodeCheck($previewData) {
		$base64Encode = $this->base64Encode;

		if ($base64Encode === true) {
			if ($previewData instanceof \OC_Image) {
				$previewData = (string)$previewData;
			} else {
				$previewData = base64_encode($previewData);
			}
		}

		return $previewData;
	}

}