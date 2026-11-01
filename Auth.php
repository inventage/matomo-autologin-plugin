<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\AutoLogin;

use Piwik\Auth\Password;
use Piwik\AuthResult;
use Piwik\Plugins\UsersManager\Model;

class Auth extends \Piwik\Plugins\Login\Auth
{
    protected $login;
    protected $token_auth;
    protected $hashedPassword;

    /**
     * @var Model
     */
    private $userModel;

    /**
     * @var Password
     */
    private $passwordHelper;

    public function __construct()
    {
        $this->userModel = new Model();
        $this->passwordHelper = new Password();
    }

    /**
     * Does not check a password. Only Controller::index() may use this, after it has verified the request came
     * from the trusted proxy.
     */
    public function authenticate()
    {
        $user = $this->userModel->getUser((string) $this->login);
        if (empty($user['login'])) {
            return new AuthResult(AuthResult::FAILURE, $this->login, null);
        }
        return $this->authenticationSuccess($user);
    }

    private function authenticationSuccess(array $user)
    {
        if (empty($this->token_auth)) {
            $this->token_auth = $this->userModel->generateRandomTokenAuth();
            // we generated one randomly which will then be stored in the session and used across the session
        }

        $isSuperUser = (int) $user['superuser_access'];
        $code = $isSuperUser ? AuthResult::SUCCESS_SUPERUSER_AUTH_CODE : AuthResult::SUCCESS;

        return new AuthResult($code, $user['login'], $this->token_auth);
    }

}
