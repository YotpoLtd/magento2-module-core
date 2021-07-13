<?php
namespace Yotpo\Core\Model\Sync\Metadata\Cron;

use Yotpo\Core\Model\Sync\Metadata\Processor as MetadataProcessor;

/**
 * Class MetadataSync - Process metadata using cron job
 */
class MetadataSync
{
    /**
     * @var MetadataProcessor
     */
    protected $metadataProcessor;

    /**
     * MetadataSync constructor.
     * @param MetadataProcessor $metadataProcessor
     */
    public function __construct(
        MetadataProcessor $metadataProcessor
    ) {
        $this->metadataProcessor = $metadataProcessor;
    }

    /**
     * Process metadata sync
     *
     * @return void
     */
    public function execute()
    {
        $this->metadataProcessor->process();
    }
}
