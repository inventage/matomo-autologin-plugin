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
use Piwik\Auth\Password;
use Piwik\Exception\Exception;
use Piwik\Piwik;
use Piwik\Plugins\Login\PasswordVerifier;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Plugins\UsersManager\UserAccessFilter;
use Piwik\Tracker\Cache;


class API extends \Piwik\Plugins\UsersManager\API
{
    /**
     * @var Model
     */
    private $model;

    private $accessTypes = ['view', 'write', 'admin'];


    public function __construct(Model $model, UserAccessFilter $filter, Password $password,
                                Access $access = null, RolesProvider $roleProvider = null,
                                CapabilitiesProvider $capabilityProvider = null,
                                PasswordVerifier $passwordVerifier = null)
    {
        parent::__construct($model, $filter, $password, $access, $roleProvider, $capabilityProvider, $passwordVerifier);
        $this->model = $model;
    }

    public function setSuperUserAccess($userLogin, $hasSuperUserAccess, $passwordConfirmation = null)
    {
        $this->model->deleteUserAccess($userLogin);
        $this->model->setSuperUserAccess($userLogin, $hasSuperUserAccess);
        Cache::deleteTrackerCache();
    }

    public function resetUserAccess($userLogin)
    {
        $this->model->deleteUserAccess($userLogin);
    }

    public function setUserAccess($userLogin, $access, $idSites, $passwordConfirmation = null)
    {
        if (!$this->validAccessType($access)){
            throw new Exception("Autologin does not support the access type: $access");
        }
        $this->model->addUserAccess($userLogin, $access, $idSites);
        Access::getInstance()->reloadAccess();
        Cache::deleteTrackerCache();
    }

    private function validAccessType($access): bool{
        return in_array($access, $this->accessTypes);
    }

}
