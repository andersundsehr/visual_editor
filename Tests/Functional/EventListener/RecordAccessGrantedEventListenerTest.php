<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\Tests\Functional\EventListener;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Access\RecordAccessVoter;
use TYPO3\CMS\VisualEditor\Context\EditModeAspect;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordAccessGrantedEventListenerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/visual_editor',
    ];

    public static function accessGrantedDataProvider(): Generator
    {
        yield 'edit mode grants access to hidden content elements' => [
            'table' => 'tt_content',
            'record' => ['uid' => 1, 'hidden' => 1],
            'editMode' => true,
            'expected' => true,
        ];
        yield 'edit mode keeps the endtime of hidden content elements' => [
            'table' => 'tt_content',
            'record' => ['uid' => 1, 'hidden' => 1, 'endtime' => 1],
            'editMode' => true,
            'expected' => false,
        ];
        yield 'edit mode denies access to hidden records of other tables' => [
            'table' => 'sys_file_reference',
            'record' => ['uid' => 1, 'hidden' => 1],
            'editMode' => true,
            'expected' => false,
        ];
        yield 'edit mode grants access to visible records of other tables' => [
            'table' => 'sys_file_reference',
            'record' => ['uid' => 1, 'hidden' => 0],
            'editMode' => true,
            'expected' => true,
        ];
        yield 'without edit mode access to hidden content elements is denied' => [
            'table' => 'tt_content',
            'record' => ['uid' => 1, 'hidden' => 1],
            'editMode' => false,
            'expected' => false,
        ];
    }

    /**
     * @param array<string, int> $record
     */
    #[Test]
    #[DataProvider('accessGrantedDataProvider')]
    public function accessGrantedIncludesHiddenRecordsOnlyForContentElementsInEditMode(
        string $table,
        array $record,
        bool $editMode,
        bool $expected,
    ): void {
        $context = $this->get(Context::class);
        $context->setAspect(EditModeAspect::IDENTIFIER, new EditModeAspect($editMode));

        self::assertSame($expected, $this->get(RecordAccessVoter::class)->accessGranted($table, $record, $context));
    }
}
