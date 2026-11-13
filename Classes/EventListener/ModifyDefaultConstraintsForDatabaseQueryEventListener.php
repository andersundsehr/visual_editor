<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Domain\Event\ModifyDefaultConstraintsForDatabaseQueryEvent;
use TYPO3\CMS\VisualEditor\Context\EditModeAspect;

/**
 * Shows hidden content elements in edit mode in PageRepository::getDefaultConstraints(),
 * which is used e.g. by Extbase and the CONTENT content object instead of query restrictions.
 * All other tables keep the behavior of the core.
 */
#[AsEventListener('visual-editor/show-hidden-content-elements-in-default-constraints')]
#[Autoconfigure(public: true)]
final readonly class ModifyDefaultConstraintsForDatabaseQueryEventListener
{
    public function __invoke(ModifyDefaultConstraintsForDatabaseQueryEvent $event): void
    {
        if ($event->getTable() !== 'tt_content' || !EditModeAspect::isEditMode($event->getContext())) {
            return;
        }

        $constraints = $event->getConstraints();
        if (!isset($constraints['disabled'])) {
            return;
        }

        unset($constraints['disabled']);
        $event->setConstraints($constraints);
    }
}
