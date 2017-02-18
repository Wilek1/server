<?php
/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Julius Haertl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author oparoz <owncloud@interfasys.ch>
 *
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

namespace OCA\Theming\Controller;

use OC\Template\SCSSCacher;
use OCA\Theming\ThemingDefaults;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCA\Theming\Util;
use OCP\ITempManager;

/**
 * Class ThemingController
 *
 * handle ajax requests to update the theme
 *
 * @package OCA\Theming\Controller
 */
class ThemingController extends Controller {
	/** @var ThemingDefaults */
	private $template;
	/** @var Util */
	private $util;
	/** @var ITimeFactory */
	private $timeFactory;
	/** @var IL10N */
	private $l;
	/** @var IConfig */
	private $config;
	/** @var ITempManager */
	private $tempManager;
	/** @var IAppData */
	private $appData;

	/**
	 * ThemingController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param ThemingDefaults $template
	 * @param Util $util
	 * @param ITimeFactory $timeFactory
	 * @param IL10N $l
	 * @param ITempManager $tempManager
	 * @param IAppData $appData
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IConfig $config,
		ThemingDefaults $template,
		Util $util,
		ITimeFactory $timeFactory,
		IL10N $l,
		ITempManager $tempManager,
		IAppData $appData
	) {
		parent::__construct($appName, $request);

		$this->template = $template;
		$this->util = $util;
		$this->timeFactory = $timeFactory;
		$this->l = $l;
		$this->config = $config;
		$this->tempManager = $tempManager;
		$this->appData = $appData;
	}

	/**
	 * @param string $setting
	 * @param string $value
	 * @return DataResponse
	 * @internal param string $color
	 */
	public function updateStylesheet($setting, $value) {
		$value = trim($value);
		switch ($setting) {
			case 'name':
				if (strlen($value) > 250) {
					return new DataResponse([
						'data' => [
							'message' => $this->l->t('The given name is too long'),
						],
						'status' => 'error'
					]);
				}
				break;
			case 'url':
				if (strlen($value) > 500) {
					return new DataResponse([
						'data' => [
							'message' => $this->l->t('The given web address is too long'),
						],
						'status' => 'error'
					]);
				}
				break;
			case 'slogan':
				if (strlen($value) > 500) {
					return new DataResponse([
						'data' => [
							'message' => $this->l->t('The given slogan is too long'),
						],
						'status' => 'error'
					]);
				}
				break;
			case 'color':
				if (!preg_match('/^\#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
					return new DataResponse([
						'data' => [
							'message' => $this->l->t('The given color is invalid'),
						],
						'status' => 'error'
					]);
				}
				break;
		}

		$this->template->set($setting, $value);
		return new DataResponse(
			[
				'data' =>
					[
						'message' => $this->l->t('Saved')
					],
				'status' => 'success'
			]
		);
	}

	/**
	 * Update the logos and background image
	 *
	 * @return DataResponse
	 */
	public function updateLogo() {
		$newLogo = $this->request->getUploadedFile('uploadlogo');
		$newBackgroundLogo = $this->request->getUploadedFile('upload-login-background');
		if (empty($newLogo) && empty($newBackgroundLogo)) {
			return new DataResponse(
				[
					'data' => [
						'message' => $this->l->t('No file uploaded')
					]
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$name = '';
		try {
			$folder = $this->appData->getFolder('images');
		} catch (NotFoundException $e) {
			$folder = $this->appData->newFolder('images');
		}

		if(!empty($newLogo)) {
			$target = $folder->newFile('logo');
			$target->putContent(file_get_contents($newLogo['tmp_name'], 'r'));
			$this->template->set('logoMime', $newLogo['type']);
			$name = $newLogo['name'];
		}
		if(!empty($newBackgroundLogo)) {
			$target = $folder->newFile('background');
			$image = @imagecreatefromstring(file_get_contents($newBackgroundLogo['tmp_name'], 'r'));
			if($image === false) {
				return new DataResponse(
					[
						'data' => [
							'message' => $this->l->t('Unsupported image type'),
						],
						'status' => 'failure',
					],
					Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}

			// Optimize the image since some people may upload images that will be
			// either to big or are not progressive rendering.
			$tmpFile = $this->tempManager->getTemporaryFile();
			if(function_exists('imagescale')) {
				// FIXME: Once PHP 5.5.0 is a requirement the above check can be removed
				// Workaround for https://bugs.php.net/bug.php?id=65171
				$newHeight = imagesy($image)/(imagesx($image)/1920);
				$image = imagescale($image, 1920, $newHeight);
			}
			imageinterlace($image, 1);
			imagejpeg($image, $tmpFile, 75);
			imagedestroy($image);

			$target->putContent(file_get_contents($tmpFile, 'r'));
			$this->template->set('backgroundMime', $newBackgroundLogo['type']);
			$name = $newBackgroundLogo['name'];
		}

		return new DataResponse(
			[
				'data' =>
					[
						'name' => $name,
						'message' => $this->l->t('Saved')
					],
				'status' => 'success'
			]
		);
	}

	/**
	 * Revert setting to default value
	 *
	 * @param string $setting setting which should be reverted
	 * @return DataResponse
	 */
	public function undo($setting) {
		$value = $this->template->undo($setting);
		return new DataResponse(
			[
				'data' =>
					[
						'value' => $value,
						'message' => $this->l->t('Saved')
					],
				'status' => 'success'
			]
		);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return FileDisplayResponse|NotFoundResponse
	 */
	public function getLogo() {
		try {
			/** @var File $file */
			$file = $this->appData->getFolder('images')->getFile('logo');
		} catch (NotFoundException $e) {
			return new NotFoundResponse();
		}

		$response = new FileDisplayResponse($file);
		$response->cacheFor(3600);
		$expires = new \DateTime();
		$expires->setTimestamp($this->timeFactory->getTime());
		$expires->add(new \DateInterval('PT24H'));
		$response->addHeader('Expires', $expires->format(\DateTime::RFC2822));
		$response->addHeader('Pragma', 'cache');
		$response->addHeader('Content-Type', $this->config->getAppValue($this->appName, 'logoMime', ''));
		return $response;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return FileDisplayResponse|NotFoundResponse
	 */
	public function getLoginBackground() {
		try {
			/** @var File $file */
			$file = $this->appData->getFolder('images')->getFile('background');
		} catch (NotFoundException $e) {
			return new NotFoundResponse();
		}

		$response = new FileDisplayResponse($file);
		$response->cacheFor(3600);
		$expires = new \DateTime();
		$expires->setTimestamp($this->timeFactory->getTime());
		$expires->add(new \DateInterval('PT24H'));
		$response->addHeader('Expires', $expires->format(\DateTime::RFC2822));
		$response->addHeader('Pragma', 'cache');
		$response->addHeader('Content-Type', $this->config->getAppValue($this->appName, 'backgroundMime', ''));
		return $response;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return FileDisplayResponse|NotFoundResponse
	 * @throws NotFoundException
	 */
	public function getStylesheet() {
		$appDataCss = \OC::$server->getAppDataDir('css');
		$cacheBusterValue = $this->config->getAppValue('theming', 'cachebuster', '0');

		/* SCSSCacher is required here
		 * We cannot rely on automatic caching done by \OC_Util::addStyle,
		 * since we need to add the cacheBuster value to the url
		 */
		$SCSSCacher = new SCSSCacher(
			\OC::$server->getLogger(),
			$appDataCss,
			\OC::$server->getURLGenerator(),
			\OC::$server->getConfig(),
			\OC::$server->getThemingDefaults(),
			\OC::$SERVERROOT
		);
		$appPath = substr(\OC::$server->getAppManager()->getAppPath('theming'), strlen(\OC::$SERVERROOT) + 1);
		$SCSSCacher->process(
			\OC::$SERVERROOT,
			$appPath . '/css/theming.scss',
			$cacheBusterValue
		);

		try {
			$folder = $appDataCss->getFolder($cacheBusterValue);
			$cssFile = $folder->getFile('theming.css');
			$response = new FileDisplayResponse($cssFile, Http::STATUS_OK, ['Content-Type' => 'text/css']);
			$response->cacheFor(86400);
			$expires = new \DateTime();
			$expires->setTimestamp($this->timeFactory->getTime());
			$expires->add(new \DateInterval('PT24H'));
			$response->addHeader('Expires', $expires->format(\DateTime::RFC1123));
			$response->addHeader('Pragma', 'cache');
			return $response;
		} catch (NotFoundException $e) {
			return new NotFoundResponse();
		}
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return DataDownloadResponse
	 */
	public function getJavascript() {
		$cacheBusterValue = $this->config->getAppValue('theming', 'cachebuster', '0');
		$responseJS = '(function() {
	OCA.Theming = {
		name: ' . json_encode($this->template->getName()) . ',
		url: ' . json_encode($this->template->getBaseUrl()) . ',
		slogan: ' . json_encode($this->template->getSlogan()) . ',
		color: ' . json_encode($this->template->getMailHeaderColor()) . ',
		inverted: ' . json_encode($this->util->invertTextColor($this->template->getMailHeaderColor())) . ',
		cacheBuster: ' . json_encode($cacheBusterValue). '
	};
})();';
		$response = new DataDownloadResponse($responseJS, 'javascript', 'text/javascript');
		$response->addHeader('Expires', date(\DateTime::RFC2822, $this->timeFactory->getTime()));
		$response->addHeader('Pragma', 'cache');
		$response->cacheFor(3600);
		return $response;
	}
}
