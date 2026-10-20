<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\Richtext as RichtextConfiguration;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Html\RteHtmlParser;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Schema\Field\InputFieldType;
use TYPO3\CMS\Core\Schema\Field\TextFieldType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\ViewHelpers\Format\HtmlViewHelper as FormatHtmlViewHelper;
use TYPO3\CMS\Fluid\ViewHelpers\Sanitize\HtmlViewHelper as SanitizeHtmlViewHelper;
use TYPO3\CMS\Fluid\ViewHelpers\Transform\HtmlViewHelper as TransformHtmlViewHelper;
use TYPO3\CMS\VisualEditor\Core\RichtText\RichTextConfigurationService;
use TYPO3\CMS\VisualEditor\Core\RichtText\RichTextConfigurationServiceDto;
use TYPO3\CMS\VisualEditor\EditableResult\Input;
use TYPO3\CMS\VisualEditor\EditableResult\RichText;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

use function htmlspecialchars;
use function is_int;
use function is_string;
use function json_encode;
use function nl2br;
use function str_replace;

use const JSON_THROW_ON_ERROR;

final readonly class TextRenderingService
{
    public function __construct(
        private EditModeService $editModeService,
        private TcaSchemaFactory $tcaSchema,
        private RteHtmlParser $rteHtmlParser,
        private AssetCollector $assetCollector,
        private RichTextConfigurationService $richTextConfigurationService,
        private RichtextConfiguration $richtext,
        private LocalizationService $localizationService,
        private RenderingContextFactory $renderingContextFactory,
    ) {
    }

    public function render(
        string $value,
        RecordInterface $record,
        InputFieldType|TextFieldType $fieldSchema,
        ServerRequestInterface $request,
    ): Input|RichText {
        $this->editModeService->init($request);
        $canEdit = $this->editModeService->canEditField($record, $fieldSchema->getName(), $request);

        $schema = $this->tcaSchema->get($record->getFullType());
        $tableLabel = $schema->getTitle($this->localizationService->tryTranslation(...));
        $label = $tableLabel . ': ' . $this->localizationService->tryTranslation($fieldSchema->getLabel());

        if ($fieldSchema instanceof InputFieldType || !$fieldSchema->isRichText()) {
            return $this->renderInput($value, $record, $fieldSchema, $label, $canEdit, $fieldSchema instanceof TextFieldType);
        }

        return $this->renderRichText($value, $record, $fieldSchema, $label, $canEdit, $request);
    }

    private function renderInput(
        string $value,
        RecordInterface $record,
        InputFieldType|TextFieldType $field,
        string $label,
        bool $editMode,
        bool $allowNewlines = false,
    ): Input {
        $html = htmlspecialchars($value);
        if ($allowNewlines) {
            $html = nl2br(htmlspecialchars(str_replace('<br>', "\n", $value)));
        }

        if (!$editMode) {
            return new Input($label, $html, !$value, $value); // TODO maybe we should remove the Input and RichText classes?
        }

        $tag = GeneralUtility::makeInstance(TagBuilder::class);
        $tag->setTagName('ve-editable-text');
        $tag->addAttribute('table', $record->getMainType());
        $tag->addAttribute('uid', (string)($record->getComputedProperties()->getLocalizedUid() ?: $record->getComputedProperties()->getVersionedUid() ?: $record->getUid()));
        $tag->addAttribute('field', $field->getName());
        $tag->addAttribute('fieldPositionId', $record->getMainType() . ':' . $record->getUid() . ':' . $field->getName());

        $tag->addAttribute('name', $label);

        $title = $this->localizationService->tryTranslation(
            'LLL:EXT:visual_editor/Resources/Private/Language/locallang.xlf:editable.title',
            [$label],
        );
        $tag->addAttribute('title', $title);
        $tag->addAttribute('allowNewlines', $allowNewlines);
        $tag->addAttribute('value', str_replace('<br>', "\n", $value));
        $tag->addAttribute('validation', $this->getInputValidationConfiguration($field, $allowNewlines));

        $tag->setContent($html);

        $tag->forceClosingTag(true);

        return new Input($label, $tag->render(), !$value, $value ?: '');
    }

    private function getInputValidationConfiguration(InputFieldType|TextFieldType $field, bool $allowNewlines): string
    {
        $config = $field->getConfiguration();
        $validation = [
            'required' => $field->isRequired(),
            'allowNewlines' => $allowNewlines,
        ];

        $min = $config['min'] ?? null;
        if (is_int($min) || (is_string($min) && $min !== '')) {
            $min = (int)$min;
            if ($min > 0) {
                $validation['min'] = $min;
            }
        }

        $max = $config['max'] ?? null;
        if (is_int($max) || (is_string($max) && $max !== '')) {
            $max = (int)$max;
            if ($max > 0) {
                $validation['max'] = $max;
            }
        }

        $evalList = array_flip(GeneralUtility::trimExplode(',', (string)($config['eval'] ?? ''), true));

        $evals = [];
        $evalOrder = ['trim', 'upper', 'lower', 'alpha', 'num', 'alphanum', 'alphanum_x', 'nospace'];
        foreach ($evalOrder as $rule) {
            if (array_key_exists($rule, $evalList)) {
                $evals[] = $rule;
            }
        }

        if ($evals !== []) {
            $validation['eval'] = $evals;
        }

        return json_encode($validation, JSON_THROW_ON_ERROR);
    }

    private function renderRichText(string $value, RecordInterface $record, TextFieldType $field, string $label, bool $editMode, ServerRequestInterface $request): RichText
    {
        if (!$editMode) {
            $renderingContext = $this->renderingContextFactory->create([], $request);

            $isBackendRequest = ApplicationType::fromRequest($request)->isBackend();
            if (!$isBackendRequest) {
                $escapedValue = $renderingContext->getViewHelperInvoker()->invoke(
                    FormatHtmlViewHelper::class,
                    [],
                    $renderingContext,
                    fn(): string => $value,
                );
            } else {
                $escapedValue = $renderingContext->getViewHelperInvoker()->invoke(
                    SanitizeHtmlViewHelper::class,
                    [],
                    $renderingContext,
                    fn(): string => $value,
                );
                $escapedValue = $renderingContext->getViewHelperInvoker()->invoke(
                    TransformHtmlViewHelper::class,
                    [],
                    $renderingContext,
                    fn() => $escapedValue,
                );
            }

            return new RichText($label, $escapedValue, $value === '', $value);
        }

        [$options, $processingConfiguration] = $this->getOptions($record, $field->getName());
        $escapedValue = $this->rteHtmlParser->transformTextForRichTextEditor($value, $processingConfiguration);

        $tag = GeneralUtility::makeInstance(TagBuilder::class);
        $tag->setTagName('ve-editable-rich-text');
        $tag->addAttribute('table', $record->getMainType());
        $tag->addAttribute('uid', (string)($record->getComputedProperties()->getLocalizedUid() ?: $record->getComputedProperties()->getVersionedUid() ?: $record->getUid()));
        $tag->addAttribute('field', $field->getName());
        $tag->addAttribute('fieldPositionId', $record->getMainType() . ':' . $record->getUid() . ':' . $field->getName());
        $tag->addAttribute('name', $label);

        $title = $this->localizationService->tryTranslation(
            'LLL:EXT:visual_editor/Resources/Private/Language/locallang.xlf:editable.title',
            [$label],
        );
        $tag->addAttribute('title', $title);
        $tag->addAttribute('options', $options);

        $tag->setContent($escapedValue);

        $tag->forceClosingTag(true);
        return new RichText($label, $tag->render(), $value === '', $value);
    }

    /**
     * @return array{0:string, 1:array<mixed>}
     */
    private function getOptions(RecordInterface $record, string $field): array
    {
        $schema = $this->tcaSchema->get($record->getFullType());
        $richtextConfiguration = $this->richtext->getConfiguration(
            $record->getMainType(),
            $field,
            $record->getPid(),
            $record->getRecordType() ?? '',
            $schema->getField($field)->getConfiguration(),
        );

        $rawRecord = $record->getRawRecord() ?? $record;
        $richTextConfigurationServiceDto = new RichTextConfigurationServiceDto(
            tableName: $record->getMainType(),
            uid: $record->getComputedProperties()->getLocalizedUid() ?: $record->getComputedProperties()->getVersionedUid() ?: $record->getUid(),
            fieldName: $field,
            recordTypeValue: $record->getRecordType() ?? '',
            effectivePid: $record->getPid(),
            richtextConfigurationName: $richtextConfiguration['preset'],
            label: 'Text',
            placeholder: '',
            readOnly: false,
            data: $rawRecord->toArray(),
            additionalConfiguration: $richtextConfiguration['editor']['config'],
            externalPlugins: $richtextConfiguration['editor']['externalPlugins'],
        );

        $config = $this->richTextConfigurationService->resolveCkEditorConfiguration($richTextConfigurationServiceDto);

        unset($config['height']); // height is set by the content itself and css
        $config['debug'] = false; // for now we disable debug mode

        // load required JavaScript modules:
        foreach ($config['importModules'] as $importModule) {
            $this->assetCollector->addJavaScriptModule($importModule['module']);
        }

        $this->assetCollector->addJavaScriptModule('@typo3/ckeditor5/translations/' . $config['language']['ui'] . '.js');
        return [json_encode($config, JSON_THROW_ON_ERROR), $richtextConfiguration['proc.'] ?? []];
    }
}
