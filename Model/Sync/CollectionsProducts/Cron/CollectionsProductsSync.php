<?php
namespace Yotpo\Core\Model\Sync\CollectionsProducts\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Sync\CollectionsProducts\Processor as Processor;

/**
 * Class CollectionsProductsSync - Trigger Catalog sync - Scheduled Job
 */
class CollectionsProductsSync
{
    /**
     * @var Processor
     */
    protected $processor;

    /**
     * CatalogSync constructor.
     * @param Processor $processor
     */
    public function __construct(
        Processor $processor
    ) {
        $this->processor = $processor;
    }

    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @return void
     */
    public function execute()
    {
        $this->processor->process();
    }
}
