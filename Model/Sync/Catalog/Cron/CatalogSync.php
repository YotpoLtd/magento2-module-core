<?php
namespace Yotpo\Core\Model\Sync\Catalog\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Sync\Catalog\Processor;

/**
 * Class CatalogSync - Trigger Catalog sync - Scheduled Job
 */
class CatalogSync
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
     * @return void
     */
    public function execute()
    {
        $this->processor->process();
    }
}
