<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\BackwardsCompatibility\ViewHelpers\Render;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Domain\Exception\RecordPropertyNotFoundException;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Schema\Field\InputFieldType;
use TYPO3\CMS\Core\Schema\Field\TextFieldType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\CMS\VisualEditor\EditableResult\Input;
use TYPO3\CMS\VisualEditor\EditableResult\RichText;
use TYPO3\CMS\VisualEditor\Service\ModelToRawRecordService;
use TYPO3\CMS\VisualEditor\Service\TextRenderingService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\InvalidArgumentValueException;

use function get_debug_type;
use function is_string;

/**
 * Compatibility ViewHelper for TYPO3 versions without BeforeRenderTextEvent.
 * Renders content based on records and fields from a TCA schema.
 * Handles the processing of both simple and rich text fields.
 *
 * ````html
 *   <f:render.text record="{page}" field="bodytext" />
 *   {record -> f:render.text(field: 'title')}
 *   <f:render.text field="subheader">{record}</f:render.text>
 * ````
 */
final class TextViewHelper extends AbstractViewHelper
{
    private const RECORD_TYPE = RecordInterface::class . '|' . PageInformation::class . '|' . DomainObjectInterface::class;

    /**
     * Extbase models have a __toString() method and Fluid calls that if we escape the Children (arguments)
     */
    protected $escapeChildren = false;

    protected $escapeOutput = false;

    public function __construct(
        private readonly RecordFactory $recordFactory,
        private readonly TcaSchemaFactory $tcaSchema,
        private readonly ModelToRawRecordService $modelToRawRecordService,
        private readonly TextRenderingService $textRenderingService,
    ) {
    }

    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $type = 'object';
        // can not always use DI here because fluid initializes the class without DI. and calls initializeArguments.
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
        if ($typo3Version->getMajorVersion() >= 14) {
            $type = self::RECORD_TYPE;
        }

        $this->registerArgument('record', $type, 'A Record API Object (field is also needed)');
        $this->registerArgument('field', 'string', 'the field that should be rendered', true);
        $this->registerArgument('optional', 'boolean', 'If the provided field does not exist in the record, null will be returned.', false, false);
    }

    public function getContentArgumentName(): string
    {
        return 'record';
    }

    public function render(): Input|RichText|null
    {
        $renderingContext = $this->renderingContext ?? throw new InvalidArgumentException('$this->renderingContext is not available', 1772464146);
        $request = $renderingContext->getAttribute(ServerRequestInterface::class);

        $record = $this->renderChildren();
        $field = $this->arguments['field'];

        if ($record instanceof PageInformation) {
            $record = $this->recordFactory->createResolvedRecordFromDatabaseRow('pages', $record->getPageRecord());
        }

        if ($record instanceof DomainObjectInterface) {
            $record = $this->modelToRawRecordService->modelToRawRecord($record);
        }

        if (!$record instanceof RecordInterface) {
            throw new InvalidArgumentException(
                'The record argument must be an instance of ' . self::RECORD_TYPE . '. Given: ' . get_debug_type(
                    $record,
                ),
                1770539910,
            );
        }

        try {
            $value = $record->get($field) ?? '';
        } catch (RecordPropertyNotFoundException $recordPropertyNotFoundException) {
            if ($this->arguments['optional']) {
                return null;
            }

            throw new InvalidArgumentValueException(
                'The field "' . $field . '" does not exist in the given record `' . $record->getFullType() . '`.',
                1775554099,
                $recordPropertyNotFoundException,
            );
        }

        if (!is_string($value)) {
            $table = $record->getMainType();
            throw new InvalidArgumentException(
                'The value of the field "' . $table . '.' . $field . '" must be a string. Given: ' . get_debug_type($value),
                1770321858,
            );
        }

        $fieldSchema = $this->tcaSchema->get($record->getFullType())->getField($field);
        if ($fieldSchema instanceof InputFieldType || $fieldSchema instanceof TextFieldType) {
            return $this->textRenderingService->render($value, $record, $fieldSchema, $request);
        }

        $table = $record->getMainType();
        throw new InvalidArgumentException('The field "' . $table . '.' . $field . '" is not supported. Given: ' . get_debug_type($fieldSchema), 1770618219);
    }
}
