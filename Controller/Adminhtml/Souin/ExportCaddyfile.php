<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Controller\Adminhtml\Souin;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use OOCS\SouinCache\Model\Caddyfile\Generator;

/**
 * Download the Caddyfile generated from the Souin settings.
 */
class ExportCaddyfile extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Backend::system';

    /**
     * @var FileFactory
     */
    protected $fileFactory;

    /**
     * @var Generator
     */
    protected $generator;

    /**
     * @param Context $context
     * @param FileFactory $fileFactory
     * @param Generator $generator
     */
    public function __construct(
        Context $context,
        FileFactory $fileFactory,
        Generator $generator
    ) {
        parent::__construct($context);
        $this->fileFactory = $fileFactory;
        $this->generator = $generator;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            return $this->fileFactory->create('Caddyfile', $this->generator->generate(), DirectoryList::VAR_DIR);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->resultRedirectFactory->create()->setPath(
                'adminhtml/system_config/edit',
                ['section' => 'system']
            );
        }
    }
}
