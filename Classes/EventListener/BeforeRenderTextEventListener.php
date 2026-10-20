<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Fluid\Event\BeforeRenderTextEvent;
use TYPO3\CMS\VisualEditor\Service\ModelToRawRecordService;
use TYPO3\CMS\VisualEditor\Service\TextRenderingService;

final readonly class BeforeRenderTextEventListener
{
    public function __construct(
        private TextRenderingService $textRenderingService,
        private ModelToRawRecordService $modelToRawRecordService,
    ) {
    }

    #[AsEventListener]
    public function __invoke(BeforeRenderTextEvent $event): void
    {
        $request = $event->getRequest();
        if ($request === null) {
            return;
        }

        $record = $event->getRecord();
        if ($record instanceof DomainObjectInterface) {
            $record = $this->modelToRawRecordService->modelToRawRecord($record);
        }

        $event->setRenderedText($this->textRenderingService->render(
            $event->getValue(),
            $record,
            $event->getFieldSchema(),
            $request,
        ));
    }
}
