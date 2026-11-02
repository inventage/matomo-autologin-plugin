<?php

/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\AutoLogin;

use Matomo\Network\IP;
use Piwik\Container\StaticContainer;
use Piwik\Exception\Exception;
use Piwik\Log;
use Piwik\Plugins\SitesManager\Model as SitesManagerModel;
use Piwik\Plugins\UsersManager\Model as UsersManagerModel;
use Piwik\Session\SessionInitializer;
use Piwik\Url;

/**
 * Extends the generic plugin controller on purpose. Extending Login\Controller would expose its public actions
 * (login, logme, ...) under module=AutoLogin, and those would then authenticate through the password-less Auth.
 */
class Controller extends \Piwik\Plugin\Controller
{
    const HEADER_USERNAME_KEY = "matomo-username";
    const HEADER_GROUP_KEY = "matomo-group";
    const HEADER_ROLE_KEY = "matomo-role";
    const HEADER_EMAIL_KEY = "matomo-email";
    const HEADER_SECRET_KEY = "matomo-autologin-secret";

    const ROLE_SUPERUSER = "superuser";
    const ROLE_NOACCESS = "noaccess";

    /**
     * @var SystemSettings
     */
    private $settings;

    /**
     * @var UserProvisioner
     */
    private $users;

    public function __construct($settings = null, $users = null)
    {
        parent::__construct();

        $this->settings = $settings ?: StaticContainer::get(SystemSettings::class);
        $this->users = $users ?: new UserProvisioner(new UsersManagerModel());
    }

    public function index()
    {
        $this->assertRequestComesFromTrustedProxy();
        $this->autoLogin();
    }

    private function autoLogin(): void
    {
        $username = $this->getHeader(self::HEADER_USERNAME_KEY);
        if ($username === null) {
            throw new Exception("Autologin error. No username defined in the request header.");
        }

        $role = $this->getHeader(self::HEADER_ROLE_KEY);
        $role = $role === null ? null : strtolower($role);
        $allowSuperUser = (bool) $this->settings->allowSuperUserRole->getValue();

        if ($role === self::ROLE_SUPERUSER && !$allowSuperUser) {
            $this->reject("role 'superuser' requested but 'Allow superuser role' is disabled");
        }

        $user = $this->users->getUser($username);
        if (empty($user['login'])) {
            if ($this->settings->disableAutoRegistration->getValue()) {
                throw new Exception("Autologin error. AutoRegistration is disabled and the user does not exist.");
            }
            $email = $this->getHeader(self::HEADER_EMAIL_KEY);
            if ($email === null) {
                throw new Exception("Autologin error. No email address defined in the request header. "
                    . "Required for AutoRegistration.");
            }
            $this->users->createUser($username, $email);
        } elseif (!empty($user['superuser_access']) && !$allowSuperUser) {
            // Without this an attacker who gets past the proxy checks could still sign in as an existing superuser.
            $this->reject("login as existing superuser '$username' but 'Allow superuser role' is disabled");
        }

        $this->setPermissions($username, $this->getHeader(self::HEADER_GROUP_KEY), $role);
        $this->signInAs($username);
    }

    private function assertRequestComesFromTrustedProxy(): void
    {
        if (!$this->settings->enabled->getValue()) {
            $this->reject("plugin is not enabled in the system settings");
        }

        // getSharedSecret() returns '' when the configured value is too short or uses characters
        // that could not survive Matomo's input encoding, so a weak secret fails closed here.
        $secret = $this->settings->getSharedSecret();
        $trustedProxies = $this->settings->getTrustedProxies();
        if ($secret === '' || empty($trustedProxies)) {
            $this->reject("shared secret or trusted proxies are not configured");
        }

        // REMOTE_ADDR on purpose: X-Forwarded-For and similar headers are client controlled.
        $remoteAddress = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ($remoteAddress === '' || !IP::fromStringIP($remoteAddress)->isInRanges($trustedProxies)) {
            $this->reject("request from untrusted address '$remoteAddress'");
        }

        $this->assertNoUnderscoreHeaderVariants();

        $providedSecret = $this->getHeader(self::HEADER_SECRET_KEY);
        if ($providedSecret === null || !hash_equals($secret, $providedSecret)) {
            $this->reject("missing or wrong shared secret header");
        }
    }

    /**
     * Some servers map both "matomo-username" and "matomo_username" to HTTP_MATOMO_USERNAME. A proxy that only
     * overwrites the hyphenated header would then let a client-sent underscore variant through.
     * Only works where getallheaders() returns the raw request headers (mod_php). Under FPM it is rebuilt from
     * $_SERVER after the merge, so there the proxy has to strip both spellings (see README).
     */
    private function assertNoUnderscoreHeaderVariants(): void
    {
        if (!function_exists('getallheaders')) {
            return;
        }
        foreach (array_keys(getallheaders()) as $name) {
            $name = strtolower((string) $name);
            if (strpos($name, '_') !== false && strpos(str_replace('_', '-', $name), 'matomo-') === 0) {
                $this->reject("header '$name' uses underscores, only hyphenated matomo-* headers are accepted");
            }
        }
    }

    /**
     * @return never
     */
    private function reject(string $reason): void
    {
        Log::warning("AutoLogin rejected a request: " . $reason);
        throw new Exception("Autologin request rejected.");
    }

    private function setPermissions(string $username, ?string $groupName, ?string $role): void
    {
        if ($role === self::ROLE_SUPERUSER) {
            $this->users->grantSuperUserAccess($username);
            return;
        }

        // Any other explicit role means the proxy no longer considers this user a superuser.
        if ($role !== null) {
            $this->users->revokeSuperUserAccess($username);
        }

        if ($role === self::ROLE_NOACCESS) {
            $this->users->resetUserAccess($username);
            return;
        }

        if (empty($groupName)) {
            return;
        }

        if (!UserProvisioner::isValidAccessType($role)) {
            throw new Exception("Autologin error. Role must be one of view, write, admin when a group is set.");
        }

        $sites = $this->getSitesOfGroup($groupName);

        $this->users->resetUserAccess($username);
        $this->users->setUserAccess($username, $role, $sites);
    }

    private function getSitesOfGroup(string $groupName): array
    {
        foreach ($this->settings->groupManagement->getValue() as $group) {
            if (!empty($group['index']) && $group['index'] === $groupName) {
                $sites = (array) $group['value'];
                $existingSiteIds = array_column((new SitesManagerModel())->getAllSites(), 'idsite');
                if (count(array_intersect($existingSiteIds, $sites)) !== count($sites)) {
                    throw new Exception("Autologin error. Group '$groupName' references sites that do not exist. "
                        . "Contact your admin.");
                }
                return $sites;
            }
        }

        throw new Exception("Autologin error. Group '$groupName' does not exist. Contact your admin.");
    }

    private function signInAs(string $username): void
    {
        $auth = StaticContainer::get(Auth::class);
        $auth->setLogin($username);

        (new SessionInitializer())->initSession($auth);

        Url::redirectToUrl(Url::getCurrentUrlWithoutQueryString());
    }

    /**
     * Reads $_SERVER instead of getallheaders(): header name casing differs between SAPIs (mod_php keeps the
     * client's casing, FPM title-cases), $_SERVER normalises to HTTP_UPPER_SNAKE on all of them.
     */
    private function getHeader(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!isset($_SERVER[$key]) || !is_string($_SERVER[$key])) {
            return null;
        }
        $value = trim($_SERVER[$key]);
        return $value === '' ? null : $value;
    }
}
