<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\Tests\Functional\Service;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\VisualEditor\Context\EditModeAspect;
use TYPO3\CMS\VisualEditor\Service\EditModeService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class EditModeServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/visual_editor',
    ];

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->get(Context::class)->unsetAspect(EditModeAspect::IDENTIFIER);
        parent::tearDown();
    }

    public static function isEditModeDataProvider(): Generator
    {
        yield 'the edit mode aspect decides while the middleware handles the request' => [
            'editModeAspect' => true,
            'editModeParameter' => false,
            'backendUser' => false,
            'expected' => true,
        ];
        yield 'the edit mode aspect decides against the parameter' => [
            'editModeAspect' => false,
            'editModeParameter' => true,
            'backendUser' => true,
            'expected' => false,
        ];
        yield 'without aspect the parameter and a backend user enable the edit mode' => [
            'editModeAspect' => null,
            'editModeParameter' => true,
            'backendUser' => true,
            'expected' => true,
        ];
        yield 'without aspect the edit mode needs a backend user' => [
            'editModeAspect' => null,
            'editModeParameter' => true,
            'backendUser' => false,
            'expected' => false,
        ];
        yield 'without aspect the edit mode needs the parameter' => [
            'editModeAspect' => null,
            'editModeParameter' => false,
            'backendUser' => true,
            'expected' => false,
        ];
    }

    #[Test]
    #[DataProvider('isEditModeDataProvider')]
    public function isEditModeFollowsTheEditModeAspectAndFallsBackToTheRequest(
        ?bool $editModeAspect,
        bool $editModeParameter,
        bool $backendUser,
        bool $expected,
    ): void {
        if ($editModeAspect !== null) {
            $this->get(Context::class)->setAspect(EditModeAspect::IDENTIFIER, new EditModeAspect($editModeAspect));
        }

        if ($backendUser) {
            $GLOBALS['BE_USER'] = new BackendUserAuthentication();
        }

        $request = new ServerRequest('https://example.com/');
        if ($editModeParameter) {
            $request = $request->withQueryParams(['editMode' => 1]);
        }

        self::assertSame($expected, $this->get(EditModeService::class)->isEditMode($request));
    }
}
