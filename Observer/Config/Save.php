<?php

namespace Yotpo\Core\Observer\Config;

use Magento\Framework\App\Cache\Type\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Yotpo\Core\Model\Api\Token as YotpoApi;
use Yotpo\Core\Model\Sync\Reset as SyncReset;
use Yotpo\Reviews\Model\Config as YotpoConfig;

/**
 * Class Save
 * Class for app-key validation
 */
class Save extends Main implements ObserverInterface
{
    /**
     * @var ReinitableConfigInterface
     */
    private $appConfig;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @var YotpoConfig
     */
    private $yotpoConfig;

    /**
     * @var YotpoApi
     */
    private $yotpoApi;

    /**
     * @var CatalogMapping
     */
    protected $catalogMapping;

    /**
     * @var CronFrequency
     */
    protected $cronFrequency;

    /**
     * @var SyncReset
     */
    protected $syncReset;

    /**
     * Save constructor.
     * @param TypeListInterface $cacheTypeList
     * @param ReinitableConfigInterface $config
     * @param YotpoConfig $yotpoConfig
     * @param YotpoApi $yotpoApi
     * @param CatalogMapping $catalogMapping
     * @param CronFrequency $cronFrequency
     * @param SyncReset $syncReset
     */
    public function __construct(
        TypeListInterface $cacheTypeList,
        ReinitableConfigInterface $config,
        YotpoConfig $yotpoConfig,
        YotpoApi $yotpoApi,
        CatalogMapping $catalogMapping,
        CronFrequency $cronFrequency,
        SyncReset $syncReset
    ) {
        $this->cacheTypeList = $cacheTypeList;
        $this->appConfig = $config;
        $this->yotpoConfig = $yotpoConfig;
        $this->yotpoApi = $yotpoApi;
        $this->catalogMapping = $catalogMapping;
        $this->cronFrequency = $cronFrequency;
        $this->syncReset = $syncReset;
    }

    /**
     * @param Observer $observer
     * @throws AlreadyExistsException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(Observer $observer)
    {
        $this->doYotpoApiKeyValidation($observer);
        $this->catalogMapping->doYotpoCatalogMappingChanges($observer);
        $this->cronFrequency->doCronFrequencyChanges($observer);
    }

    /**
     * @param Observer $observer
     * @return bool|void
     * @throws AlreadyExistsException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function doYotpoApiKeyValidation(Observer $observer)
    {
        $changedPaths = (array)$observer->getEvent()->getChangedPaths();
        if ($changedPaths) {
            $scopeDetails = $this->getScopes($observer);
            $scopeId = $scopeDetails['scope_id'];
            if ($this->isYotpoSettingsChanged($changedPaths)) {
                $this->cacheTypeList->cleanType(Config::TYPE_IDENTIFIER);
                $this->appConfig->reinit();
                $scope = $scopeDetails['scope'];
                $scopes = $scopeDetails['scopes'];
                $appKey = $this->yotpoConfig->getAppKey($scopeId, $scope);

                if ($scope !== ScopeInterface::SCOPE_STORE && !$this->yotpoConfig->isSingleStoreMode()) {
                    $this->resetStoreCredentials($scopeId, $scopes);
                    return true;
                }

                //Check if appKey is unique:
                if ($scopeId && $appKey) {
                    foreach ((array) $this->yotpoConfig->getAllStoreIds() as $storeId) {
                        if (($storeId != $scopeId) && $this->yotpoConfig->getAppKey($storeId) === $appKey) {
                            $this->resetStoreCredentials($scopeId, $scopes);
                            throw new AlreadyExistsException(__(
                                "The APP KEY you've entered is already in use by another store on this system.
                                Note that Yotpo requires a unique set of APP KEY & SECRET for each store."
                            ));
                        }
                    }
                }

                if ($this->yotpoConfig->isEnabled($scopeId, $scope) &&
                    !($this->yotpoApi->createAuthToken($scopeId, $scope))) {
                    $this->resetStoreCredentials($scopeId, $scopes);
                    throw new AlreadyExistsException(__(
                        "Please make sure the APP KEY and SECRET you've entered are correct"
                    ));
                }
            }
            if ($scopeId && $this->isYotpoAppKeyChanged($changedPaths)) {
                $this->syncReset->resetSync($scopeId);
            }
        }
    }

    /**
     * Reset store credentials
     *
     * @param int|null $scopeId
     * @param string $scope
     * @return void
     * @throws NoSuchEntityException
     */
    private function resetStoreCredentials($scopeId = null, $scope = ScopeInterface::SCOPE_STORES)
    {
        $this->yotpoConfig->resetStoreCredentials($scopeId, $scope);
        $this->cacheTypeList->cleanType(Config::TYPE_IDENTIFIER);
        $this->appConfig->reinit();
    }

    /**
     * @param array <string> $changedPaths
     * @return bool
     */
    public function isYotpoSettingsChanged($changedPaths = [])
    {
        $yotpoKeyPaths = ['app_key', 'secret', 'yotpo_active'];
        $commonPaths = $this->getChangedYotpoPaths($changedPaths, $yotpoKeyPaths);
        return (bool)$commonPaths;
    }

    /**
     * @param array <string> $changedPaths
     * @return bool
     */
    public function isYotpoAppKeyChanged($changedPaths = [])
    {
        $yotpoKeyPaths = ['app_key'];
        $commonPaths = $this->getChangedYotpoPaths($changedPaths, $yotpoKeyPaths);
        return (bool)$commonPaths;
    }

    /**
     * @param array <string> $changedPaths
     * @param array <string> $keyPathsToCompare
     * @return array <mixed>
     */
    public function getChangedYotpoPaths($changedPaths, $keyPathsToCompare)
    {
        $pathsToCheck = [];
        foreach ($keyPathsToCompare as $path) {
            $pathsToCheck[] = $this->yotpoConfig->getConfigPath($path);
        }
        return array_intersect($pathsToCheck, $changedPaths);
    }
}
