<?php

namespace Yotpo\Core\Model\Sync\Orders\Logger;

use Yotpo\Core\Model\Logger\Handler as CoreHandler;

/**
 * Class Handler - For customized logging for orders
 */
class Handler extends CoreHandler
{
    /** @phpstan-ignore-next-line */
    const FILE_NAME = BP . '/var/log/yotpo/orders.log';

    /**
     * File name
     *
     * @var string
     */
    protected $fileName = '/var/log/yotpo/orders.log';
}
