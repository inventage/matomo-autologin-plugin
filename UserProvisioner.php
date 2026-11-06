<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\AutoLogin;

use Piwik\Access;
use Piwik\Exception\Exception;
use Piwik\Plugins\UsersManager\API as UsersManagerAPI;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Tracker\Cache;

/**
 * Writes users and access rows for AutoLogin.
 *
 * Deliberately not named API and not extending a Plugin\API class: Matomo exposes every public method of
 * Piwik\Plugins\<Plugin>\API over the HTTP API, and these methods do no permission checks of their own.
 * Only call them after Controller has verified the request came from the trusted proxy.
 */
class UserProvisioner
{
    private const ACCESS_TYPES = ['view', 'write', 'admin'];

    /**
     * @var Model
     */
    private $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public static function isValidAccessType(?string $access): bool
    {
        return in_array($access, self::ACCESS_TYPES, true);
    }

    public function getUser(string $login): array
    {
        return $this->model->getUser($login);
    }

    public function createUser(string $login, string $email): void
    {
        // Random password: the account is meant to be used through the proxy only.
        $password = bin2hex(random_bytes(16));
        try {
            Access::doAsSuperUser(function () use ($login, $password, $email) {
                UsersManagerAPI::getInstance()->addUser($login, $password, $email);
            });
        } catch (\Exception $e) {
            throw new Exception("Autologin user signup failed: " . $e->getMessage());
        }
    }

    public function grantSuperUserAccess(string $login): void
    {
        $this->model->deleteUserAccess($login);
        $this->model->setSuperUserAccess($login, true);
        Cache::deleteTrackerCache();
    }

    public function revokeSuperUserAccess(string $login): void
    {
        $user = $this->model->getUser($login);
        if (empty($user['superuser_access'])) {
            return;
        }
        // Same guard as core UsersManager: never remove the last superuser, the instance would be unmanageable.
        if (count($this->model->getUsersHavingSuperUserAccess()) <= 1) {
            throw new Exception("Autologin error. Cannot remove superuser access from the last superuser.");
        }
        $this->model->setSuperUserAccess($login, false);
        Cache::deleteTrackerCache();
    }

    public function resetUserAccess(string $login): void
    {
        $this->model->deleteUserAccess($login);
    }

    public function setUserAccess(string $login, string $access, array $idSites): void
    {
        if (!self::isValidAccessType($access)) {
            throw new Exception("Autologin does not support the access type: $access");
        }
        $this->model->addUserAccess($login, $access, $idSites);
        Access::getInstance()->reloadAccess();
        Cache::deleteTrackerCache();
    }
}
