<?php

namespace Yotpo\Core\Model\Api;

use Yotpo\Core\Model\Config;

class ErrorHandler
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var CoreSync
     */
    protected $coreSync;

    /**
     * Request constructor.
     * @param Config $config
     * @param Sync $coreSync
     */
    public function __construct(
        Config $config,
        Sync $coreSync
    ) {
        $this->config = $config;
        $this->coreSync = $coreSync;
    }

    public function handleConflictResponse($entityId, $entityTypeInYotpoApi, $entityDataToSync, $parentTypeInYotpoApi = null, $parentYotpoId = null)
    {
        $getEndpointUrl = $this->config->buildYotpoEntityRequestUrl($entityTypeInYotpoApi, $this->config::METHOD_GET, null, $parentTypeInYotpoApi, $parentYotpoId);
        $entityYotpoId = $this->getYotpoIdFromMagentoEntityId($entityId, $getEndpointUrl, $entityTypeInYotpoApi);
        if (!$entityYotpoId) {
            return false;
        }

        $patchEndpointUrl = $this->config->buildYotpoEntityRequestUrl($entityTypeInYotpoApi, $this->config::METHOD_PATCH, $entityYotpoId, $parentTypeInYotpoApi, $parentYotpoId);
        return $this->updateExistingEntityInYotpo($entityYotpoId, $patchEndpointUrl, $entityDataToSync);
    }

    public function handleNotFoundResponse($entityId, $entityTypeInYotpoApi, $entityDataToSync, $parentTypeInYotpoApi = null, $parentYotpoId = null)
    {
        $getEndpointUrl = $this->config->buildYotpoEntityRequestUrl($entityTypeInYotpoApi, $this->config::METHOD_GET, null, $parentTypeInYotpoApi, $parentYotpoId);
        $entityYotpoId = $this->getYotpoIdFromMagentoEntityId($entityId, $getEndpointUrl, $entityTypeInYotpoApi);
        if ($entityYotpoId === false) {
            return false;
        }

        if ($entityYotpoId == 0) {
            $postEndpointUrl = $this->config->buildYotpoEntityRequestUrl($entityTypeInYotpoApi, $this->config::METHOD_POST, $entityYotpoId, $parentTypeInYotpoApi, $parentYotpoId);
            return $this->createEntityInYotpo($entityYotpoId, $postEndpointUrl, $entityDataToSync);
        }

        $patchEndpointUrl = $this->config->buildYotpoEntityRequestUrl($entityTypeInYotpoApi, $this->config::METHOD_PATCH, $entityYotpoId, $parentTypeInYotpoApi, $parentYotpoId);
        return $this->updateExistingEntityInYotpo($entityYotpoId, $patchEndpointUrl, $entityDataToSync);
    }

    private function getYotpoIdFromMagentoEntityId($entityId, $getEndpointUrl, $entityTypeKeyInYotpoApi) {
        $getRequestPayload = ['external_ids' => $entityId, 'entityLog' => $entityTypeKeyInYotpoApi];
        $getResponseData = $this->coreSync->sync($this->config::METHOD_GET, $getEndpointUrl, $getRequestPayload);
        if (!$getResponseData->getData('is_success')) {
            return false;
        }

        $responseData = $getResponseData->getResponse();
        if (!$responseData || !is_array($responseData)) {
            if (!array_key_exists($entityTypeKeyInYotpoApi, $responseData) || count($responseData[$entityTypeKeyInYotpoApi]) == 0) {
                return 0;
            }
        }

        $existingEntitiesInYotpo = $responseData[$entityTypeKeyInYotpoApi];
        $existingEntityInYotpo = $existingEntitiesInYotpo[0];
        return $existingEntityInYotpo['yotpo_id'];
    }

    private function createEntityInYotpo($entityYotpoId, $postEndpointUrl, $entityDataToSync) {
        $postResponseData = $this->coreSync->sync($this->config::METHOD_POST, $postEndpointUrl, $entityDataToSync);
        if (!$postResponseData->getData('is_success')) {
            return false;
        }

        return $entityYotpoId;
    }

    private function updateExistingEntityInYotpo($entityYotpoId, $patchEndpointUrl, $entityDataToSync) {
        $patchResponseData = $this->coreSync->sync($this->config::METHOD_PATCH, $patchEndpointUrl, $entityDataToSync);
        if (!$patchResponseData->getData('is_success')) {
            return false;
        }

        return $entityYotpoId;
    }
}
