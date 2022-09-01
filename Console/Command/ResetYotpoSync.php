<?php

namespace Yotpo\Core\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\State as AppState;
use Yotpo\Core\Model\Config;
use Yotpo\Core\Model\Sync\ResetEntitiesSync;

/**
 * Class ReSetYotpoSync - Manage Yotpo Reset Sync
 */
class ResetYotpoSync extends Command
{
    public const YOTPO_ENTITY              = 'entity';
    public const STORE_ID                  = 'store_id';
    public const YOTPO_ENTITY_ALL          = 'all';
    public const YOTPO_ENTITY_CUSTOMERS    = 'customer';
    public const YOTPO_ENTITY_ORDERS       = 'order';
    public const YOTPO_ENTITY_CATALOG      = 'catalog';

    /**
     * @var string[]
     */
    protected $yotpoEntities = ['order', 'customer', 'catalog', 'all'];

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var AppState
     */
    protected $appState;

    /**
     * @var ResetEntitiesSync
     */
    protected $syncReset;

    /**
     * @var Config
     */
    protected $config;

    /**
     * ResetYotpoSync constructor.
     * @param ObjectManagerInterface $objectManager
     * @param AppState $appState
     * @param string|null $name
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        AppState $appState,
        string $name = null
    ) {
        $this->objectManager = $objectManager;
        $this->appState = $appState;
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function init()
    {
        $this->syncReset = $this->objectManager->get(\Yotpo\Core\Model\Sync\ResetEntitiesSync::class);
        $this->config = $this->objectManager->get(\Yotpo\Core\Model\Config::class);
        $this->setAreaCodeIfNotConfigured();
    }

    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $options = [
            new InputOption(
                self::YOTPO_ENTITY,
                null,
                InputOption::VALUE_REQUIRED,
                'Entity'
            ),
            new InputOption(
                self::STORE_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Store ID'
            ),
        ];

        $this->setName('yotpo:resetsync')
            ->setDescription('Reset Sync')
            ->setDefinition($options);

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return $this|int
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init();

        $storeId = $input->getOption(self::STORE_ID);
        if ($storeId) {
            $storeIds = [$storeId];
        } else {
            $storeIds = $this->config->getAllStoreIds();
        }
        if (!$storeIds) {
            return $this;
        }
        foreach ($storeIds as $storeId) {
            $yotpoEntityInput = $input->getOption(self::YOTPO_ENTITY);
            if (!$yotpoEntityInput) {
                return $this;
            }
            if (!is_array($yotpoEntityInput)) {
                $yotpoEntityInput = [$yotpoEntityInput];
            }
            $this->resetYotpoSyncByEntity($yotpoEntityInput, $storeId, $output);
        }

        return $this;
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function setAreaCodeIfNotConfigured()
    {
        try {
            $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
        }
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetAllSync($storeId)
    {
        $this->syncReset->resetSync($storeId);
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetCatalogSync($storeId)
    {
        $this->syncReset->resetCatalogSync($storeId);
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetCustomersSync($storeId)
    {
        $this->syncReset->resetCustomersSync($storeId);
    }

    /**
     * @param int $storeId
     * @return void
     */
    public function resetOrdersSync($storeId)
    {
        $this->syncReset->resetOrdersSync($storeId);
    }

    /**
     * @param array <bool|string|null> $yotpoEntityInput
     * @param int $storeId
     * @param OutputInterface $output
     * @return void
     * @throws NoSuchEntityException
     */
    protected function resetYotpoSyncByEntity($yotpoEntityInput, $storeId, OutputInterface $output)
    {
        foreach ($yotpoEntityInput as $yotpoEntity) {
            switch ($yotpoEntity) {
                case self::YOTPO_ENTITY_CATALOG:
                    $this->resetCatalogSync($storeId);
                    break;
                case self::YOTPO_ENTITY_CUSTOMERS:
                    $this->resetCustomersSync($storeId);
                    break;
                case self::YOTPO_ENTITY_ORDERS:
                    $this->resetOrdersSync($storeId);
                    break;
                case self::YOTPO_ENTITY_ALL:
                    $this->resetAllSync($storeId);
                    break;
                default:
                    $output->writeln('Yotpo Reset Sync');
                    break;
            }
            if (in_array($yotpoEntity, $this->yotpoEntities)) {
                $storeName = $this->config->getStoreName($storeId);
                $output->writeln(
                    'Sync reset completed for Entity - ' . $yotpoEntity . ', Store ID - ' . $storeName
                );
            } else {
                $output->writeln('Entity - ' . $yotpoEntity . ' does not exist');
            }
        }
    }
}
