<?php
namespace Yotpo\Core\Model\Sync\Metadata;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Yotpo\Core\Model\Api\Sync as YotpoSyncMain;
use Yotpo\Reviews\Model\Config as YotpoConfig;
use Yotpo\Core\Model\AbstractJobs;
use Yotpo\Core\Model\Logger\General as Logger;
use Magento\Framework\App\ProductMetadataInterface;

/**
 * Class Processor - Process data for Metadata API
 */
class Processor extends AbstractJobs
{
    const ENTITY_LOG_FILE = 'entityLog';
    const SYNC_RESPONSE_IS_SUCCESS_KEY = 'is_success';
    const POST_METHOD_STRING = 'POST';

    /**
     * @var YotpoSyncMain
     */
    protected $yotpoSyncMain;

    /**
     * @var YotpoConfig
     */
    protected $yotpoConfig;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * Processor constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     * @param YotpoSyncMain $yotpoSyncMain
     * @param YotpoConfig $yotpoConfig
     * @param Logger $logger
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection,
        YotpoSyncMain $yotpoSyncMain,
        YotpoConfig $yotpoConfig,
        Logger $logger,
        ProductMetadataInterface $productMetadata
    ) {
        $this->yotpoSyncMain = $yotpoSyncMain;
        $this->yotpoConfig = $yotpoConfig;
        $this->logger = $logger;
        $this->productMetadata = $productMetadata;
        parent::__construct($appEmulation, $resourceConnection);
    }

    /**
     * Process updating metadata
     *
     * @return void
     */
    public function process()
    {
        foreach ((array)$this->yotpoConfig->getAllStoreIds(false) as $storeId) {
            try {
                $this->emulateFrontendArea($storeId);
                if (!$this->yotpoConfig->isEnabled()) {
                    $this->logger->info(
                        __(
                            'Updating Metadata is disabled. Skipping for Magento Store ID: %1, Name: %2',
                            $storeId,
                            $this->yotpoConfig->getStoreName($storeId)
                        )
                    );
                    continue;
                }
                $this->logger->info(
                    __(
                        'Starting updating Metadata for Magento Store ID: %1, Name: %2',
                        $storeId,
                        $this->yotpoConfig->getStoreName($storeId)
                    )
                );

                $metadataDataToSync = $this->prepareMetadata();
                $metadataDataToSync[$this::ENTITY_LOG_FILE] = 'general';
                $metadataEndpoint = $this->yotpoConfig->getEndpoint('metadata');
                $response = $this->yotpoSyncMain->syncV1(
                    $this::POST_METHOD_STRING,
                    $metadataEndpoint,
                    $metadataDataToSync
                );
                if ($response[$this::SYNC_RESPONSE_IS_SUCCESS_KEY]) {
                    $this->logger->info(
                        __(
                            'Finished updating Metadata successfully for Magento Store ID: %1, Name: %2',
                            $storeId,
                            $this->yotpoConfig->getStoreName($storeId)
                        )
                    );
                }
            } catch (\Exception $exception) {
                $this->logger->info(
                    __(
                        'Error occurred when updating Metadata,
                        got Exception on Magento Store ID: %1,
                        Name: %2,
                        Reason: %3',
                        $storeId,
                        $this->yotpoConfig->getStoreName($storeId),
                        $exception->getMessage()
                    )
                );
            } finally {
                $this->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * Prepare data for sending Metadata
     *
     * @param int|null $storeId
     * @return array<string, mixed>
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function prepareMetadata($storeId = null)
    {
        return [
            'utoken'   => '',
            'app_key'  => $this->yotpoConfig->getAppKey($storeId),
            'metadata' => [
                'platform'       => 'magento2',
                'version'        => "{$this->getMagentoPlatformVersion()} {$this->getMagentoPlatformEdition()}",
                'plugin_version' => $this->yotpoConfig->getModuleVersion(),
            ],
        ];
    }

    /**
     * Get Magento Platform Version
     *
     * @return string
     */
    private function getMagentoPlatformVersion()
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * Get Magento Platform Edition
     *
     * @return string
     */
    private function getMagentoPlatformEdition()
    {
        return $this->productMetadata->getEdition();
    }
}
