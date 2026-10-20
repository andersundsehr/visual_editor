<?php

declare(strict_types=1);

namespace TYPO3\CMS\VisualEditor\Tests\Functional\ViewHelpers\Render;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\Event\BeforeRenderTextEvent;
use TYPO3\CMS\Fluid\ViewHelpers\Render\TextViewHelper as CoreTextViewHelper;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\CMS\VisualEditor\BackwardsCompatibility\ViewHelpers\Render\TextViewHelper as LegacyTextViewHelper;
use TYPO3\CMS\VisualEditor\EditableResult\EditableResult;
use TYPO3\CMS\VisualEditor\EditableResult\Input;
use TYPO3\CMS\VisualEditor\EditableResult\RichText;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;
use TYPO3Tests\BlogExample\Domain\Model\Blog;

final class TextViewHelperTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/blog_example',
        'typo3conf/ext/visual_editor',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
        $this->get(SiteWriter::class)->write('typo3-localhost', [
            'rootPageId' => 1,
            'base' => 'https://typo3.localhost/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'navigationTitle' => 'English',
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'flag' => 'global',
                ],
            ],
        ]);
    }

    #[Test]
    #[DataProvider('backendRichTextRenderingDataProvider')]
    public function richTextIsRenderedInBackendContext(string $value, string $expected): void
    {
        $request = new ServerRequest(
            'https://typo3-2.localhost/',
            'GET',
            'php://input',
            [],
            ['HTTP_HOST' => 'typo3-2.localhost'],
        );
        $request = $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request))
            ->withAttribute('language', (new NullSite())->getDefaultLanguage());
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource(
            "{record -> f:render.text(field: 'bodytext')}",
        );
        $view = new TemplateView($context);
        $view->assign('record', $this->get(RecordFactory::class)->createRawRecord('tt_content', [
            'uid' => 1,
            'pid' => 1,
            'CType' => 'text',
            'bodytext' => $value,
        ]));

        $result = $view->render();

        self::assertInstanceOf(RichText::class, $result);
        self::assertSame($expected, $result->getHtml());
    }

    public static function backendRichTextRenderingDataProvider(): Generator
    {
        yield 'sanitizes unsafe attributes' => [
            'value' => '<img src="/image.png" onerror="alert(1)">',
            'expected' => '<img src="/image.png">',
        ];
        yield 'transforms internal links' => [
            'value' => '<a href="t3://page?uid=1">Root</a>',
            'expected' => '<a href="https://typo3.localhost/">Root</a>',
        ];
    }

    /**
     * @param class-string<Input|RichText> $resultClass
     */
    #[Test]
    #[DataProvider('textRenderingDataProvider')]
    public function rendersTextWithAndWithoutEditMode(string $field, string $value, string $expectedHtml, bool $editMode, string $resultClass): void
    {
        $request = $this->createFrontendRequest($editMode);
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('<f:render.text record="{record}" field="{field}" />');
        self::assertSame(
            class_exists(BeforeRenderTextEvent::class) ? CoreTextViewHelper::class : LegacyTextViewHelper::class,
            $context->getViewHelperResolver()->resolveViewHelperClassName('f', 'render.text'),
        );
        $record = $this->get(RecordFactory::class)->createRawRecord('tt_content', [
            'uid' => 1,
            'pid' => 1,
            'CType' => 'text',
            $field => $value,
        ]);

        for ($iteration = 0; $iteration < 2; $iteration++) {
            $view = new TemplateView($context);
            $view->assignMultiple(['record' => $record, 'field' => $field]);
            $result = $view->render();
            self::assertInstanceOf($resultClass, $result);
            self::assertInstanceOf(EditableResult::class, $result);
            self::assertSame($value, $result->value);
            self::assertSame($value === '', $result->isEmpty());
            self::assertNotSame('', $result->getName());
            if ($editMode) {
                self::assertStringContainsString('table="tt_content"', $result->getHtml());
                self::assertStringContainsString('uid="1"', $result->getHtml());
                self::assertStringContainsString('field="' . $field . '"', $result->getHtml());
                self::assertStringContainsString($resultClass === RichText::class ? '<ve-editable-rich-text' : '<ve-editable-text', $result->getHtml());
            } else {
                self::assertSame($expectedHtml, $result->getHtml());
            }
        }

        $assets = $this->get(AssetCollector::class);
        self::assertSame($editMode, $assets->hasInlineJavaScript('veLangInfo'));
    }

    public static function textRenderingDataProvider(): Generator
    {
        foreach ([false, true] as $editMode) {
            $mode = $editMode ? 'edit' : 'display';
            yield $mode . ' input' => ['field' => 'header', 'value' => '<b>Title</b>', 'expectedHtml' => '&lt;b&gt;Title&lt;/b&gt;', 'editMode' => $editMode, 'resultClass' => Input::class];
            yield $mode . ' multiline' => ['field' => 'rowDescription', 'value' => "Line 1\nLine 2", 'expectedHtml' => "Line 1<br />\nLine 2", 'editMode' => $editMode, 'resultClass' => Input::class];
            yield $mode . ' rich text' => ['field' => 'bodytext', 'value' => '<p>Text</p>', 'expectedHtml' => '<p>Text</p>', 'editMode' => $editMode, 'resultClass' => RichText::class];
            yield $mode . ' empty input' => ['field' => 'header', 'value' => '', 'expectedHtml' => '', 'editMode' => $editMode, 'resultClass' => Input::class];
        }
    }

    #[Test]
    public function rendersPageInformationAndExtbaseModels(): void
    {
        $request = $this->createFrontendRequest(true);
        $page = $request->getAttribute('frontend.page.information');
        $model = new Blog();
        $model->setPid(1);
        $model->setTitle('Blog');
        foreach ([$page, $model] as $record) {
            $context = $this->get(RenderingContextFactory::class)->create([], $request);
            $context->getTemplatePaths()->setTemplateSource('{record -> f:render.text(field: "title")}');
            $view = new TemplateView($context);
            $view->assign('record', $record);
            $result = $view->render();
            self::assertInstanceOf(Input::class, $result);
            self::assertStringContainsString('<ve-editable-text', $result->getHtml());
            self::assertSame($record instanceof PageInformation ? 'Root' : 'Blog', $result->value);
        }
    }

    #[Test]
    public function doesNotMakeFieldsEditableWithoutPermissions(): void
    {
        $request = $this->createFrontendRequest(true);
        $GLOBALS['BE_USER']->user['admin'] = 0;
        $GLOBALS['BE_USER']->groupData['tables_modify'] = '';
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('{record -> f:render.text(field: "header")}');
        $view = new TemplateView($context);
        $view->assign('record', $this->get(RecordFactory::class)->createRawRecord('tt_content', [
            'uid' => 1, 'pid' => 1, 'CType' => 'text', 'header' => '<b>Title</b>',
        ]));
        $result = $view->render();
        self::assertInstanceOf(Input::class, $result);
        self::assertSame('&lt;b&gt;Title&lt;/b&gt;', $result->getHtml());
    }

    #[Test]
    public function eventResultStopsSubsequentListeners(): void
    {
        if (!class_exists(BeforeRenderTextEvent::class)) {
            self::markTestSkipped('Requires the Core BeforeRenderTextEvent change.');
        }

        $this->get('service_container')->set('after-visual-editor-text', static function (): never {
            self::fail('The Visual Editor result must stop propagation.');
        });
        $this->get(ListenerProvider::class)->addListener(BeforeRenderTextEvent::class, 'after-visual-editor-text');
        $request = $this->createFrontendRequest(true);
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource('{record -> f:render.text(field: "header")}');
        $view = new TemplateView($context);
        $view->assign('record', $this->get(RecordFactory::class)->createRawRecord('tt_content', [
            'uid' => 1, 'pid' => 1, 'CType' => 'text', 'header' => 'Title',
        ]));
        self::assertInstanceOf(Input::class, $view->render());
    }

    /**
     * @param array<string, int> $properties
     */
    #[Test]
    #[DataProvider('localizedRecordDataProvider')]
    public function preservesLocalizedAndVersionedRecordIdentity(array $properties, string $expectedUid): void
    {
        $context = $this->get(RenderingContextFactory::class)->create([], $this->createFrontendRequest(true));
        $context->getTemplatePaths()->setTemplateSource('{record -> f:render.text(field: "header")}');
        $view = new TemplateView($context);
        $view->assign('record', $this->get(RecordFactory::class)->createRawRecord('tt_content', [
            'uid' => 1, 'pid' => 1, 'CType' => 'text', 'header' => 'Title', ...$properties,
        ]));
        $result = $view->render();
        self::assertInstanceOf(Input::class, $result);
        self::assertStringContainsString('uid="' . $expectedUid . '"', $result->getHtml());
        self::assertStringContainsString('fieldPositionId="tt_content:1:header"', $result->getHtml());
    }

    public static function localizedRecordDataProvider(): Generator
    {
        yield 'translated' => ['properties' => ['_LOCALIZED_UID' => 11], 'expectedUid' => '11'];
        yield 'workspace' => ['properties' => ['_ORIG_uid' => 12], 'expectedUid' => '12'];
        yield 'translated workspace' => ['properties' => ['_LOCALIZED_UID' => 11, '_ORIG_uid' => 12], 'expectedUid' => '11'];
    }

    #[Test]
    public function missingOptionalFieldReturnsNull(): void
    {
        $context = $this->get(RenderingContextFactory::class)->create([], $this->createFrontendRequest(false));
        $context->getTemplatePaths()->setTemplateSource('{record -> f:render.text(field: "missing", optional: true)}');
        $view = new TemplateView($context);
        $view->assign('record', $this->get(RecordFactory::class)->createRawRecord('tt_content', [
            'uid' => 1, 'pid' => 1, 'CType' => 'text', 'header' => 'Title',
        ]));
        self::assertNull($view->render());
    }

    #[Test]
    public function missingRequestUsesDefaultCoreRendering(): void
    {
        if (!class_exists(BeforeRenderTextEvent::class)) {
            self::markTestSkipped('Requires the Core BeforeRenderTextEvent change.');
        }

        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource('{record -> f:render.text(field: "header")}');
        $view = new TemplateView($context);
        $view->assign('record', $this->get(RecordFactory::class)->createRawRecord('tt_content', [
            'uid' => 1, 'pid' => 1, 'CType' => 'text', 'header' => '<b>Title</b>',
        ]));
        self::assertSame('&lt;b&gt;Title&lt;/b&gt;', (string)$view->render());
    }

    private function createFrontendRequest(bool $editMode): ServerRequest
    {
        $site = $this->get(SiteFinder::class)->getSiteByPageId(1);
        $page = new PageInformation();
        $page->setId(1);

        $pageRecord = $this->get(ConnectionPool::class)->getConnectionForTable('pages')->select(['*'], 'pages', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($pageRecord);
        $page->setPageRecord($pageRecord);
        $typoScript = new FrontendTypoScript(new RootNode(), [], [], []);
        $typoScript->setSetupTree(new RootNode());
        $typoScript->setSetupArray(['lib.' => ['parseFunc_RTE.' => ['htmlSanitize' => '1']]]);
        $typoScript->setConfigArray([]);

        $request = new ServerRequest('https://typo3.localhost/');
        $request = $request
            ->withQueryParams($editMode ? ['editMode' => '1'] : [])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withAttribute('routing', new PageArguments(1, '0', []))
            ->withAttribute('frontend.page.information', $page)
            ->withAttribute('frontend.typoscript', $typoScript);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }
}
