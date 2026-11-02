<?php

/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\AutoLogin;

use Matomo\Network\IPUtils;
use Piwik\Piwik;
use Piwik\Plugins\SitesManager\Model as SitesManagerModel;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Plugin\SystemConfigSetting;
use Piwik\Settings\Plugin\SystemSetting;
use Piwik\Settings\Setting;
use Piwik\Site;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    private const MIN_SECRET_LENGTH = 32;

    /**
     * The four settings that gate authentication live in config.ini.php instead of the database,
     * under this section. Two reasons: they can be provisioned by `php console config:set` during
     * a deployment, and a Super User who takes over the web UI cannot rewrite them to let
     * themselves back in.
     */
    public const CONFIG_SECTION = 'AutoLogin';

    public $enabled;
    public $sharedSecret;
    public $trustedProxies;
    public $allowSuperUserRole;
    public $disableAutoRegistration;
    public $groupManagement;
    private $siteModel;

    private const BREAK_LINE_HTML = '<br/> <br/>';


    /**
     * Initialize the plugin settings.
     *
     * @return void
     */
    protected function init()
    {
        $this->siteModel = new SitesManagerModel();
        $this->enabled = $this->createEnabled();
        $this->sharedSecret = $this->createSharedSecret();
        $this->trustedProxies = $this->createTrustedProxies();
        $this->allowSuperUserRole = $this->createAllowSuperUserRole();
        $this->disableAutoRegistration = $this->createDisableAutoRegistration();
        $this->groupManagement = $this->createGroupManagement();
    }

    /**
     * The shared secret, or '' when it does not meet the minimum requirements.
     *
     * FieldConfig::$validate only runs through Setting::setValue(), which `php console config:set`
     * does not use - it writes config.ini.php directly. The constraints are therefore enforced
     * here too, and returning '' makes Controller reject the request rather than trusting a weak
     * or unusable secret.
     */
    public function getSharedSecret(): string
    {
        $secret = trim((string) $this->sharedSecret->getValue());

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            return '';
        }
        // Matomo HTML-encodes characters like < & " in setting input, which would never match the
        // raw header, so the charset is restricted on both paths.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $secret)) {
            return '';
        }

        return $secret;
    }

    /**
     * @return string[] IPs and ranges (CIDR or wildcard) the proxy connects from, empty if not configured
     */
    public function getTrustedProxies(): array
    {
        return self::parseLines((string) $this->trustedProxies->getValue());
    }

    private static function parseLines(string $value): array
    {
        // Commas as well as newlines: a value set in config.ini.php cannot span lines.
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value))));
    }

    private function createEnabled(): SystemConfigSetting
    {
        return $this->makeSettingManagedInConfigOnly(self::CONFIG_SECTION, "enabled", false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = "Enable AutoLogin";
            $field->description = "Off by default. AutoLogin rejects every request until this is on and a shared "
                . "secret and trusted proxies are set.";
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
        });
    }

    private function createSharedSecret(): SystemConfigSetting
    {
        return $this->makeSettingManagedInConfigOnly(self::CONFIG_SECTION, "sharedSecret", "", FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = "Shared secret";
            $field->description = "The proxy must send this value in the '" . Controller::HEADER_SECRET_KEY
                . "' header. At least " . self::MIN_SECRET_LENGTH . " characters, for example the output of "
                . "'openssl rand -hex 32'.";
            $field->uiControl = FieldConfig::UI_CONTROL_PASSWORD;
            $field->validate = function ($value) {
                if ($value === '' || $value === null) {
                    return;
                }
                if (strlen((string) $value) < self::MIN_SECRET_LENGTH) {
                    throw new \Exception("The shared secret must be at least " . self::MIN_SECRET_LENGTH
                        . " characters long.");
                }
                // Matomo HTML-encodes characters like < & " in setting input, which would never match the raw header.
                if (!preg_match('/^[A-Za-z0-9_-]+$/', (string) $value)) {
                    throw new \Exception("The shared secret may only contain letters, digits, '-' and '_'.");
                }
            };
        });
    }

    private function createTrustedProxies(): SystemConfigSetting
    {
        return $this->makeSettingManagedInConfigOnly(self::CONFIG_SECTION, "trustedProxies", "", FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = "Trusted proxies";
            $field->description = "One IP address or range per line, for example 10.0.0.5 or 10.0.0.0/24. "
                . "Requests whose connecting address (REMOTE_ADDR) is not listed are rejected.";
            $field->uiControl = FieldConfig::UI_CONTROL_TEXTAREA;
            $field->validate = function ($value) {
                foreach (self::parseLines((string) $value) as $range) {
                    if (IPUtils::getIPRangeBounds($range) === null) {
                        throw new \Exception("Not a valid IP address or range: $range");
                    }
                }
            };
        });
    }

    private function createAllowSuperUserRole(): SystemConfigSetting
    {
        return $this->makeSettingManagedInConfigOnly(self::CONFIG_SECTION, "allowSuperUserRole", false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = "Allow superuser role";
            $field->description = "Off by default. When off, the 'superuser' role header is rejected and existing "
                . "superusers cannot sign in through AutoLogin.";
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
        });
    }

    /**
     * Add disable superuser setting.
     *
     * @return SystemSetting
     */
    private function createDisableAutoRegistration(): SystemSetting
    {
        return $this->makeSetting("disableAutoRegistration", false,
            FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
                $field->title = "Disable AutoRegistration";
                $field->description = "If the username defined in the request header does not exist in matomo,
                automatically register a new account with the given username and email";
                $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            });
    }

    private function createGroupManagement(): SystemSetting
    {
        return $this->makeSetting('groupManagement', array(), FieldConfig::TYPE_ARRAY, function (FieldConfig $field) {

            $allSites = $this->getAllSites();
            $field->uiControl = FieldConfig::UI_CONTROL_MULTI_TUPLE;
            $field->introduction = "Group Management";
            $field1 = new FieldConfig\MultiPair('Group', 'index', FieldConfig::UI_CONTROL_TEXT);
            $field2 = new FieldConfig\MultiPair('Sites', 'value', FieldConfig::UI_CONTROL_MULTI_SELECT);
            $allSitesKeys = array_keys($allSites);
            $allSiteIds = [];
            foreach ($allSitesKeys as $value) {
                $allSiteIds[$value] = (int)$value;
            }
            $field2->availableValues = $allSiteIds;

            $field->uiControlAttributes['field1'] = $field1->toArray();
            $field->uiControlAttributes['field2'] = $field2->toArray();

            $inlineText = "You can define groups and their site access. " . self::BREAK_LINE_HTML
                . " Available Sites:" . self::BREAK_LINE_HTML;
            foreach ($allSites as $value) {
                $inlineText .= implode(" ", array_slice($value, 0, 2)) . self::BREAK_LINE_HTML;
            }

            $inlineText .= "Based on the values in the request headers,
                which for this feature should contain at least 'role', 'group' and 'username',
                the corresponding user will get access to all sites defined for his group
                with the role defined in the header.";
            $field->inlineHelp = $inlineText;
        });
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


}
