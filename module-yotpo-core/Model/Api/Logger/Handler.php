<?php

namespace Yotpo\Core\Model\Api\Logger;

use Yotpo\Core\Model\Logger\Handler as YotpoCoreMainHandler;

/**
 * Class Handler for custom logger
 */
class Handler extends YotpoCoreMainHandler
{
    /**
     * File name
     *
     * @var string
     */
    protected $fileName = '/var/log/yotpo/api.log';
}
