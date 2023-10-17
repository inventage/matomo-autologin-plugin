<?php

/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\AutoLogin;

use Piwik\Piwik;
use Piwik\Plugins\SitesManager\Model as SitesManagerModel;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Plugin\SystemSetting;
use Piwik\Settings\Setting;
use Piwik\Site;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
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
        $this->disableAutoRegistration = $this->createDisableAutoRegistration();
        $this->groupManagement = $this->createGroupManagement();
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
