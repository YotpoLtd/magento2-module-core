<?php

namespace Yotpo\Core\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;

/**
 * Class DownloadLogs - Download sync logs
 */
class DownloadLogs extends Action
{
    const ORDERS_LOG    = 'orders';
    const CATALOG_LOG   = 'catalog';
    const CUSTOMERS_LOG = 'customers';
    const CHECKOUT_LOG  = 'checkout';

    /**
     * @var FileFactory
     */
    protected $fileFactory;

    /**
     * DownloadLogs constructor.
     * @param Context $context
     * @param FileFactory $fileFactory
     */
    public function __construct(
        Context $context,
        FileFactory $fileFactory
    ) {
        $this->fileFactory =  $fileFactory;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|Redirect|ResultInterface|string
     */
    public function execute()
    {
        $response   =   '';
        $downloadType = $this->getRequest()->getParam('logName');
        switch ($downloadType) {
            case self::ORDERS_LOG:
                $filepath = \Yotpo\Core\Model\Sync\Orders\Logger\Handler::FILE_NAME;
                $response = $this->downloadFile($filepath);
                break;
            case self::CATALOG_LOG:
                $filepath = \Yotpo\Core\Model\Sync\Catalog\Logger\Handler::FILE_NAME;
                $response = $this->downloadFile($filepath);
                break;
            case self::CUSTOMERS_LOG:
                $filepath = \Yotpo\SmsBump\Model\Sync\Customers\Logger\Handler::FILE_NAME;
                $response = $this->downloadFile($filepath);
                break;
            case self::CHECKOUT_LOG:
                $filepath = \Yotpo\SmsBump\Model\Sync\Checkout\Logger\Handler::FILE_NAME;
                $response = $this->downloadFile($filepath);
                break;
        }

        return $response;
    }

    /**
     * @param string $filepath
     * @return ResponseInterface|Redirect
     */
    public function downloadFile($filepath)
    {
        try {
            $fileName = substr($filepath, strrpos($filepath, '/') + 1);
            $content = ['type' => 'filename', 'value' => $filepath];
            return $this->fileFactory->create($fileName, $content);
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while downloading the log :') . ' ' . $e->getMessage()
            );
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setRefererOrBaseUrl();
            return  $resultRedirect;
        }
    }
}
