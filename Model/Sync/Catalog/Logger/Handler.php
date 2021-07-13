<?php

namespace Yotpo\Core\Model\Sync\Catalog\Logger;

use Yotpo\Core\Model\Logger\Handler as CoreMainHandler;

/**
 * Class Handler for custom logger - Catalog Api
 */
class Handler extends CoreMainHandler
{
    /** @phpstan-ignore-next-line */
    const FILE_NAME = BP . '/var/log/yotpo/catalog.log';

    /**
     * File name
     *
     * @var string
     */
    protected $fileName = '/var/log/yotpo/catalog.log';
}
