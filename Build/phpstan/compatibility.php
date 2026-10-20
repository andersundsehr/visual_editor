<?php

declare(strict_types=1);

use TYPO3\CMS\Fluid\Event\BeforeRenderTextEvent;

return [
    'parameters' => [
        'scanFiles' => class_exists(BeforeRenderTextEvent::class) ? [] : [__DIR__ . '/BeforeRenderTextEvent.stub'],
    ],
];
