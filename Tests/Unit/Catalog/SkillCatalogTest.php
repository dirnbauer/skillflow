<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Webconsulting\Skillflow\Domain\Catalog\SkillCatalog;
use Webconsulting\Skillflow\Domain\Catalog\SkillFacts;
use Webconsulting\Skillflow\Service\SkillCatalogRepository;

final class SkillCatalogTest extends TestCase
{
    private const string BUNDLED = __DIR__ . '/../../../Resources/Private/Catalog/typo3-skills.yaml';

    private function catalog(): SkillCatalog
    {
        $catalog = SkillCatalogRepository::fromArray([
            'repository' => 'https://github.com/acme/skills/',
            'title' => 'Acme skills',
            'license' => 'MIT',
            'install' => [
                'all' => 'git clone https://github.com/acme/skills.git && ./install.sh',
                'skill' => 'ln -s ~/skills/skills/{skill} {dir}/{skill}',
                'pack' => 'for s in {skills}; do ln -s ~/skills/skills/$s {dir}/$s; done',
            ],
            'clients' => [
                ['key' => 'claude', 'label' => 'Claude Code', 'directory' => '~/.claude/skills', 'call' => '/{skill}'],
                ['key' => 'codex', 'label' => 'Codex', 'directory' => '~/.codex/skills', 'call' => '${skill}'],
                ['key' => 'broken', 'label' => 'No folder'],
            ],
            'audiences' => ['site' => 'Site builders'],
            'groups' => [
                'second' => ['title' => 'Second', 'page' => 'second'],
                'first' => ['title' => 'First'],
            ],
            'skills' => [
                'b-skill' => ['group' => 'second', 'audience' => ['site']],
                'a-skill' => ['group' => 'second', 'superfeature' => true, 'origin' => 'vendored', 'evals' => ['cases' => 4, 'reviewed' => 2], 'updated' => '2026-10-31'],
                'lonely' => ['group' => 'unknown', 'updated' => 1793404800],
                'c-skill' => ['group' => 'first', 'origin' => 'something else'],
            ],
        ]);
        self::assertNotNull($catalog);

        return $catalog;
    }

    public function testGroupsKeepTheFileOrderAndTheirMembers(): void
    {
        $catalog = $this->catalog();

        self::assertSame(['second', 'first'], array_keys($catalog->groups));
        self::assertSame(1, $catalog->groups['second']->position);
        self::assertSame(['b-skill', 'a-skill'], $catalog->groups['second']->skills);
        self::assertSame(['a-skill'], $catalog->relatedSkills('b-skill'));
        self::assertSame([], $catalog->relatedSkills('lonely'));
        self::assertSame('', $catalog->skill('lonely')?->group, 'an unknown group is no group');
    }

    public function testFactsAreTypedAndRankedShowcaseFirst(): void
    {
        $catalog = $this->catalog();
        $a = $catalog->skill('a-skill');
        self::assertInstanceOf(SkillFacts::class, $a);
        self::assertTrue($a->superfeature);
        self::assertTrue($a->isVendored());
        self::assertSame([4, 2, '2026-10-31'], [$a->evals, $a->evalsReviewed, $a->updated]);
        self::assertSame(SkillFacts::ORIGIN_MAINTAINED, $catalog->skill('c-skill')?->origin);
        self::assertSame('2026-10-31', $catalog->skill('lonely')?->updated, 'YAML timestamps become days');

        $ranks = array_map(static fn(SkillFacts $facts): int => $facts->rank, $catalog->skills);
        asort($ranks);
        self::assertSame(['a-skill', 'b-skill', 'c-skill', 'lonely'], array_keys($ranks));
    }

    public function testCommandsAreWrittenForTheChosenAgent(): void
    {
        $catalog = $this->catalog();

        self::assertSame(['claude', 'codex'], array_map(static fn($client): string => $client->key, $catalog->clients));
        self::assertSame('ln -s ~/skills/skills/a-skill ~/.claude/skills/a-skill', $catalog->installSkillCommand('a-skill'));
        self::assertSame('ln -s ~/skills/skills/a-skill ~/.codex/skills/a-skill', $catalog->installSkillCommand('a-skill', 'codex'));
        self::assertSame('for s in b-skill a-skill; do ln -s ~/skills/skills/$s ~/.claude/skills/$s; done', $catalog->installPackCommand('second'));
        self::assertSame('', $catalog->installPackCommand('nope'));
        self::assertSame('$a-skill', $catalog->client('codex')?->callFor('a-skill'));
    }

    public function testCoversTheRepositoryWithAndWithoutGitSuffix(): void
    {
        $catalog = $this->catalog();

        self::assertTrue($catalog->covers('https://github.com/acme/skills'));
        self::assertTrue($catalog->covers('https://github.com/Acme/skills.git'));
        self::assertFalse($catalog->covers('https://github.com/acme/other'));
        self::assertFalse($catalog->covers(''));
        self::assertNull(SkillCatalogRepository::fromArray(['skills' => []]));
    }

    public function testTheBundledCatalogueDescribesTheTypo3SkillsCollection(): void
    {
        $data = Yaml::parseFile(self::BUNDLED);
        self::assertIsArray($data);
        $catalog = SkillCatalogRepository::fromArray($data);
        self::assertNotNull($catalog);

        self::assertTrue($catalog->covers('https://github.com/dirnbauer/typo3-skills.git'));
        self::assertCount(12, $catalog->groups);
        self::assertCount(61, $catalog->skills);
        self::assertCount(10, array_filter($catalog->skills, static fn(SkillFacts $facts): bool => $facts->superfeature));
        self::assertCount(20, array_filter($catalog->skills, static fn(SkillFacts $facts): bool => $facts->isVendored()));
        foreach ($catalog->groups as $group) {
            self::assertGreaterThanOrEqual(3, count($group->skills), $group->key);
            self::assertStringStartsWith('LLL:EXT:skillflow/Resources/Private/Language/catalog.xlf:group.', $group->title);
        }
        foreach ($catalog->skills as $facts) {
            self::assertNotSame('', $facts->owner, $facts->name);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $facts->updated, $facts->name);
            self::assertSame([], array_diff($facts->audience, array_keys($catalog->audiences)), $facts->name);
        }
        self::assertSame('', $catalog->skill('legal-impressum')?->group, 'not featured, no topic');
        $testing = $catalog->skill('typo3-testing');
        self::assertNotNull($testing);
        self::assertSame('Netresearch', $testing->owner);
        self::assertSame('https://github.com/netresearch/typo3-testing-skill', $testing->upstream);
    }

    public function testEveryCatalogueLabelExistsInTheLabelFile(): void
    {
        $data = Yaml::parseFile(self::BUNDLED);
        self::assertIsArray($data);
        $labels = (string)file_get_contents(__DIR__ . '/../../../Resources/Private/Language/catalog.xlf');
        array_walk_recursive($data, static function (mixed $value) use ($labels): void {
            if (is_string($value) && str_starts_with($value, 'LLL:EXT:skillflow/Resources/Private/Language/catalog.xlf:')) {
                $key = substr($value, strlen('LLL:EXT:skillflow/Resources/Private/Language/catalog.xlf:'));
                self::assertStringContainsString('id="' . $key . '"', $labels, $key);
            }
        });
    }
}
