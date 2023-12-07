<?php
/**
 * @copyright Copyright (c) 2023 Anupam Kumar <kyteinsky@gmail.com>
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Stt\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Stt\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotifyManager;
use OCP\PreConditionNotMetException;
use OCP\SpeechToText\ISpeechToTextManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SttService {

	public function __construct(
		private ISpeechToTextManager $manager,
		private IRootFolder $rootFolder,
		private INotifyManager $notificationManager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private IConfig $config,
	) {
	}

	/**
	 * Sends a notification to the user for successful or failed transcription
	 *
	 * @param int $id
	 * @param ?string $userId
	 * @param boolean $success
	 * @param string $message The transcript or error message
	 * @return void
	 */
	public function sendNotification(int $id, ?string $userId, bool $success, string $message) {
		if (is_null($userId)) {
			$this->logger->warning('userId is null, skipping sending a notification.');
			return;
		}

		$notification = $this->notificationManager->createNotification();

		$subject = $success ? 'success' : 'failure';
		$params = [
			'id' => $id,
			'message' => $message,
		];
		$url = $this->urlGenerator->linkToRouteAbsolute(
			Application::APP_ID . '.stt.getResultPage', ['id' => $id]
		);

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('transcript', strval($id))
			->setSubject($subject, $params)
			->setLink($url)
		;

		$this->notificationManager->notify($notification);
	}

	/**
	 * @param string $path
	 * @param string|null $userId
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function transcribeFile(string $path, ?string $userId): void {
		// this also prevents NoUserException
		if (is_null($userId)) {
			throw new InvalidArgumentException('userId must not be null');
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$audioFile = $userFolder->get($path);

		$this->manager->scheduleFileTranscription($audioFile, $userId, Application::APP_ID);
	}

	/**
	 * @param string $tempFileLocation
	 * @param string|null $userId
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function transcribeAudio(string $tempFileLocation, ?string $userId): void {
		if ($userId === null) {
			throw new InvalidArgumentException('userId must not be null');
		}

		$audioFile = $this->getFileObject($userId, $tempFileLocation);
		$this->manager->scheduleFileTranscription($audioFile, $userId, Application::APP_ID);
	}

	/**
	 * @param string $userId
	 * @param string $tempFileLocation
	 * @return File
	 * @throws NotPermittedException
	 * @throws RuntimeException
	 */
	private function getFileObject(string $userId, string $tempFileLocation): File {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$sttFolderName = $this->config->getAppValue(Application::APP_ID, 'stt_folder', '(not set)');
		if ($sttFolderName === '(not set)') {
			$sttFolderName = Application::REC_FOLDER;

			if ($userFolder->nodeExists($sttFolderName)) {
				$sttFolder = $this->getUniqueNamedFolder($userId);
				$sttFolderName = $sttFolder->getName();
			} else {
				$sttFolder = $userFolder->newFolder($sttFolderName);
			}
			$this->config->setAppValue(Application::APP_ID, 'stt_folder', $sttFolderName);
		} else {
			$sttFolder = $userFolder->get($sttFolderName);
			if (!$sttFolder instanceof Folder) {
				// the folder created by this app was tampered with
				// create a new one
				$sttFolder = $this->getUniqueNamedFolder($userId);
				$sttFolderName = $sttFolder->getName();
				$this->config->setAppValue(Application::APP_ID, 'stt_folder', $sttFolderName);
			}
		}

		$filename = (new DateTime())->format('d-M-Y-Hisu') . '.mp3';
		$audioFile = $sttFolder->newFile($filename, fopen($tempFileLocation, 'rb'));

		return $audioFile;
	}

	/**
	 * @param string $userId
	 * @param integer $try
	 * @return Folder
	 * @throws RuntimeException
	 * @throws NotPermittedException
	 */
	private function getUniqueNamedFolder(string $userId, int $try = 3): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$sttFolderPath = Application::REC_FOLDER . ' ' . strval(4 - $try);

		if ($userFolder->nodeExists($sttFolderPath)) {
			if ($try === 0) {
				// give up
				throw new RuntimeException('Could not create a folder with a unique name');
			}
			return $this->getUniqueNamedFolder($userId, $try - 1);
		}

		return $userFolder->newFolder($sttFolderPath);
	}
}
