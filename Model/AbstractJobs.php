<?php
namespace Yotpo\Core\Model;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Framework\App\ResourceConnection;

/**
 * Common class for all schedule jobs
 */
class AbstractJobs
{
    /**
     * @var AppEmulation
     */
    protected $appEmulation;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var array <mixed>
     */
    private $immediateRetryDone = [];

    /**
     * AbstractJobs constructor.
     * @param AppEmulation $appEmulation
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        AppEmulation $appEmulation,
        ResourceConnection $resourceConnection
    ) {
        $this->appEmulation = $appEmulation;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Start environment emulation of the specified store
     * @param int|null $storeId
     * @param string $area
     * @param bool $force
     * @return $this
     */
    public function startEnvironmentEmulation(
        $storeId,
        string $area = Area::AREA_FRONTEND,
        bool $force = false
    ): AbstractJobs {
        $this->stopEnvironmentEmulation();
        $this->appEmulation->startEnvironmentEmulation((int)$storeId, $area, $force);
        return $this;
    }

    /**
     * Stop environment emulation
     *
     * @return $this
     */
    public function stopEnvironmentEmulation(): AbstractJobs
    {
        $this->appEmulation->stopEnvironmentEmulation();
        return $this;
    }

    /**
     * Start environment emulation
     *
     * @param int|null $storeId
     * @param bool $force
     * @return $this
     */
    public function emulateFrontendArea($storeId, bool $force = true): AbstractJobs
    {
        $this->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, $force);
        return $this;
    }

    /**
     * Insert/Update to table
     *
     * @param string $table
     * @param array<mixed> $insertData
     * @return void
     */
    public function insertOnDuplicate($table, array $insertData = [])
    {
        if (!$insertData) {
            return;
        }
        $connection = $this->resourceConnection->getConnection();
        foreach ($insertData as $data) {
            $connection->insertOnDuplicate(
                $this->resourceConnection->getTableName($table),
                $data
            );
        }
    }

    /**
     * @param string $table
     * @param array<mixed> $insertData
     * @param array<mixed> $whereCondition
     * @return void
     */
    public function update($table, $insertData, $whereCondition)
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName($table),
            $insertData,
            $whereCondition
        );
    }

    /**
     * @return array
     * @param string $entity
     * @param int|string $entityId
     * @param int|null $storeId
     * @return bool
     */
    public function getImmediateRetryAlreadyDone($entity, $entityId, $storeId)
    {
        $storeId = (int) $storeId;
        return array_key_exists($entity, $this->immediateRetryDone) &&
        isset($this->immediateRetryDone[$entity][$storeId]) &&
        isset($this->immediateRetryDone[$entity][$storeId][$entityId]) ?
            $this->immediateRetryDone[$entity][$storeId][$entityId] :
            false ;
    }

    /**
     * @param string $entity
     * @param int|string $entityId
     * @param int|null $storeId
     * @param bool $flag
     * @return void
     */
    public function setImmediateRetryAlreadyDone($entity, $entityId, $storeId, $flag = true)
    {
        $storeId = (int) $storeId;
        $this->immediateRetryDone[$entity][$storeId][$entityId] = $flag;
    }

    /**
     * @param array <mixed> $response
     * @param string $entity
     * @param int|string $entityId
     * @param int|null $storeId
     * @return bool
     */
    public function isImmediateRetry($response, $entity, $entityId, $storeId)
    {
        $storeId = (int) $storeId;
        /** @phpstan-ignore-next-line */
        $responseCode = $response && $response->getData('status') ? $response->getData('status') : null;
        if ($responseCode) {
            $isImmediateRetry = $this->isImmediateRetryResponse($responseCode);
            return $isImmediateRetry && !$this->getImmediateRetryAlreadyDone($entity, $entityId, $storeId);
        }
        return false;
    }

    /**
     * @param string | int $responseCode
     * @return bool
     */
    public function isImmediateRetryResponse($responseCode)
    {
        return in_array($responseCode, ['404','401']);
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    public function getConnection()
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function getTableName($tableName)
    {
        return $this->resourceConnection->getTableName($tableName);
    }
}
