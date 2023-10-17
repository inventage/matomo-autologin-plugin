<?php

/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\AutoLogin;

use Piwik\Access;
use Piwik\Container\StaticContainer;
use Piwik\Exception\Exception;
use Piwik\Site;
use Piwik\Plugins\SitesManager\Model as SitesManagerModel;
use Piwik\Plugins\UsersManager\Model as UsersManagerModel;
use function DI\string;


class Controller extends \Piwik\Plugins\Login\Controller
{
    const PLUGIN_AUTH_PATH = "Piwik\Plugins\AutoLogin\Auth";
    const PLUGIN_SYSTEM_SETTINGS_PATH = "\Piwik\Plugins\AutoLogin\SystemSettings";
    const PLUGIN_API_PATH = "Piwik\Plugins\AutoLogin\API";
    const HEADER_USERNAME_KEY = "matomo-username";
    const HEADER_GROUP_KEY = "matomo-group";
    const HEADER_ROLE_KEY = "matomo-role";
    const HEADER_EMAIL_KEY = "matomo-email";


    private $userModel;

    private $siteModel;
    private $api;

    public function __construct(
        $passwordResetter = null,
        $auth = null,
        $sessionInitializer = null,
        $passwordVerify = null,
        $bruteForceDetection = null,
        $systemSettings = null
    )
    {
        if (empty($auth)) {
            $auth = StaticContainer::get(self::PLUGIN_AUTH_PATH);
        }
        if (empty($systemSettings)) {
            $systemSettings = StaticContainer::get(self::PLUGIN_SYSTEM_SETTINGS_PATH);
        }
        $this->auth = $auth;
        $this->userModel = new UsersManagerModel();
        $this->siteModel = new SitesManagerModel();
        $this->api = StaticContainer::get(self::PLUGIN_API_PATH);

        parent::__construct($passwordResetter, $auth, $sessionInitializer, $passwordVerify
            , $bruteForceDetection, $systemSettings);
    }

    public function index()
    {
        $this->autoLogin();
    }

    private function autoLogin(): void
    {
        $username = $this->getUsernameFromRequestHeader();

        if ($username == null) {
            throw new Exception("Autologin error. No username defined in the request header.");
        }

        $user = $this->userModel->getUser($username);
        if (empty($user['login'])) {
            $disabledAutoRegistration = $this->systemSettings->disableAutoRegistration->getValue();
            if ($disabledAutoRegistration) {
                throw new Exception("Autologin error. AutoRegistration is disabled. User '"
                    . $username . " does not exist in matomo: ");
            }
            $email = $this->getEmailFromRequestHeader();
            if ($email == null) {
                throw new Exception("Autologin error. No email address defined in the request header.
                        Required for AutoRegistration");
            }
            $this->signupUser($username, $this->generateRandomPassword(), $email);
        }

        $groupName = $this->getGroupFromRequestHeader();
        $role = $this->getRoleFromRequestHeader();

        $this->setPermissions($username, $groupName, $role);
        $this->autoLoginAs($username);

    }

    private function setPermissions($username, $groupName, $role): void
    {
        if ($role != null){
            $role = strtolower($role);
        }

        if ($role == "superuser") {
            $this->setSuperUserAccess($username);
            return;
        }


        if ($role == "noaccess") {
            $this->api->resetUserAccess($username);
            return;
        }


        if (empty($groupName)) {
            return;
        }


        $availableGroups = $this->systemSettings->groupManagement->getValue();
        $groupFound = false;
        $sites = [];

        foreach ($availableGroups as $curGroup) {
            $curGroupName = $curGroup['index'];
            if (empty($curGroupName)) {
                continue;
            }

            if ($curGroupName == $groupName) {
                $groupFound = true;
                $sites = $curGroup['value'];
                break;
            }
        }

        if (!$groupFound) {
            throw new Exception("Autologin error. Groupname `$groupName` does not exist. Contact your admin");
        }

        $allSitesId = array_keys($this->getAllSites());
        $sitesIntersection = array_intersect($allSitesId, $sites);

        if (count($sitesIntersection) != count($sites)) {
            $allSitesIdString = implode(", ", $allSitesId);
            $sitesString = implode(", ", $sites);

            throw new Exception("Autologin error. Site ids from group `$groupName`
                do not exist. Contact your admin. All site ids: $allSitesIdString
                . And requested site: $sitesString. Number of sites: "
                . count($sites) . " 
                Counter sitesIntersection: " . count($sitesIntersection) . " and sites : " . count($sites));
            return;
        }
        $this->api->resetUserAccess($username);
        $this->api->setUserAccess($username, $role, $sites);
    }


    private function autoLoginAs($username): void
    {
        $this->authenticateAndRedirect($username, "");
    }

    private function signupUser(string $login, string $password, string $email)
    {
        try {
            Access::doAsSuperUser(function () use ($login, $password, $email) {
                $this->api->addUser($login, $password, $email);
            });
        } catch (\Exception $e) {
            throw new Exception("Autologin user signup failed. Reason: $e");
        }
    }

    private function setSuperUserAccess(string $login)
    {
        try {
            Access::doAsSuperUser(function () use ($login) {
                $this->api->setSuperUserAccess($login, true);
            });
        } catch (\Exception $e) {
            throw new Exception("Autologin set superuser access failed. Reason: $e");
        }
    }

    private function getUsernameFromRequestHeader(): ?string
    {
        $username = null;

        foreach (getallheaders() as $name => $value) {
            if ($name == self::HEADER_USERNAME_KEY) {
                $username = $value;
            }
        }
        return $username;
    }

    private function getEmailFromRequestHeader(): ?string
    {
        $username = null;

        foreach (getallheaders() as $name => $value) {
            if ($name == self::HEADER_EMAIL_KEY) {
                $username = $value;
            }
        }
        return $username;
    }

    private function getGroupFromRequestHeader(): ?string
    {
        $groupName = null;

        foreach (getallheaders() as $name => $value) {
            if ($name == self::HEADER_GROUP_KEY) {
                $groupName = $value;
            }
        }
        return $groupName;
    }

    private function getRoleFromRequestHeader(): ?string
    {
        $role = null;
        foreach (getallheaders() as $name => $value) {
            if ($name == self::HEADER_ROLE_KEY) {
                $role = $value;
            }
        }
        return $role;
    }

    private function getAllSites(): array
    {
        $sites = $this->siteModel->getAllSites();
        $allSites = [];
        foreach ($sites as $site) {
            $allSites[$site['idsite']] = $site;
        }
        return Site::setSitesFromArray($allSites);
    }

    function generateRandomPassword($length = 12): string
    {
        $bytes = random_bytes($length);
        return bin2hex($bytes);
    }
}
