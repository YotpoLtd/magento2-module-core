<?php

namespace Yotpo\Core\Model\Sync\Catalog\Processor;

use InvalidArgumentException;
use UnexpectedValueException;
use Yotpo\Core\Model\Api\Sync as CoreSync;
use Yotpo\Core\Model\Config as CoreConfig;
use Yotpo\Core\Model\Sync\Catalog\Data as CatalogData;
use Yotpo\Core\Model\Sync\Catalog\Logger as YotpoCoreCatalogLogger;

/**
 * Manage catalog requests
 */
class CatalogRequestHandler
{

    /**
     * @var CoreConfig
     */
    protected $coreConfig;

    /**
     * @var CoreSync
     */
    protected $coreSync;

    /**
     * @var CatalogData
     */
    protected $catalogData;

    /**
     * @var YotpoCoreCatalogLogger
     */
    protected $yotpoCatalogLogger;

    /**
     * CatalogRequestHandler constructor.
     * @param CoreConfig $coreConfig
     * @param CoreSync $coreSync,
     * @param CatalogData $catalogData
     */
    public function __construct(
        CoreConfig $coreConfig,
        CoreSync $coreSync,
        CatalogData $catalogData,
        YotpoCoreCatalogLogger $yotpoCatalogLogger
    ) {
        $this->coreConfig = $coreConfig;
        $this->coreSync = $coreSync;
        $this->catalogData = $catalogData;
        $this->yotpoCatalogLogger = $yotpoCatalogLogger;
    }

    /**
     * @param integer $itemEntityId
     * @param array<mixed> $yotpoItemData
     * @param int $yotpoProductId
     * @return array<mixed>
     */
    public function handleProductUpsert($itemEntityId, $yotpoItemData, $yotpoProductId)
    {
        $responseObject = $this->upsertProduct($yotpoProductId, $yotpoItemData);

        $response = $responseObject['response'];
        $responseStatusCode = $response->getData('status');
        if ($responseStatusCode == CoreConfig::BAD_REQUEST_RESPONSE_CODE && !$yotpoProductId) {
            $minimalProductRequest = $this->catalogData->getMinimalProductRequestData($yotpoItemData);
            $responseObject = $this->upsertProduct($yotpoProductId, $minimalProductRequest);
        } elseif (in_array($responseStatusCode, [CoreConfig::NOT_FOUND_RESPONSE_CODE, CoreConfig::CONFLICT_RESPONSE_CODE])) {
            try {
                $yotpoProductId = $this->getYotpoItemIdFromItemEntityId($itemEntityId, 'products');
            } catch (UnexpectedValueException $e) {
                $this->yotpoCatalogLogger->info(
                    __(
                        'Failed getting Yotpo product ID from entity ID,
                        returning initial upsert response - Product Entity ID: %1',
                        $itemEntityId
                    )
                );
                return $responseObject;
            }

            $responseObject = $this->upsertProduct($yotpoProductId, $yotpoItemData);
        }

        return $responseObject;
    }

    /**
     * @param int $itemEntityId
     * @param array<mixed> $yotpoItemData
     * @param int $yotpoParentProductId
     * @param int $yotpoVariantId
     * @return array<mixed>
     */
    public function handleVariantUpsert($itemEntityId, $yotpoItemData, $yotpoParentProductId, $yotpoVariantId)
    {
        $responseObject = $this->upsertVariant($yotpoItemData, $yotpoParentProductId, $yotpoVariantId);

        $response = $responseObject['response'];
        $responseStatusCode = $response->getData('status');
        if (($responseStatusCode == CoreConfig::NOT_FOUND_RESPONSE_CODE && $yotpoVariantId)
            || $responseStatusCode == CoreConfig::CONFLICT_RESPONSE_CODE) {
            try {
                $yotpoVariantId = $this->getYotpoItemIdFromItemEntityId(
                    $yotpoParentProductId,
                    'variants',
                    $itemEntityId
                );
            } catch (UnexpectedValueException $e) {
                $this->yotpoCatalogLogger->info(
                    __(
                        'Failed getting Yotpo variant ID from entity ID,
                        returning initial upsert response - Variant Entity ID: %1',
                        $itemEntityId
                    )
                );
                return $responseObject;
            }

            $responseObject = $this->upsertVariant($yotpoItemData, $yotpoParentProductId, $yotpoVariantId);
        }

        return $responseObject;
    }

    /**
     * Get yotpo_id from response
     * @param mixed $response
     * @param string|int $method
     * @return int
     */
    public function getYotpoIdFromResponse($response, $method)
    {
        $array = [
            $this->coreConfig->getProductSyncMethod('createProduct') => 'product',
            $this->coreConfig->getProductSyncMethod('createProductVariant') => 'variant'
        ];
        $key = $array[$method] ?? null;
        $yotpoId = 0;
        if (!$key) {
            return $yotpoId;
        }
        if ($response && $response->getData('response')) {
            $responseData = $response->getData('response');
            if ($responseData && is_array($responseData)) {
                $yotpoId = isset($responseData[$key]) && is_array($responseData[$key])
                && isset($responseData[$key]['yotpo_id']) ? $responseData[$key]['yotpo_id']  : 0;
            }
        }
        return $yotpoId;
    }

    /**
     * @param string $method
     * @param integer $yotpoItemId
     * @param array|mixed $response
     * @return array<mixed>
     */
    public function prepareRequestResponseObject($method, $yotpoItemId, $response)
    {
        return [
            'method' => $method,
            'yotpo_id' => $yotpoItemId,
            'response' => $response
        ];
    }

    /**
     * @param integer $yotpoProductId
     * @param array<mixed> $yotpoItemData
     * @return array<mixed>
     */
    private function upsertProduct($yotpoProductId, $yotpoItemData)
    {
        $requestPayload = ['product' => $yotpoItemData, 'entityLog' => 'catalog'];

        if ($yotpoProductId) {
            $method = 'updateProduct';
            $response = $this->updateProduct($yotpoProductId, $requestPayload);
        } else {
            $method = 'createProduct';
            $response = $this->createProduct($requestPayload);
            $yotpoProductId = $this->getYotpoIdFromResponse($response, 'createProduct');
        }

        return $this->prepareRequestResponseObject($method, $yotpoProductId, $response);
    }

    /**
     * @param array<mixed> $yotpoItemData
     * @param integer $yotpoParentProductId
     * @param integer $yotpoVariantId
     * @return array<mixed>
     */
    private function upsertVariant($yotpoItemData, $yotpoParentProductId, $yotpoVariantId)
    {
        $requestPayload = ['variant' => $yotpoItemData, 'entityLog' => 'catalog'];

        if ($yotpoVariantId) {
            $method = 'updateProductVariant';
            $response = $this->updateVariant($yotpoVariantId, $yotpoParentProductId, $requestPayload);
        } else {
            $method = 'createProductVariant';
            $response = $this->createVariant($yotpoParentProductId, $requestPayload);
            $yotpoVariantId = $this->getYotpoIdFromResponse($response, 'createProduct');
        }

        return $this->prepareRequestResponseObject($method, $yotpoVariantId, $response);
    }

    /**
     * @param array<mixed> $requestPayload
     * @return mixed
     */
    private function createProduct($requestPayload)
    {
        $productPostEndpoint = $this->coreConfig->getEndpoint('products');
        return $this->coreSync->sync(CoreConfig::METHOD_POST, $productPostEndpoint, $requestPayload);
    }

    /**
     * @param integer $yotpoProductId
     * @param array<mixed> $requestPayload
     * @return mixed
     */
    private function updateProduct($yotpoProductId, $requestPayload)
    {
        $productPostEndpoint = $this->coreConfig->getEndpoint(
            'updateProduct',
            ['{yotpo_product_id}'],
            [$yotpoProductId]
        );
        return $this->coreSync->sync(CoreConfig::METHOD_PATCH, $productPostEndpoint, $requestPayload);
    }

    /**
     * @param integer $yotpoParentProductId
     * @param array<mixed> $requestPayload
     * @return mixed
     */
    private function createVariant($yotpoParentProductId, $requestPayload)
    {
        $variantPostEndpoint = $this->coreConfig->getEndpoint(
            'variant',
            ['{yotpo_product_id}'],
            [$yotpoParentProductId]
        );
        return $this->coreSync->sync(CoreConfig::METHOD_POST, $variantPostEndpoint, $requestPayload);
    }

    /**
     * @param integer $yotpoVariantId
     * @param integer $yotpoParentProductId
     * @param array<mixed> $requestPayload
     * @return mixed
     */
    private function updateVariant($yotpoVariantId, $yotpoParentProductId, $requestPayload)
    {
        $variantPostEndpoint = $this->coreConfig->getEndpoint(
            'updateVariant',
            ['{yotpo_product_id}',
            '{yotpo_variant_id}'],
            [$yotpoParentProductId, $yotpoVariantId]
        );
        return $this->coreSync->sync(CoreConfig::METHOD_PATCH, $variantPostEndpoint, $requestPayload);
    }

    /**
     * @param integer $itemEntityId
     * @param string $entityType
     * @param integer $yotpoParentEntityId
     * @return int
     */
    private function getYotpoItemIdFromItemEntityId($itemEntityId, $entityType, $yotpoParentEntityId = null)
    {
        if ($entityType == 'products') {
            $existingItems = $this->getYotpoProductFromItemEntityId($itemEntityId);
        } elseif ($entityType == 'variants') {
            if (!$yotpoParentEntityId) {
                throw new InvalidArgumentException("yotpoParentEntityId must be provided for Yotpo variant ID");
            }
            $existingItems = $this->getYotpoVariantFromItemEntityId($itemEntityId, $yotpoParentEntityId);
        } else {
            throw new InvalidArgumentException("Unsupported entityType provided");
        }

        if ($existingItems
            && is_array($existingItems)
            && isset($existingItems[0])
            && isset($existingItems[0]['yotpo_id'])) {
            return $existingItems[0]['yotpo_id'];
        }

        return 0;
    }

    /**
     * @param integer $itemEntityId
     * @return array<mixed>
     */
    private function getYotpoProductFromItemEntityId($itemEntityId)
    {
        $productGetEndpoint = $this->coreConfig->getEndpoint('products');
        $requestData = ['external_ids' => $itemEntityId, 'entityLog' => 'catalog'];
        $response = $this->coreSync->sync(CoreConfig::METHOD_GET, $productGetEndpoint, $requestData);

        if (!$response->getData('is_success')) {
            throw new UnexpectedValueException("Request to get product from Yotpo was not successful");
        }

        $responseData = $response->getResponse();
        return array_key_exists('products', $responseData) ? $responseData['products'] : [];
    }

    /**
     * @param integer $yotpoParentProductId
     * @param integer $itemEntityId
     * @return array<mixed>
     */
    private function getYotpoVariantFromItemEntityId($yotpoParentProductId, $itemEntityId)
    {
        $variantGetEndpoint = $this->coreConfig->getEndpoint(
            'variant',
            ['{yotpo_product_id}'],
            [$yotpoParentProductId]
        );
        $requestData = ['external_ids' => $itemEntityId, 'entityLog' => 'catalog'];
        $response = $this->coreSync->sync(CoreConfig::METHOD_GET, $variantGetEndpoint, $requestData);

        if (!$response->getData('is_success')) {
            throw new UnexpectedValueException("Request to get variant from Yotpo was not successful");
        }

        $responseData = $response->getResponse();
        return array_key_exists('variants', $responseData) ? $responseData['variants'] : [];
    }
}
