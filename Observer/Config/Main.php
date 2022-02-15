<?php

namespace Yotpo\Core\Observer\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\ScopeInterface;

class Main
{
    /**
     * retrieve scope details
     * @param Observer $observer
     * @return array <mixed>
     */
    public function getScopes(Observer $observer): array
    {
        $return = [
            'scope' => '',
            'scopes' => '',
            'scope_id' => ''
        ];
        $scope = $scopes = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        if (($scopeId = $observer->getEvent()->getStore())) {
            $scope = ScopeInterface::SCOPE_STORE;
            $scopes = ScopeInterface::SCOPE_STORES;
        } elseif (($scopeId = $observer->getEvent()->getWebsite())) {
            $scope = ScopeInterface::SCOPE_WEBSITE;
            $scopes = ScopeInterface::SCOPE_WEBSITES;
        } else {
            $scopeId = 0;
        }
        $return['scope'] = $scope;
        $return['scopes'] = $scopes;
        $return['scope_id'] = (int) $scopeId;
        return $return;
    }
}
