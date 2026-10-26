<?php

namespace App\Http\Controllers\Api\Concerns;

/** Old Telegram messages open the corresponding new screen without applying stale edits. */
trait LegacyBotAdminNavigation
{
    protected function adminPanels($type = [])
    {
        return $this->adminCatalog(['e' => 'p', 'a' => 'list', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminPanelDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'p', 'a' => 'show', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCreatePanel($type = [])
    {
        return $this->adminCatalog(['e' => 'p', 'a' => 'new', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminEditPanel($type = [])
    {
        return $this->adminCatalog(['e' => 'p', 'a' => 'edit', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminUpdatePanel($type = [])
    {
        return $this->adminCatalogSave();
    }

    protected function adminPanelDeleteDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'p', 'a' => 'del', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminPanelDeleteSubmit($type = [])
    {
        return $this->adminCatalog(['e' => 'p', 'a' => 'del', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCountries($type = [])
    {
        return $this->adminCatalog(['e' => 'c', 'a' => 'list', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCountriesDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'c', 'a' => 'show', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCountriesCreate($type = [])
    {
        return $this->adminCatalog(['e' => 'c', 'a' => 'new', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCountriesEdit($type = [])
    {
        return $this->adminCatalog(['e' => 'c', 'a' => 'edit', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCountriesUpdate($type = [])
    {
        return $this->adminCatalogSave();
    }

    protected function adminPlans($type = [])
    {
        return $this->adminCatalog(['e' => 'l', 'a' => 'list', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminPlanDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'l', 'a' => 'show', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminPlanCreate($type = [])
    {
        return $this->adminCatalog(['e' => 'l', 'a' => 'new', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminEditPlan($type = [])
    {
        return $this->adminCatalog(['e' => 'l', 'a' => 'edit', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminUpdatePlan($type = [])
    {
        return $this->adminCatalogSave();
    }

    protected function adminPlanDeleteDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'l', 'a' => 'del', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminPlanDeleteSubmit($type = [])
    {
        return $this->adminCatalog(['e' => 'l', 'a' => 'del', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminExtraBandwidths($type = [])
    {
        return $this->adminCatalog(['e' => 'x', 'a' => 'list', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminExtraBandwidthsDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'x', 'a' => 'show', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminExtraBandwidthsCreate($type = [])
    {
        return $this->adminCatalog(['e' => 'x', 'a' => 'new', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminExtraBandwidthsEdit($type = [])
    {
        return $this->adminCatalog(['e' => 'x', 'a' => 'edit', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminExtraBandwidthsUpdate($type = [])
    {
        return $this->adminCatalogSave();
    }

    protected function adminExtraBandwidthsDelete($type = [])
    {
        return $this->adminCatalog(['e' => 'x', 'a' => 'del', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminExtraBandwidthsDeleteSubmit($type = [])
    {
        return $this->adminCatalog(['e' => 'x', 'a' => 'del', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCartList($type = [])
    {
        return $this->adminCatalog(['e' => 'b', 'a' => 'list', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCartDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'b', 'a' => 'show', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCartCreate($type = [])
    {
        return $this->adminCatalog(['e' => 'b', 'a' => 'new', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCartEdit($type = [])
    {
        return $this->adminCatalog(['e' => 'b', 'a' => 'edit', 'id' => $type['id'] ?? 0, 'k' => $type['key'] ?? '', 'page' => $type['page'] ?? 1]);
    }

    protected function adminCartUpdate($type = [])
    {
        return $this->adminCatalogSave();
    }

    protected function adminService($type = [])
    {
        return $this->adminCatalog(['e' => 'v']);
    }

    protected function adminServiceDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'v']);
    }

    protected function adminServiceCreate($type = [])
    {
        return $this->adminCatalog(['e' => 'v']);
    }

    protected function adminServiceEdit($type = [])
    {
        return $this->adminCatalog(['e' => 'v']);
    }

    protected function adminServiceUpdate($type = [])
    {
        return $this->adminCatalog(['e' => 'v']);
    }

    protected function adminServiceDeleteDetail($type = [])
    {
        return $this->adminCatalog(['e' => 'v']);
    }

    protected function adminServiceDeleteSubmit($type = [])
    {
        return $this->adminCatalog(['e' => 'v']);
    }

    protected function adminSetting($type = [])
    {
        return $this->adminSettings(['g' => '']);
    }

    protected function adminSettingSell($type = [])
    {
        return $this->adminSettings(['g' => 'sales']);
    }

    protected function adminPaymentSetting($type = [])
    {
        return $this->adminSettings(['g' => 'payment']);
    }

    protected function adminCartSetting($type = [])
    {
        return $this->adminSettings(['g' => 'payment']);
    }

    protected function adminBotSetting($type = [])
    {
        return $this->adminSettings(['g' => 'channels']);
    }

    protected function adminSettingCommission($type = [])
    {
        return $this->adminSettings(['k' => 'commission']);
    }

    protected function adminSettingCommissionText($type = [])
    {
        return $this->adminSettings(['k' => 'commission_text']);
    }

    protected function adminCartBeCartText($type = [])
    {
        return $this->adminSettings(['k' => 'cart_be_cart_text']);
    }

    protected function adminChargeAmount($type = [])
    {
        return $this->adminSettings(['k' => 'charge_amount']);
    }

    protected function adminChargeAmountAdd($type = [])
    {
        return $this->adminSettings(['k' => 'charge_amount']);
    }

    protected function adminSettingCommissionEdit($type = [])
    {
        return $this->adminSettingsSave();
    }

    protected function adminSettingCommissionTextEdit($type = [])
    {
        return $this->adminSettingsSave();
    }

    protected function adminCartBeCartTextUpdate($type = [])
    {
        return $this->adminSettingsSave();
    }

    protected function adminChangeSettingSubmit($type = [])
    {
        return $this->adminSettingsSave();
    }

    protected function adminChargeAmountSubmit($type = [])
    {
        return $this->adminSettingsSave();
    }

    protected function adminChangeSetting($type = [])
    {
        return $this->adminSettings(['k' => $type['k'] ?? '']);
    }

    protected function adminCartBeCartStatus($type = [])
    {
        return $this->adminSettings(['k' => 'cart_be_cart']);
    }

    protected function adminCartBeCartRandom($type = [])
    {
        return $this->adminSettings(['k' => 'cart_be_cart_random']);
    }

    protected function adminSettingChangeValue($type = [])
    {
        return $this->adminSettings(['k' => $type['key'] ?? '']);
    }

    protected function adminToggleSetting($type = [])
    {
        return $this->adminSettings(['k' => $type['key'] ?? '']);
    }

    protected function adminChargeAmountDelete($type = [])
    {
        return $this->adminSettings(['k' => 'charge_amount']);
    }

    protected function adminInbounds($type = [])
    {
        return $this->adminNetwork([]);
    }

    protected function adminInboundList($type = [])
    {
        return $this->adminNetwork(['p' => $type['id'] ?? 0, 'page' => $type['page'] ?? 1]);
    }

    protected function adminPasarGuardGroups($type = [])
    {
        return $this->adminNetwork(['p' => $type['id'] ?? 0, 'page' => $type['page'] ?? 1]);
    }

    protected function adminPasarGuardGroupDetail($type = [])
    {
        return $this->adminNetwork(['a' => 'show', 'id' => $type['id'] ?? 0]);
    }

    protected function adminToggleInboundStatus($type = [])
    {
        return $this->adminNetwork(['a' => 'show', 'id' => $type['id'] ?? 0]);
    }

    protected function adminPasarGuardGroupsEdit($type = [])
    {
        return $this->adminNetwork(['a' => 'show', 'id' => $type['id'] ?? 0]);
    }

    protected function adminPasarGuardGroupsUpdate($type = [])
    {
        return $this->adminNetwork([]);
    }

    protected function adminConnectPanel($type = [])
    {
        return $this->adminNetwork(['a' => 'check', 'p' => $type['id'] ?? 0]);
    }

    protected function adminGetInbounds($type = [])
    {
        return $this->adminNetwork(['a' => 'sync', 'p' => $type['id'] ?? 0]);
    }

    protected function adminPasarGuardSellSetting($type = [])
    {
        return $this->adminNetwork(['a' => 'all', 'p' => $type['id'] ?? 0]);
    }

    protected function adminPGSellChangeStatus($type = [])
    {
        return $this->adminNetwork(['a' => 'all', 'p' => $type['id'] ?? 0]);
    }

    protected function adminPGSellChangePercent($type = [])
    {
        return $this->adminNetwork(['a' => 'percent', 'p' => $type['id'] ?? 0]);
    }

    protected function adminPGSellChangePercentSubmit($type = [])
    {
        return $this->adminAllCountryPercent();
    }
}
