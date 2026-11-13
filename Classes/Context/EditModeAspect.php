<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\Context;

use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectPropertyNotFoundException;

/**
 * Tells whether the current frontend request renders the edit mode.
 *
 * Set by the EditModeMiddleware while it handles a frontend request and removed afterwards, so services
 * without access to the request, e.g. query restrictions, can check it.
 */
final readonly class EditModeAspect implements AspectInterface
{
    public const IDENTIFIER = 'visual_editor';

    public function __construct(
        private bool $editMode = false,
    ) {
    }

    public static function isEditMode(Context $context): bool
    {
        // The aspect is missing outside of frontend requests, e.g. in the backend or CLI.
        return $context->hasAspect(self::IDENTIFIER)
            && (bool)$context->getPropertyFromAspect(self::IDENTIFIER, 'editMode', false);
    }

    public function get(string $name): bool
    {
        if ($name === 'editMode') {
            return $this->editMode;
        }

        throw new AspectPropertyNotFoundException('Property "' . $name . '" not found in Aspect "' . self::class . '".', 1794563200);
    }
}
