<?php

namespace Yotpo\Core\Model\Logger;

use Yotpo\Core\Model\Config as YotpoConfig;

/**
 * Class Logger - Core module
 */
class Main extends \Monolog\Logger
{

    const LOG_PREFIX = 'Yotpo :: ';

    /**
     * @var YotpoConfig
     */
    protected $yotpoConfig;

    /**
     * Main constructor.
     * @param string $name
     * @param YotpoConfig $yotpoConfig
     * @param array<mixed> $handlers
     * @param array<mixed> $processors
     */
    public function __construct(
        string $name,
        YotpoConfig $yotpoConfig,
        array $handlers = [],
        array $processors = []
    ) {
        $this->yotpoConfig = $yotpoConfig;
        parent::__construct($name, $handlers, $processors);
    }

    /**
     * Adds a log record at the INFO level.
     *
     * @param  string $message The log message
     * @param  array  <mixed> $context The log context
     * @return bool   Whether the record has been processed
     */
    public function info($message, array $context = []): bool
    {
        $message = self::LOG_PREFIX . $message;
        if ($this->isDebugEnabled()) {
            return parent::info($message, $context);
        }
        return true;
    }

    /**
     * Append error log with some Yotpo specific text
     *
     * @param string $message
     * @param array<mixed> $context
     * @return bool
     */
    public function error($message, array $context = []): bool
    {
        $message = self::LOG_PREFIX . $message;
        return parent::error($message, $context);
    }

    /**
     * @return mixed
     */
    public function isDebugEnabled()
    {
        return $this->yotpoConfig->getConfig('debug_mode_active');
    }
}
