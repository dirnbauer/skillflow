<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\ViewHelpers;

use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;
use Webconsulting\Skillflow\Service\SkillDocumentLinkResolver;

final class MarkdownViewHelperTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        'webconsulting/skillflow',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templateProvider(): iterable
    {
        yield 'no offset keeps the h1' => ['<sf:markdown source="{body}" />', '<h1>Title</h1>'];
        yield 'headingOffset 1 turns the title into an h2' => ['<sf:markdown source="{body}" headingOffset="1" />', '<h2>Title</h2>'];
        yield 'the offset works with tag content too' => ['<sf:markdown headingOffset="2">{body}</sf:markdown>', '<h3>Title</h3>'];
    }

    #[DataProvider('templateProvider')]
    public function testTheTemplateChoosesTheLevelOfTheFirstHeading(string $template, string $expected): void
    {
        $rendered = $this->render($template, "# Title\n\nText with <b>markup</b>.");

        self::assertStringContainsString($expected, $rendered);
        self::assertStringContainsString('&lt;b&gt;markup&lt;/b&gt;', $rendered);
    }

    public function testTheSkillDetailTemplateStartsTheBodyAtH2(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/SkillDetail/Show.html');

        self::assertSame(1, substr_count($template, '<sf:markdown '));
        self::assertStringContainsString('<sf:markdown source="{skill.body}" headingOffset="1" links="{links}" />', $template);
    }

    public function testTheLinksArgumentRewritesRelativeLinks(): void
    {
        $links = new SkillDocumentLinkResolver(
            'skills/typo3-seo/SKILL.md',
            'https://github.com/o/r/blob/abc/',
            'https://github.com/o/r/raw/abc/',
            static fn(string $path): ?string => $path === 'skills/typo3-solr/SKILL.md' ? '/skill/typo3-solr/' : null,
        );
        $rendered = $this->render(
            '<sf:markdown source="{body}" links="{links}" />',
            '[guide](references/full-guide.md) and [Solr](../typo3-solr/SKILL.md) and [out](https://typo3.org)',
            ['links' => $links],
        );

        self::assertStringContainsString('<a href="https://github.com/o/r/blob/abc/skills/typo3-seo/references/full-guide.md">guide</a>', $rendered);
        self::assertStringContainsString('<a href="/skill/typo3-solr/">Solr</a>', $rendered);
        self::assertStringContainsString('<a href="https://typo3.org">out</a>', $rendered);
    }

    public function testWithoutTheLinksArgumentRelativeLinksStay(): void
    {
        $rendered = $this->render('<sf:markdown source="{body}" />', '[guide](references/full-guide.md)');

        self::assertStringContainsString('<a href="references/full-guide.md">guide</a>', $rendered);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, string $body, array $variables = []): string
    {
        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getViewHelperResolver()->addNamespace('sf', 'Webconsulting\\Skillflow\\ViewHelpers');
        $context->getTemplatePaths()->setTemplateSource($template);
        $view = new TemplateView($context);
        $view->assign('body', $body);
        $view->assignMultiple($variables);

        $rendered = $view->render();
        self::assertIsString($rendered);

        return $rendered;
    }
}
