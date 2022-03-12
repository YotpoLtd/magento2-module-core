<?php
namespace Yotpo\Core\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Class is for schedule the cron job for trigger product api
 */
class CronConfig extends Value
{
    /**
     * Cron expression configuration path
     */
    const CRON_EXPRESSION_PATH = '/schedule/cron_expr';

    /**
     * Cron expression model path
     */
    const CRON_MODEL_PATH = '/run/model';

    /**
     * Products sync Cron job path
     */
    // phpcs:ignore
    const PRODUCTS_SYNC_CRON_PATH = 'crontab/yotpo_core_catalog_sync/jobs/yotpo_cron_core_products_sync';

    /**
     * Category sync Cron job path
     */
    const CATEGORY_SYNC_CRON_PATH = 'crontab/yotpo_core_catalog_sync/jobs/yotpo_cron_core_category_sync';

    /**
     * @var ValueFactory
     */
    protected $configValueFactory;

    /**
     * @var string
     */
    protected $runModelPath = '';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ValueFactory $configValueFactory
     * @param AbstractResource $resource
     * @param AbstractDb<mixed> $resourceCollection
     * @param string $runModelPath
     * @param array<mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ValueFactory $configValueFactory,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        $runModelPath = '',
        array $data = []
    ) {
        $this->runModelPath = $runModelPath;
        $this->configValueFactory = $configValueFactory;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * {@inheritdoc}
     *
     * @return $this
     * @throws \Exception
     */
    public function afterSave()
    {
        $catalogCronExpressionString = $this->getData('groups/sync_settings/groups/catalog_sync/fields/frequency/value');
        try {
            $this->configureCronProductsSync($catalogCronExpressionString);

            $this->configureCronCategorySync($catalogCronExpressionString);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('We can\'t save the cron expression.'),
                $exception
            );
        }

        return parent::afterSave();
    }

    /**
     * @param string $catalogCronExpressionString
     * @return void
     */
    private function configureCronProductsSync($catalogCronExpressionString)
    {
        /** @phpstan-ignore-next-line */
        $this->configValueFactory->create()->load(
            self::PRODUCTS_SYNC_CRON_PATH . self::CRON_EXPRESSION_PATH,
            'path'
        )->setValue(
            $catalogCronExpressionString
        )->setPath(
            self::PRODUCTS_SYNC_CRON_PATH . self::CRON_EXPRESSION_PATH
        )->save();
         /** @phpstan-ignore-next-line */
        $this->configValueFactory->create()->load(
            self::PRODUCTS_SYNC_CRON_PATH . self::CRON_MODEL_PATH,
            'path'
        )->setValue(
            $this->runModelPath
        )->setPath(
            self::PRODUCTS_SYNC_CRON_PATH . self::CRON_MODEL_PATH
        )->save();
    }

    /**
     * @param string $catalogCronExpressionString
     * @return void
     */
    private function configureCronCategorySync($catalogCronExpressionString)
    {
        /** @phpstan-ignore-next-line */
        $this->configValueFactory->create()->load(
            self::CATEGORY_SYNC_CRON_PATH . self::CRON_EXPRESSION_PATH,
            'path'
        )->setValue(
            $catalogCronExpressionString
        )->setPath(
            self::CATEGORY_SYNC_CRON_PATH . self::CRON_EXPRESSION_PATH
        )->save();
        /** @phpstan-ignore-next-line */
        $this->configValueFactory->create()->load(
            self::CATEGORY_SYNC_CRON_PATH . self::CRON_MODEL_PATH,
            'path'
        )->setValue(
            $this->runModelPath
        )->setPath(
            self::CATEGORY_SYNC_CRON_PATH . self::CRON_MODEL_PATH
        )->save();
    }
}
