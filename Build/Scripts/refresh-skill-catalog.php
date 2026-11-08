<?php

declare(strict_types=1);

/*
 * Refreshes the facts of a Skillflow skill catalogue from a checkout of the
 * skill repository it describes:
 *
 *   php Build/Scripts/refresh-skill-catalog.php \
 *       Resources/Private/Catalog/typo3-skills.yaml ~/projects/typo3-skills
 *
 * Facts (rewritten): owner, origin, upstream, license, evals, updated.
 * Editorial fields (kept): the groups and every skill's group, superfeature
 * and audience. A skill folder the catalogue does not list yet is appended
 * without a group and reported; a listed skill without a folder is reported
 * and kept, so nothing editorial is lost by a refresh against an old checkout.
 *
 * Sources in the repository (github.com/dirnbauer/typo3-skills layout):
 * - catalog/skill-sources.json: source_owner and source_url per skill
 * - VENDORED.md, table "Inventory": the skills copied unchanged from upstream
 * - skills/<name>/evals/evals.json: eval cases and their review status
 * - skills/<name>/LICENSE*: the licence of a skill that brings its own
 * - git log: the day of the last commit that touched skills/<name>
 *
 * See Documentation/Search/Catalogue.rst.
 */

use Symfony\Component\Yaml\Yaml;

foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

[$catalogFile, $checkout] = [$argv[1] ?? '', rtrim($argv[2] ?? '', '/')];
if ($catalogFile === '' || !is_file($catalogFile) || $checkout === '' || !is_dir($checkout . '/skills')) {
    fwrite(STDERR, "Usage: php refresh-skill-catalog.php <catalogue.yaml> <checkout of the skill repository>\n");
    exit(2);
}

$catalog = Yaml::parseFile($catalogFile);
if (!is_array($catalog) || !is_array($catalog['skills'] ?? null)) {
    fwrite(STDERR, "$catalogFile is not a skill catalogue (no skills: map)\n");
    exit(2);
}
$repository = strtolower(rtrim((string)($catalog['repository'] ?? ''), '/'));

$sources = [];
$sourcesFile = $checkout . '/catalog/skill-sources.json';
foreach (is_file($sourcesFile) ? (array)json_decode((string)file_get_contents($sourcesFile), true) : [] as $entry) {
    if (is_array($entry) && isset($entry['skill_name'])) {
        $sources[(string)$entry['skill_name']] = $entry;
    }
}

$vendored = [];
$vendoredFile = $checkout . '/VENDORED.md';
if (is_file($vendoredFile) && preg_match_all('/^\|\s*`([a-z0-9-]+)`\s*\|/m', (string)file_get_contents($vendoredFile), $matches) > 0) {
    $vendored = array_fill_keys($matches[1], true);
}

$folders = [];
foreach (glob($checkout . '/skills/*/SKILL.md') ?: [] as $skillFile) {
    $folders[basename(dirname($skillFile))] = dirname($skillFile);
}

$evals = static function (string $folder): array {
    $file = $folder . '/evals/evals.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $cases = is_array($data) ? (array_is_list($data) ? $data : (array)($data['evals'] ?? [])) : [];
    $reviewed = count(array_filter($cases, static fn(mixed $case): bool => is_array($case) && ($case['status'] ?? '') === 'reviewed'));

    return ['cases' => count($cases), 'reviewed' => $reviewed];
};

$license = static function (string $folder): string {
    if (is_file($folder . '/LICENSE-MIT') && is_file($folder . '/LICENSE-CC-BY-SA-4.0')) {
        return 'MIT AND CC-BY-SA-4.0';
    }
    foreach (['LICENSE', 'LICENSE.md', 'LICENSE.txt'] as $name) {
        $text = is_file($folder . '/' . $name) ? (string)file_get_contents($folder . '/' . $name, length: 400) : '';
        if (str_contains($text, 'MIT License')) {
            return 'MIT';
        }
        if (str_contains($text, 'Apache License')) {
            return 'Apache-2.0';
        }
    }

    return '';
};

$updated = static function (string $name) use ($checkout): string {
    $day = trim((string)shell_exec(sprintf('git -C %s log -1 --format=%%cs -- %s 2>/dev/null', escapeshellarg($checkout), escapeshellarg('skills/' . $name))));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : '';
};

foreach (array_diff_key($folders, $catalog['skills']) as $name => $folder) {
    $catalog['skills'][$name] = ['group' => '', 'superfeature' => false, 'audience' => []];
    fwrite(STDERR, "new skill without a group: $name\n");
}
foreach (array_diff_key($catalog['skills'], $folders) as $name => $entry) {
    fwrite(STDERR, "listed skill without a folder (kept): $name\n");
}

foreach ($catalog['skills'] as $name => $entry) {
    $folder = $folders[$name] ?? null;
    if ($folder === null) {
        continue;
    }
    $source = $sources[$name] ?? [];
    $owner = (string)($source['source_owner'] ?? 'webconsulting');
    $sourceUrl = strtolower(rtrim((string)($source['source_url'] ?? ''), '/'));
    $catalog['skills'][$name] = [
        'group' => (string)($entry['group'] ?? ''),
        'superfeature' => (bool)($entry['superfeature'] ?? false),
        'audience' => array_values((array)($entry['audience'] ?? [])),
        'owner' => $owner,
        'origin' => isset($vendored[$name]) ? 'vendored' : 'maintained',
        // The collection's own skills name no upstream, even when they moved
        // here from an older webconsulting repository.
        'upstream' => $owner !== 'webconsulting' && $sourceUrl !== '' && $sourceUrl !== $repository ? (string)$source['source_url'] : '',
        'license' => $license($folder),
        'evals' => $evals($folder),
        'updated' => $updated($name),
    ];
}

$header = <<<'YAML'
# Skill catalogue of github.com/dirnbauer/typo3-skills for Skillflow's skill
# search: topics (groups), showcase skills, audiences, publishers, test
# coverage and install commands. Matched to skills by `repository`.
#
# Editorial, edited by hand: groups, and per skill group, superfeature and
# audience. Facts, rewritten by
#   php Build/Scripts/refresh-skill-catalog.php <this file> <checkout>
# owner, origin, upstream, license, evals, updated.
# Labels are LLL references (Resources/Private/Language/catalog.xlf).
# Format: Documentation/Search/Catalogue.rst

YAML;

file_put_contents($catalogFile, $header . "\n" . Yaml::dump($catalog, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
printf("%d skills, %d vendored, %d with reviewed evals\n", count($catalog['skills']), count(array_filter($catalog['skills'], static fn(array $s): bool => ($s['origin'] ?? '') === 'vendored')), count(array_filter($catalog['skills'], static fn(array $s): bool => ($s['evals']['reviewed'] ?? 0) > 0)));
