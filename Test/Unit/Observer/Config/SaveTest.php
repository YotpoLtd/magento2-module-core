<?php

namespace Yotpo\Core\Test\Unit\Observer\Config;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Yotpo\Core\Model\Config as YotpoConfig;
use Yotpo\Core\Model\Sync\ResetEntitiesSync as SyncReset;
use Yotpo\Core\Observer\Config\Save;

/**
 * Covers the shipments_flag config-change hook: a merchant flipping is_fulfillment_based_on_shipment
 * is a deliberate signal to re-derive fulfillment for already-synced orders, so the stored
 * per-order pin (Data.php:444-448) must be cleared rather than left to latch forever.
 */
class SaveTest extends TestCase
{
    const SHIPMENTS_FLAG_PATH = 'yotpo_core/sync_settings/orders_sync/shipments/shipments_flag';
    const UNRELATED_PATH = 'yotpo_core/sync_settings/orders_sync/enable_real_time_sync';

    /**
     * @param SyncReset&MockObject $syncReset
     * @return Save
     */
    private function createSave($syncReset)
    {
        $save = $this->getMockBuilder(Save::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $yotpoConfig = $this->createMock(YotpoConfig::class);
        $yotpoConfig->method('getConfigPath')
            ->with('is_fulfillment_based_on_shipment')
            ->willReturn(self::SHIPMENTS_FLAG_PATH);

        (new \ReflectionProperty(Save::class, 'yotpoConfig'))->setValue($save, $yotpoConfig);
        (new \ReflectionProperty(Save::class, 'syncReset'))->setValue($save, $syncReset);

        return $save;
    }

    /**
     * @param int|null $storeId
     * @param array <string> $changedPaths
     * @return Observer
     */
    private function createObserver($storeId, $changedPaths)
    {
        $event = new Event(['changed_paths' => $changedPaths, 'store' => $storeId]);
        return new Observer(['event' => $event]);
    }

    public function testIsShipmentsFlagChangedIsTrueWhenPathPresent(): void
    {
        $save = $this->createSave($this->createMock(SyncReset::class));

        $this->assertTrue($save->isShipmentsFlagChanged([self::SHIPMENTS_FLAG_PATH]));
    }

    public function testIsShipmentsFlagChangedIsFalseWhenPathAbsent(): void
    {
        $save = $this->createSave($this->createMock(SyncReset::class));

        $this->assertFalse($save->isShipmentsFlagChanged([self::UNRELATED_PATH]));
    }

    public function testDoShipmentsFlagChangesClearsThePinForTheChangedStore(): void
    {
        $syncReset = $this->createMock(SyncReset::class);
        $syncReset->expects($this->once())
            ->method('clearOrdersFulfillmentFlag')
            ->with(7);

        $save = $this->createSave($syncReset);

        $save->doShipmentsFlagChanges($this->createObserver(7, [self::SHIPMENTS_FLAG_PATH]));
    }

    public function testDoShipmentsFlagChangesDoesNothingForAnUnrelatedSetting(): void
    {
        $syncReset = $this->createMock(SyncReset::class);
        $syncReset->expects($this->never())->method('clearOrdersFulfillmentFlag');

        $save = $this->createSave($syncReset);

        $save->doShipmentsFlagChanges($this->createObserver(7, [self::UNRELATED_PATH]));
    }

    public function testDoShipmentsFlagChangesDoesNothingWithoutAResolvedStoreScope(): void
    {
        // No store/website on the event -> getScopes() resolves scope_id to 0 (default scope).
        // Matches the existing app-key-change guard (isYotpoAppKeyChanged path): scope 0 is
        // skipped rather than guessed at.
        $syncReset = $this->createMock(SyncReset::class);
        $syncReset->expects($this->never())->method('clearOrdersFulfillmentFlag');

        $save = $this->createSave($syncReset);

        $save->doShipmentsFlagChanges($this->createObserver(null, [self::SHIPMENTS_FLAG_PATH]));
    }
}
