<?php

namespace Yotpo\Core\Model\Logger\General;

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
    protected $fileName = '/var/log/yotpo/general.log';
}
