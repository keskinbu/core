<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Michael U <mdusher@users.noreply.github.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Chan <plus.vincchan@gmail.com>
 * @author Volkan Gezer <volkangezer@gmail.com>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\User;

use OC\Cache\CappedMemoryCache;
use OC\Hooks\PublicEmitter;
use OCP\IUser;
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\IConfig;

/**
 * Class Manager
 *
 * Hooks available in scope \OC\User:
 * - preSetPassword(\OC\User\User $user, string $password, string $recoverPassword)
 * - postSetPassword(\OC\User\User $user, string $password, string $recoverPassword)
 * - preDelete(\OC\User\User $user)
 * - postDelete(\OC\User\User $user)
 * - preCreateUser(string $uid, string $password)
 * - postCreateUser(\OC\User\User $user, string $password)
 * - change(\OC\User\User $user)
 *
 * @package OC\User
 */
class Manager extends PublicEmitter implements IUserManager {
	/**
	 * @var \OCP\UserInterface[] $backends
	 */
	private $backends = [];

	/**
	 * @var CappedMemoryCache $cachedUsers
	 */
	private $cachedUsers;

	/**
	 * @var \OCP\IConfig $config
	 */
	private $config;

	/**
	 * @param \OCP\IConfig $config
	 */
	public function __construct(IConfig $config = null) {
		$this->config = $config;
		$this->cachedUsers = new CappedMemoryCache();
		$this->listen('\OC\User', 'postDelete', function ($user) {
			/** @var IUser $user */
			$this->clearUserCache($user->getUID());
		});
	}

	/**
	 * Get the active backends
	 * @return \OCP\UserInterface[]
	 */
	public function getBackends() {
		return $this->backends;
	}

	/**
	 * register a user backend
	 *
	 * @param \OCP\UserInterface $backend
	 */
	public function registerBackend($backend) {
		$this->clearUserCache();
		$this->backends[] = $backend;
	}

	/**
	 * remove a user backend
	 *
	 * @param \OCP\UserInterface $backend
	 */
	public function removeBackend($backend) {
		$this->clearUserCache();
		if (($i = array_search($backend, $this->backends)) !== false) {
			unset($this->backends[$i]);
		}
	}

	/**
	 * remove all user backends
	 */
	public function clearBackends() {
		$this->clearUserCache();
		$this->backends = [];
	}

	/**
	 * get a user by user id
	 *
	 * @param string $uid
	 * @return IUser|null Either the user or null if the specified user does not exist
	 */
	public function get($uid) {
		if ($this->isCached($uid)) { //check the cache first to prevent having to loop over the backends
			return $this->getCached($uid);
		}
		foreach ($this->backends as $backend) {
			if ($backend->userExists($uid)) {
				return $this->getUserObject($uid, $backend);
			}
		}
		$this->cacheUserNotExisting($uid);
		return null;
	}

	/**
	 * get or construct the user object
	 *
	 * @param string $uid
	 * @param \OCP\UserInterface $backend
	 * @param bool $cacheUser If false the newly created user object will not be cached
	 * @return IUser
	 */
	protected function getUserObject($uid, $backend, $cacheUser = true) {
		if ($this->isCached($uid)) {
			// TODO check backend matches?
			return $this->getCached($uid);
		}

		if (method_exists($backend, 'loginName2UserName')) {
			$loginName = $backend->loginName2UserName($uid);
			if ($loginName !== false) {
				$uid = $loginName;
			}
			if ($this->isCached($uid)) {
				return $this->getCached($uid);
			}
		}

		$user = new User($uid, $backend, $this, $this->config);
		if ($cacheUser) {
			$this->cacheUser($uid, $user);
		}
		return $user;
	}

	/**
	 * check if a user exists
	 *
	 * @param string $uid
	 * @return bool
	 */
	public function userExists($uid) {
		$user = $this->get($uid);
		return ($user !== null);
	}

	/**
	 * Check if the password is valid for the user
	 *
	 * @param string $loginName
	 * @param string $password
	 * @return mixed the User object on success, false otherwise
	 */
	public function checkPassword($loginName, $password) {
		$loginName = str_replace("\0", '', $loginName);
		$password = str_replace("\0", '', $password);
		
		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(Backend::CHECK_PASSWORD)) {
				$uid = $backend->checkPassword($loginName, $password);
				if ($uid !== false) {
					return $this->getUserObject($uid, $backend);
				}
			}
		}

		\OC::$server->getLogger()->warning('Login failed: \''. $loginName .'\' (Remote IP: \''. \OC::$server->getRequest()->getRemoteAddress(). '\')', ['app' => 'core']);
		return false;
	}

	/**
	 * search by user id
	 *
	 * @param string $pattern
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function search($pattern, $limit = null, $offset = null) {
		$users = [];
		foreach ($this->backends as $backend) {
			$backendUsers = $backend->getUsers($pattern, $limit, $offset);
			if (is_array($backendUsers)) {
				foreach ($backendUsers as $uid) {
					$users[$uid] = $this->getUserObject($uid, $backend);
				}
			}
		}

		uasort($users, function ($a, $b) {
			/**
			 * @var \OC\User\User $a
			 * @var \OC\User\User $b
			 */
			return strcmp($a->getUID(), $b->getUID());
		});
		return $users;
	}

	/**
	 * search by displayName
	 *
	 * @param string $pattern
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function searchDisplayName($pattern, $limit = null, $offset = null) {
		$users = [];
		foreach ($this->backends as $backend) {
			$backendUsers = $backend->getDisplayNames($pattern, $limit, $offset);
			if (is_array($backendUsers)) {
				foreach ($backendUsers as $uid => $displayName) {
					$users[] = $this->getUserObject($uid, $backend);
				}
			}
		}

		usort($users, function ($a, $b) {
			/**
			 * @var \OC\User\User $a
			 * @var \OC\User\User $b
			 */
			return strcmp($a->getDisplayName(), $b->getDisplayName());
		});
		return $users;
	}

	/**
	 * @param string $uid
	 * @param string $password
	 * @throws \Exception
	 * @return bool|IUser the created user or false
	 */
	public function createUser($uid, $password) {
		$l = \OC::$server->getL10N('lib');
		// Check the name for bad characters
		// Allowed are: "a-z", "A-Z", "0-9" and "_.@-'"
		if (preg_match('/[^a-zA-Z0-9 _\.@\-\']/', $uid)) {
			throw new \Exception($l->t('Only the following characters are allowed in a username:'
				. ' "a-z", "A-Z", "0-9", and "_.@-\'"'));
		}
		// No empty username
		if (trim($uid) == '') {
			throw new \Exception($l->t('A valid username must be provided'));
		}
		// No whitespace at the beginning or at the end
		if (strlen(trim($uid, "\t\n\r\0\x0B\xe2\x80\x8b")) !== strlen(trim($uid))) {
			throw new \Exception($l->t('Username contains whitespace at the beginning or at the end'));
		}
		// No empty password
		if (trim($password) == '') {
			throw new \Exception($l->t('A valid password must be provided'));
		}

		// Check if user already exists
		if ($this->userExists($uid)) {
			throw new \Exception($l->t('The username is already being used'));
		}

		// clear cache for new user object
		$this->clearUserCache($uid);

		$this->emit('\OC\User', 'preCreateUser', [$uid, $password]);
		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(Backend::CREATE_USER)) {
				$backend->createUser($uid, $password);
				$user = $this->getUserObject($uid, $backend);
				$this->emit('\OC\User', 'postCreateUser', [$user, $password]);
				return $user;
			}
		}
		return false;
	}

	/**
	 * returns how many users per backend exist (if supported by backend)
	 *
	 * @param boolean $hasLoggedIn when true only users that have a lastLogin
	 *                entry in the preferences table will be affected
	 * @return array|int an array of backend class as key and count number as value
	 *                if $hasLoggedIn is true only an int is returned
	 */
	public function countUsers($hasLoggedIn = false) {
		if ($hasLoggedIn) {
			return $this->countSeenUsers();
		}
		$userCountStatistics = [];
		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(Backend::COUNT_USERS)) {
				$backendUsers = $backend->countUsers();
				if($backendUsers !== false) {
					if($backend instanceof IUserBackend) {
						$name = $backend->getBackendName();
					} else {
						$name = get_class($backend);
					}
					if(isset($userCountStatistics[$name])) {
						$userCountStatistics[$name] += $backendUsers;
					} else {
						$userCountStatistics[$name] = $backendUsers;
					}
				}
			}
		}
		return $userCountStatistics;
	}

	/**
	 * The callback is executed for each user on each backend.
	 * If the callback returns false no further users will be retrieved.
	 *
	 * @param \Closure $callback
	 * @param string $search
	 * @param boolean $onlySeen when true only users that have a lastLogin entry
	 *                in the preferences table will be affected
	 * @since 9.0.0
	 */
	public function callForAllUsers(\Closure $callback, $search = '', $onlySeen = false) {
		if ($onlySeen) {
			$this->callForSeenUsers($callback);
		} else {
			foreach ($this->getBackends() as $backend) {
				$limit = 500;
				$offset = 0;
				do {
					$users = $backend->getUsers($search, $limit, $offset);
					foreach ($users as $uid) {
						if (!$backend->userExists($uid)) {
							continue;
						}
						$user = $this->getUserObject($uid, $backend, false);
						$return = $callback($user);
						if ($return === false) {
							break;
						}
					}
					$offset += $limit;
				} while (count($users) >= $limit);
			}
		}
	}

	/**
	 * returns how many users have logged in once
	 *
	 * @return int
	 * @since 9.2.0
	 */
	public function countSeenUsers() {
		$queryBuilder = \OC::$server->getDatabaseConnection()->getQueryBuilder();
		$queryBuilder->select($queryBuilder->createFunction('COUNT(*)'))
			->from('preferences')
			->where($queryBuilder->expr()->eq(
				'appid', $queryBuilder->createNamedParameter('login'))
			)
			->andWhere($queryBuilder->expr()->eq(
				'configkey', $queryBuilder->createNamedParameter('lastLogin'))
			)
			->andWhere($queryBuilder->expr()->isNotNull('configvalue')
			);

		$query = $queryBuilder->execute();
		return (int)$query->fetchColumn();
	}

	/**
	 * @param \Closure $callback
	 * @param string $search
	 * @since 9.2.0
	 */
	public function callForSeenUsers (\Closure $callback) {
		$limit = 1000;
		$offset = 0;
		do {
			$userIds = $this->getSeenUserIds($limit, $offset);
			$offset += $limit;
			foreach ($userIds as $userId) {
				foreach ($this->backends as $backend) {
					if ($backend->userExists($userId)) {
						$user = $this->getUserObject($userId, $backend, false);
						$return = $callback($user);
						if ($return === false) {
							return;
						}
					}
				}
			}
		} while (count($userIds) >= $limit);
	}

	/**
	 * Getting all userIds that have a listLogin value requires checking the
	 * value in php because on oracle you cannot use a clob in a where clause,
	 * preventing us from doing a not null or length(value) > 0 check.
	 * 
	 * @param int $limit
	 * @param int $offset
	 * @return string[] with user ids
	 */
	private function getSeenUserIds($limit = null, $offset = null) {
		$queryBuilder = \OC::$server->getDatabaseConnection()->getQueryBuilder();
		$queryBuilder->select(['userid'])
			->from('preferences')
			->where($queryBuilder->expr()->eq(
				'appid', $queryBuilder->createNamedParameter('login'))
			)
			->andWhere($queryBuilder->expr()->eq(
				'configkey', $queryBuilder->createNamedParameter('lastLogin'))
			)
			->andWhere($queryBuilder->expr()->isNotNull('configvalue')
			);

		if ($limit !== null) {
			$queryBuilder->setMaxResults($limit);
		}
		if ($offset !== null) {
			$queryBuilder->setFirstResult($offset);
		}
		$query = $queryBuilder->execute();
		$result = [];

		while ($row = $query->fetch()) {
			$result[] = $row['userid'];
		}

		return $result;
	}
	/**
	 * @param string $email
	 * @return IUser[]
	 * @since 9.1.0
	 */
	public function getByEmail($email) {
		$userIds = $this->config->getUsersForUserValue('settings', 'email', $email);

		return array_map(function($uid) {
			return $this->get($uid);
		}, $userIds);
	}

	/**
	 * Check if a user object has been cached or is false
	 * @param $uid
	 * @return bool
	 */
	private function isCached($uid) {
		return isset($this->cachedUsers[$uid]);
	}

	/**
	 * get cached user object, converts false to null
	 * @param $uid
	 * @return IUser|null
	 */
	private function getCached($uid) {
		$user = $this->cachedUsers[$uid];
		if ($user === false) { // user was cached as not existing
			return null;
		}
		return $user;
	}

	/**
	 * cache a user object
	 * @param $uid
	 * @param IUser $user
	 */
	private function cacheUser($uid, IUser $user) {
		$this->cachedUsers[$uid] = $user;
	}

	/**
	 * mark a uid as not existing by caching it as false
	 * @param $uid
	 */
	private function cacheUserNotExisting($uid) {
		$this->cachedUsers[$uid] = false;
	}

	/**
	 * clear the user cache or only remove a single user from cache
	 * @param string $uid optional
	 */
	private function clearUserCache($uid = null) {
		if ($uid) {
			$this->cachedUsers->remove($uid);
		} else {
			$this->cachedUsers->clear();
		}
	}
}
