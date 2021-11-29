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
     * @var bool
     */
    private $systemInfoLog = false;

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
            $this->logSystemInfo();
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
        $this->logSystemInfo();
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

    /**
     * Log system information
     * @return void
     */
    public function logSystemInfo()
    {
        if (!$this->systemInfoLog) {
            parent::info('PHP Version : ' . phpversion(), []);
            parent::info(
                'Magento Version : ' .
                $this->yotpoConfig->getMagentoVersion() . ' - ' . $this->yotpoConfig->getMagentoEdition(),
                []
            );
            parent::info('Yotpo Module Version : ' . $this->yotpoConfig->getModuleVersion(), []);
            $this->systemInfoLog = true;
        }
    }
}
