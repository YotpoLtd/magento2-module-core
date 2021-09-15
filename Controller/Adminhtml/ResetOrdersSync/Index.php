<?php

namespace Yotpo\Core\Controller\Adminhtml\ResetOrdersSync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Yotpo\Core\Model\Sync\Orders\ResetSync;

/**
 * Class Index
 * Reset Orders Sync
 */
class Index extends Action
{
    /**
     * Json Factory
     *
     * @var JsonFactory
     */
    protected $jsonResultFactory;

    /**
     * @var ResetSync
     */
    protected $resetSync;

    /**
     * Index constructor.
     * @param Context $context
     * @param JsonFactory $jsonResultFactory
     * @param ResetSync $resetSync
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonResultFactory,
        ResetSync $resetSync
    ) {
        $this->jsonResultFactory = $jsonResultFactory;
        $this->resetSync = $resetSync;
        parent::__construct($context);
    }

    /**
     * Process reset orders
     *
     * @return ResponseInterface|ResultInterface|void
     */
    public function execute()
    {
        try {
            $storeId = $this->_request->getParam('store');
            $currentTime = date('Y-m-d H:i:s');

            $this->resetSync->resetOrderStatusSync($storeId);
            $this->resetSync->updateLastSyncDate($currentTime, $storeId);
        } catch (NoSuchEntityException | LocalizedException $e) {
            $this->resetSync->addMessage(
                'error',
                'Something went wrong during reset sync process - ' . $e->getMessage()
            );
        }
        $result = $this->jsonResultFactory->create();
        $messages = $this->resetSync->getMessages();

        return $result->setData(['status' => $messages]);
    }
}
