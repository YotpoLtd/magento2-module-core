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
        try {
            foreach ((array)$this->yotpoConfig->getAllStoreIds(false) as $storeId) {
                try {
                    $this->emulateFrontendArea($storeId);
                    if (!$this->yotpoConfig->isEnabled()) {
                        $this->logger->info(__('Skipping store ID: %1 [Disabled]', $storeId));
                        continue;
                    }
                    $this->logger->info(__('Updating metadata for store ID: %1 [START]', $storeId));
                    $data = $this->prepareMetadata();
                    $data['entityLog'] = 'general';
                    $endPoint = $this->yotpoConfig->getEndpoint('metadata');
                    $response = $this->yotpoSyncMain->syncV1('POST', $endPoint, $data);
                    if ($response['is_success']) {
                        $this->logger->info(__('Updating metadata for store ID: %1 [SUCCESS]', $storeId));
                    }
                } catch (\Exception $e) {
                    $this->logger->info(__('Exception on Store ID: %1, Reason: %2', $storeId, $e->getMessage()));
                }
                $this->stopEnvironmentEmulation();
            }
        } catch (\Exception $e) {
            $this->logger->info(__('Exception: %1', $e->getMessage()));
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
