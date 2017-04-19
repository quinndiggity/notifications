<?php
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Notifications;


use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Security\IdentityProof\Key;
use OC\Security\IdentityProof\Manager;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;

class Push {
	/** @var IDBConnection */
	protected $db;
	/** @var IManager */
	protected $manager;
	/** @var IConfig */
	protected $config;
	/** @var IProvider */
	protected $tokenProvider;
	/** @var Manager */
	private $keyManager;
	/** @var IUserManager */
	private $userManager;
	/** @var IClientService */
	protected $clientService;
	/** @var ILogger */
	protected $log;

	public function __construct(IDBConnection $connection, IManager $manager, IConfig $config, IProvider $tokenProvider, Manager $keyManager, IUserManager $userManager, IClientService $clientService, ILogger $log) {
		$this->db = $connection;
		$this->manager = $manager;
		$this->config = $config;
		$this->tokenProvider = $tokenProvider;
		$this->keyManager = $keyManager;
		$this->userManager = $userManager;
		$this->clientService = $clientService;
		$this->log = $log;
	}

	/**
	 * @param INotification $notification
	 */
	public function pushToDevice(INotification $notification) {
		$devices = $this->getDevicesForUser($notification->getUser());
		$user = $this->userManager->get($notification->getUser());

		if (empty($devices) || !($user instanceof IUser)) {
			return;
		}

		$language = $this->config->getUserValue($notification->getUser(), 'core', 'lang', 'en');
		try {
			$notification = $this->manager->prepare($notification, $language);
		} catch (\InvalidArgumentException $e) {
			return;
		}

		$userKey = $this->keyManager->getKey($user);

		$pushNotifications = [];
		foreach ($devices as $device) {
			try {
				if (!isset($pushNotifications[$device['proxyserver']])) {
					$pushNotifications[$device['proxyserver']] = [];
				}
				$pushNotifications[$device['proxyserver']][] = json_encode($this->encryptAndSign($userKey, $device, $notification));
			} catch (InvalidTokenException $e) {
				// Token does not exist anymore, should drop the push device entry
				$this->deletePushToken($device['token']);
			} catch (\InvalidArgumentException $e) {
				// Failed to encrypt message for device: public key is invalid
				$this->deletePushToken($device['token']);
			}
		}

		$client = $this->clientService->newClient();
		foreach ($pushNotifications as $proxyServer => $notifications) {
			try {
				$response = $client->post(rtrim($proxyServer, '/') . '/notifications', [
					'body' => [
						'notifications' => $notifications,
					],
				]);
			} catch (\Exception $e) {
				$this->log->logException($e, [
					'app' => 'notifications',
				]);
				return;
			}

			$status = $response->getStatusCode();
			if ($status !== Http::STATUS_OK && $status !== Http::STATUS_SERVICE_UNAVAILABLE) {
				$body = $response->getBody();
				$this->log->error('Could not send notification to push server [{url}]: {error}',[
					'error' => is_string($body) ? $body : 'no reason given',
					'url' => $proxyServer,
					'app' => 'notifications',
				]);
			} else if ($status === Http::STATUS_SERVICE_UNAVAILABLE && $this->config->getSystemValue('debug', false)) {
				$body = $response->getBody();
				$this->log->debug('Could not send notification to push server [{url}]: {error}',[
					'error' => is_string($body) ? $body : 'no reason given',
					'url' => $proxyServer,
					'app' => 'notifications',
				]);
			}
		}
	}

	/**
	 * @param int $tokenId
	 * @return bool
	 */
	protected function deletePushToken($tokenId) {
		$query = $this->db->getQueryBuilder();
		$query->delete('notifications_pushtokens')
			->where($query->expr()->eq('token', $query->createNamedParameter($tokenId, IQueryBuilder::PARAM_INT)));

		return $query->execute() !== 0;
	}

	/**
	 * @param Key $userKey
	 * @param array $device
	 * @param INotification $notification
	 * @return array
	 * @throws InvalidTokenException
	 * @throws \InvalidArgumentException
	 */
	protected function encryptAndSign(Key $userKey, array $device, INotification $notification) {
		// Check if the token is still valid...
		$this->tokenProvider->getTokenById($device['token']);

		$data = [
			'app' => $notification->getApp(),
			'subject' => $notification->getParsedSubject(),
		];

		if (!openssl_public_encrypt(json_encode($data), $encryptedSubject, $device['devicepublickey'], OPENSSL_PKCS1_PADDING)) {
			$this->log->error(openssl_error_string(), ['app' => 'notifications']);
			throw new \InvalidArgumentException('Failed to encrypt message for device');
		}

		openssl_sign($encryptedSubject, $signature, $userKey->getPrivate(), OPENSSL_ALGO_SHA512);
		$base64EncryptedSubject = base64_encode(hash('sha512', $encryptedSubject, true));
		$base64Signature = base64_encode($signature);

		return [
			'deviceIdentifier' => $device['deviceidentifier'],
			'pushTokenHash' => $device['pushtokenhash'],
			'subject' => $base64EncryptedSubject,
			'signature' => $base64Signature,
		];
	}

	/**
	 * @param string $uid
	 * @return array[]
	 */
	protected function getDevicesForUser($uid) {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('notifications_pushtokens')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)));

		$result = $query->execute();
		$devices = $result->fetchAll();
		$result->closeCursor();

		return $devices;
	}
}
