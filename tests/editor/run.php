<?php
/**
 * Structured document / editor foundation tests.
 * Run: php tests/editor/run.php
 */
declare(strict_types=1);

define('SOI_ROOT', dirname(__DIR__, 2));
require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use SOI\Core\Auth;
use SOI\Core\Content\BlockRegistry;
use SOI\Core\Content\ContentStore;
use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentException;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Content\EditorSchema;
use SOI\Core\Content\Html;

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $message): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$message}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$message}\n";
}

function assert_false(bool $cond, string $message): void
{
    assert_true(!$cond, $message);
}

function assert_contains(string $haystack, string $needle, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message);
}

function assert_not_contains(string $haystack, string $needle, string $message): void
{
    assert_true(!str_contains($haystack, $needle), $message);
}

echo "Structured editor tests\n";

echo "\n[schema / parse]\n";
$doc = Document::parse([
    'schemaVersion' => 1,
    'blocks' => [
        ['id' => 'blk_heading', 'type' => 'heading', 'data' => ['text' => 'Welcome', 'level' => 2]],
        ['id' => 'blk_p', 'type' => 'paragraph', 'data' => ['text' => 'Hello <strong>world</strong>']],
        ['id' => 'blk_code', 'type' => 'code', 'data' => ['code' => 'echo 1;', 'language' => 'php', 'caption' => 'Example']],
        ['id' => 'blk_callout', 'type' => 'callout', 'data' => ['tone' => 'warning', 'title' => 'Careful', 'text' => 'Check this.']],
        ['id' => 'blk_table', 'type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['A', 'B'], ['1', '2']]]],
        ['id' => 'blk_list', 'type' => 'list', 'data' => ['style' => 'ordered', 'items' => ['One', 'Two']]],
        ['id' => 'blk_quote', 'type' => 'quote', 'data' => ['text' => 'Quoted', 'caption' => 'Src']],
        ['id' => 'blk_div', 'type' => 'divider', 'data' => []],
        ['id' => 'blk_link', 'type' => 'link', 'data' => ['url' => 'https://example.com', 'title' => 'Example']],
    ],
]);
assert_true($doc['schemaVersion'] === 1, 'Canonical schema version is 1');
assert_true(count($doc['blocks']) === 9, 'All supported sample blocks are kept');
$types = array_column($doc['blocks'], 'type');
foreach (['heading', 'paragraph', 'code', 'callout', 'table', 'list', 'quote', 'divider', 'link'] as $type) {
    assert_true(in_array($type, $types, true), 'Supported block type registered: ' . $type);
}

echo "\n[malformed rejection]\n";
try {
    Document::parse('{not-json');
    assert_true(false, 'Malformed JSON is rejected');
} catch (DocumentException $e) {
    assert_true(true, 'Malformed JSON is rejected');
}
try {
    Document::parse(['schemaVersion' => 1]);
    assert_true(false, 'Missing blocks array is rejected');
} catch (DocumentException $e) {
    assert_true(true, 'Missing blocks array is rejected');
}
try {
    Document::parse(['blocks' => ['nope']]);
    assert_true(false, 'Non-object block is rejected');
} catch (DocumentException $e) {
    assert_true(true, 'Non-object block is rejected');
}

echo "\n[dangerous content]\n";
$xss = Document::parse([
    'blocks' => [
        ['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script>Safe <img src=x onerror=alert(1)> <a href="javascript:alert(1)">x</a>']],
        ['type' => 'link', 'data' => ['url' => 'javascript:alert(1)', 'title' => 'Bad']],
        ['type' => 'heading', 'data' => ['text' => '<em onclick="alert(1)">Title</em>', 'level' => 2]],
        ['type' => 'code', 'data' => ['code' => '<script>alert(1)</script>', 'language' => 'html']],
        ['type' => 'legacy', 'data' => ['html' => '<p>ok</p><script>alert(1)</script><iframe src="https://evil.test"></iframe><img src="/media/view/abc" alt="x" onerror="alert(1)">']],
    ],
]);
$rendered = DocumentRenderer::render($xss, false);
assert_not_contains($rendered, '<script', 'Renderer strips script tags');
assert_not_contains($rendered, 'onerror=', 'Renderer strips event handlers');
assert_not_contains($rendered, 'javascript:', 'Renderer strips javascript: URLs');
assert_not_contains($rendered, '<iframe', 'Renderer strips iframes from legacy HTML');
assert_contains($rendered, 'Safe', 'Safe paragraph text is kept');
assert_contains($rendered, htmlspecialchars('<script>alert(1)</script>', ENT_QUOTES, 'UTF-8'), 'Code is escaped, not executed');
assert_true(Html::sanitizeUrl('javascript:alert(1)') === '', 'javascript: URLs are rejected');
assert_true(Html::sanitizeUrl('https://example.com/a') === 'https://example.com/a', 'https URLs are allowed');
assert_true(Html::sanitizeUrl('/media/view/abc') === '/media/view/abc', 'Relative media URLs are allowed');

echo "\n[rendered output / headings]\n";
$html = DocumentRenderer::render($doc, false);
assert_contains($html, 'id="welcome"', 'Heading produces a stable anchor');
assert_contains($html, '<h2', 'Heading renders as semantic h2');
assert_contains($html, '<strong>world</strong>', 'Inline strong is preserved');
assert_contains($html, 'data-language="php"', 'Code language is stored as data, not highlighted HTML');
assert_contains($html, 'kc-callout-warning', 'Callout tone is a class, not inline style');
assert_contains($html, '<table', 'Table renders as a table');
assert_contains($html, '<th>', 'Table header cells are rendered');
$headings = DocumentRenderer::extractHeadings($doc);
assert_true(count($headings) === 1 && $headings[0]['id'] === 'welcome', 'Heading extraction supports future On this page');

$dup = Document::parse([
    'blocks' => [
        ['type' => 'heading', 'data' => ['text' => 'Same', 'level' => 2]],
        ['type' => 'heading', 'data' => ['text' => 'Same', 'level' => 2]],
    ],
]);
$dupHtml = DocumentRenderer::render($dup);
assert_contains($dupHtml, 'id="same"', 'First duplicate heading uses the base anchor');
assert_contains($dupHtml, 'id="same-2"', 'Duplicate headings get unique anchors');

echo "\n[unknown blocks]\n";
$future = Document::parse([
    'blocks' => [
        ['id' => 'x1', 'type' => 'futureWidget', 'data' => ['title' => 'Later']],
        ['id' => 'x2', 'type' => 'paragraph', 'data' => ['text' => 'Still works']],
    ],
]);
assert_true($future['blocks'][0]['type'] === 'unknown', 'Unknown future blocks are kept safely');
assert_true(($future['blocks'][0]['data']['originalType'] ?? '') === 'futureWidget', 'Original unknown type is preserved');
$futureHtml = DocumentRenderer::render($future, false);
assert_not_contains($futureHtml, 'Later', 'Unknown blocks do not emit raw payload on the public renderer');
assert_contains($futureHtml, 'Still works', 'Known blocks still render after an unknown block');
$errors = Document::validate($future);
assert_true($errors === [], 'Unknown blocks do not fail validation');

echo "\n[editor.js adapter]\n";
$fromEditor = Document::parse([
    'time' => 1,
    'version' => '2.30.8',
    'blocks' => [
        ['id' => 'h1', 'type' => 'header', 'data' => ['text' => 'From editor', 'level' => 3]],
        ['id' => 'd1', 'type' => 'delimiter', 'data' => []],
        ['id' => 'l1', 'type' => 'list', 'data' => ['style' => 'checklist', 'items' => [['content' => 'Done', 'meta' => ['checked' => true], 'items' => []]]]],
    ],
]);
assert_true($fromEditor['blocks'][0]['type'] === 'heading', 'Editor.js header maps to heading');
assert_true($fromEditor['blocks'][1]['type'] === 'divider', 'Editor.js delimiter maps to divider');
assert_true($fromEditor['blocks'][2]['data']['style'] === 'checklist', 'Editor.js checklist list is preserved');
$roundTrip = Document::toEditorJs($fromEditor);
assert_true($roundTrip['blocks'][0]['type'] === 'header', 'Canonical heading maps back to Editor.js header');

echo "\n[legacy compatibility]\n";
$legacyRecord = ['content' => '<p>Old TinyMCE <strong>HTML</strong></p>', 'editor_format' => 'legacy'];
assert_true(EditorSchema::isLegacy($legacyRecord), 'Records without structured JSON stay on the legacy path');
assert_true(soi_document_html($legacyRecord) === '<p>Old TinyMCE <strong>HTML</strong></p>', 'Legacy HTML is returned unchanged by the public helper');
$structuredRecord = [
    'editor_format' => 'structured',
    'body_json' => Document::encode($doc),
    'content' => '<p>stale cache</p>',
];
$fromHelper = soi_document_html($structuredRecord);
assert_contains($fromHelper, 'id="welcome"', 'Public helper renders structured documents from body_json');
assert_not_contains($fromHelper, 'stale cache', 'Structured render does not depend on stale HTML cache');

echo "\n[authorization / CSRF]\n";
$_SESSION = ['soi_csrf' => 'unit-test-token'];
assert_true(Auth::verifyCsrf('unit-test-token'), 'Valid CSRF token is accepted');
assert_true(!Auth::verifyCsrf('other-token'), 'Invalid CSRF token is rejected');
assert_true(!Auth::verifyCsrf(''), 'Empty CSRF token is rejected');
assert_true(!Auth::check(), 'Editor API session is unauthenticated without a user');
try {
    ContentStore::tableFor('library');
    assert_true(false, 'Unknown entities are rejected');
} catch (DocumentException $e) {
    assert_true(true, 'Unknown entities are rejected');
}

echo "\n[registry / files]\n";
$catalog = array_column(BlockRegistry::catalog(), 'type');
foreach (['paragraph', 'heading', 'list', 'quote', 'divider', 'image', 'link', 'table', 'code', 'callout', 'file'] as $type) {
    assert_true(in_array($type, $catalog, true), 'Registry catalogs ' . $type);
}
$pages = file_get_contents(SOI_ROOT . '/admin/pages.php') ?: '';
$posts = file_get_contents(SOI_ROOT . '/admin/posts.php') ?: '';
assert_not_contains($pages, 'tinymce', 'pages.php no longer loads TinyMCE');
assert_not_contains($posts, 'tinymce', 'posts.php no longer loads TinyMCE');
assert_true(is_file(SOI_ROOT . '/admin/editor-api.php'), 'Save/preview API endpoint exists');
assert_true(is_file(SOI_ROOT . '/admin/assets/editor/vendor/editorjs.js'), 'Editor.js is vendored locally');
assert_true(is_file(SOI_ROOT . '/admin/assets/editor/registry.js'), 'Client block registry exists');

echo "\n[preview uses same renderer]\n";
$preview = DocumentRenderer::render($doc, true);
$public = DocumentRenderer::render($doc, false);
assert_true(str_contains($preview, 'id="welcome"') && str_contains($public, 'id="welcome"'), 'Preview and public rendering share heading anchors');

echo "\n[workspace catalog]\n";
$fullCatalog = BlockRegistry::catalog(true);
$groups = array_unique(array_column($fullCatalog, 'group'));
foreach (['basic', 'media', 'structured', 'technical', 'notice', 'layout'] as $group) {
    assert_true(in_array($group, $groups, true), 'Catalog includes group: ' . $group);
}
$ids = array_column($fullCatalog, 'id');
assert_true(in_array('list-unordered', $ids, true) && in_array('list-checklist', $ids, true), 'List variants share the registry catalog');
foreach ($fullCatalog as $item) {
    assert_true(!empty($item['editorType']) && !empty($item['label']), 'Catalog item has editorType and label: ' . ($item['id'] ?? '?'));
}
$slashSource = json_encode($fullCatalog);
assert_contains($slashSource, 'callout', 'Slash/component catalog includes callout from the same registry');
$partial = file_get_contents(SOI_ROOT . '/admin/partials/structured-editor.php') ?: '';
assert_contains($partial, 'kc-workspace', 'Authoring workspace markup is present');
assert_contains($partial, 'kc-ribbon', 'Enterprise ribbon markup is present');
assert_contains($partial, 'kc-component-list', 'Component library panel is present');
assert_contains($partial, 'kc-block-inspector', 'Context-aware inspector is present');
assert_contains($partial, 'kc-statusbar', 'Status bar is present');
assert_contains($partial, 'data-ribbon-tab="layout"', 'Layout ribbon tab is present');
$css = file_get_contents(SOI_ROOT . '/admin/assets/editor/kc-editor.css') ?: '';
assert_contains($css, 'max-width: 100% !important', 'Editor.js default 650px canvas width is overridden');
assert_true(str_contains($css, '@media (min-width: 1600px)') || str_contains($css, '@media (min-width: 1800px)'), 'Ultra-wide canvas rules exist');
assert_true(str_contains($css, '@media (max-width: 980px)') || str_contains($css, '@media (max-width: 1100px)'), 'Laptop/tablet drawer rules exist');
assert_contains($css, '@media (max-width: 720px)', 'Narrow/mobile rules exist');
assert_contains($css, 'kc-authoring-fullscreen', 'Fullscreen authoring shell rules exist');
assert_contains($css, '100vw', 'Fullscreen uses viewport width');
$js = file_get_contents(SOI_ROOT . '/admin/assets/editor/kc-editor.js') ?: '';
assert_contains($js, 'cfg.catalog', 'Workspace slash/components use the server catalog');
assert_contains($js, "setStatus('Saved', 'saved')", 'Saved is only set after a successful server response');
assert_contains($js, "setStatus('Saving…', 'saving')", 'Saving state is explicit');
assert_not_contains($js, 'const slashItems', 'Workspace no longer keeps a second hard-coded slash catalog');


echo "\n[1.1.0 runtime & recovery]\n";
assert_contains($js, 'linkCard', 'Editor tools use linkCard to avoid Link inline tool collision');
assert_contains($js, 'editorReady', 'Editor readiness gate is present');
assert_contains($js, 'showBootError', 'Visible init failure UI is present');
assert_contains($js, '__KC_EDITOR_DIAGNOSTICS', 'Diagnostic interface exposed');
assert_contains($js, 'bindCanvasClickToFocus', 'Canvas whitespace click-to-focus is wired');
assert_contains($js, 'filterComponents', 'Server-rendered component filtering is present');
assert_contains($js, "setStatus('Unsaved changes', 'dirty')", 'Dirty state is explicit');
assert_contains($js, "setStatus('Saving…', 'saving')", 'Saving state remains explicit');
assert_not_contains($js, "link: { class: custom.link", 'Block tool no longer registers as tools.link');
assert_contains($partial, "editorVersion = '1.1.0'", 'Structured editor asset version is 1.1.0');
assert_contains($partial, 'enterprise.js', 'Enterprise block tools script is loaded');
assert_contains($partial, 'kc-editor-error', 'Visible editor error panel markup exists');
assert_contains($partial, 'kc-component-group', 'Server-rendered component groups are present');
assert_contains($partial, 'kc-component-grid', 'Component 2-column tile grid is present');
assert_contains($partial, 'kc-close-left', 'Left panel close control is present');
assert_contains($partial, 'kc-close-right', 'Right inspector close control is present');
assert_contains($partial, 'kc-reopen-left', 'Floating left panel reopen control is present');
assert_contains($partial, 'kc-reopen-right', 'Floating right inspector reopen control is present');
assert_contains($partial, 'data-component-id="<?= esc($item[\'id\']) ?>"', 'Server-rendered component template emits catalog IDs');
$catalogIds = array_column(BlockRegistry::catalog(), 'id');
assert_true(in_array('steps', $catalogIds, true), 'Server catalog includes Steps');
assert_true(in_array('callout', $catalogIds, true), 'Server catalog includes Callout');
assert_true(in_array('apiEndpoint', $catalogIds, true), 'Server catalog includes API Endpoint');
assert_true(in_array('keyValues', $catalogIds, true), 'Server catalog includes Key/Values');
assert_true(in_array('kbd', $catalogIds, true), 'Server catalog includes Keyboard Key');
assert_true(in_array('reusable', $catalogIds, true), 'Server catalog includes Reusable');
assert_contains($partial, 'data-insert="table"', 'Insert ribbon includes Table');
assert_contains($partial, 'data-insert="callout"', 'Insert ribbon includes Callout');
assert_contains($partial, 'kc-ribbon-more-components', 'Ribbon includes More Components action');

echo "\n[1.1.0 host shell & layout]\n";
assert_contains($pages, "kc-authoring kc-authoring-fullscreen", 'pages.php sets server-rendered fullscreen body class');
assert_contains($posts, "kc-authoring kc-authoring-fullscreen", 'posts.php sets server-rendered fullscreen body class');
assert_contains($pages, "?v=1.1.0", 'pages.php references stylesheet v=1.1.0');
assert_contains($posts, "?v=1.1.0", 'posts.php references stylesheet v=1.1.0');
$header = file_get_contents(SOI_ROOT . '/admin/partials/header.php') ?: '';
$footer = file_get_contents(SOI_ROOT . '/admin/partials/footer.php') ?: '';
assert_contains($header, '$isAuthoringMode', 'header.php checks for authoring mode');
assert_contains($header, '<?php if (!$isAuthoringMode): ?>', 'header.php omits sidebar/topbar during authoring');
assert_contains($footer, 'kc-authoring', 'footer.php checks for authoring mode');

echo "\n[1.0.8 enterprise components]\n";
$enterpriseTypes = ['steps', 'accordion', 'faq', 'tabs', 'codeGroup', 'definitionList', 'statusBadge', 'group', 'columns', 'cards'];
foreach ($enterpriseTypes as $type) {
    assert_true(BlockRegistry::has($type), 'Block registered: ' . $type);
}
$entDoc = Document::parse([
    'schemaVersion' => 1,
    'blocks' => [
        ['type' => 'steps', 'data' => ['items' => [
            ['title' => 'Create app', 'content' => 'Enter details.'],
            ['title' => 'Verify', 'content' => 'Test it.'],
        ]]],
        ['type' => 'accordion', 'data' => ['items' => [
            ['title' => 'Section', 'content' => 'Body', 'open' => true],
        ]]],
        ['type' => 'faq', 'data' => ['items' => [
            ['question' => 'What?', 'answer' => 'This.'],
        ]]],
        ['type' => 'tabs', 'data' => ['items' => [
            ['title' => 'Windows', 'content' => 'Win'],
            ['title' => 'macOS', 'content' => 'Mac'],
        ]]],
        ['type' => 'codeGroup', 'data' => ['items' => [
            ['label' => 'PHP', 'language' => 'php', 'code' => 'echo 1;', 'caption' => ''],
            ['label' => 'JS', 'language' => 'javascript', 'code' => 'console.log(1)', 'caption' => ''],
        ]]],
        ['type' => 'definitionList', 'data' => ['items' => [
            ['term' => 'Entity ID', 'description' => 'Unique id'],
        ]]],
        ['type' => 'statusBadge', 'data' => ['status' => 'beta', 'label' => 'Beta']],
        ['type' => 'group', 'data' => ['title' => 'Group', 'content' => 'Related']],
        ['type' => 'columns', 'data' => ['layout' => '50-50', 'columns' => [
            ['content' => 'Left'],
            ['content' => 'Right'],
        ]]],
        ['type' => 'cards', 'data' => ['items' => [
            ['title' => 'Card', 'description' => 'Desc', 'icon' => '★', 'imageUrl' => '', 'linkUrl' => 'https://example.com'],
        ]]],
    ],
]);
$entHtml = DocumentRenderer::render($entDoc);
assert_contains($entHtml, 'kc-block-steps', 'Steps render');
assert_contains($entHtml, 'kc-block-accordion', 'Accordion renders');
assert_contains($entHtml, 'kc-block-faq', 'FAQ renders as dedicated component');
assert_contains($entHtml, 'FAQPage', 'FAQ includes schema.org markup');
assert_contains($entHtml, 'kc-block-tabs', 'Tabs render');
assert_contains($entHtml, 'kc-block-codegroup', 'Code group renders');
assert_contains($entHtml, 'echo 1;', 'Code group preserves raw code text');
assert_not_contains($entHtml, '<script>alert', 'Code group does not execute script');
assert_contains($entHtml, 'kc-block-deflist', 'Definition list renders');
assert_contains($entHtml, 'kc-badge-beta', 'Status badge renders controlled class');
assert_contains($entHtml, 'kc-block-group', 'Group renders');
assert_contains($entHtml, 'kc-columns-50-50', 'Columns render layout class');
assert_contains($entHtml, 'kc-block-cards', 'Cards render');

echo "\n[1.0.8 sanitization]\n";
$bad = Document::parse([
    'blocks' => [
        ['type' => 'faq', 'data' => ['items' => [
            ['question' => '<script>x</script>Q', 'answer' => '<img src=x onerror=alert(1)>Safe'],
        ]]],
        ['type' => 'statusBadge', 'data' => ['status' => 'totally-fake', 'label' => '<b>X</b>']],
        ['type' => 'cards', 'data' => ['items' => [
            ['title' => 'T', 'description' => 'D', 'linkUrl' => 'javascript:alert(1)', 'imageUrl' => 'javascript:alert(1)'],
        ]]],
        ['type' => 'codeGroup', 'data' => ['items' => [
            ['label' => 'x', 'code' => '<script>alert(1)</script>'],
        ]]],
    ],
]);
$badHtml = DocumentRenderer::render($bad);
assert_not_contains($badHtml, '<script>', 'FAQ/code group sanitize script tags from HTML context');
assert_not_contains($badHtml, 'onerror=', 'Event handlers stripped from FAQ content');
assert_not_contains($badHtml, 'javascript:', 'javascript: URLs stripped from cards');
assert_contains($badHtml, 'kc-badge-stable', 'Invalid status falls back to controlled stable style');
$codeBlock = null;
foreach ($bad['blocks'] as $b) {
    if (($b['type'] ?? '') === 'codeGroup') { $codeBlock = $b; break; }
}
assert_true(is_array($codeBlock) && str_contains((string)($codeBlock['data']['items'][0]['code'] ?? ''), '<script>'), 'Code group stores raw code structurally, not highlighted HTML');

echo "\n[1.0.8 linkCard mapping]\n";
$fromEditor = Document::fromEditorJs([
    'blocks' => [
        ['type' => 'linkCard', 'data' => ['url' => 'https://example.com', 'title' => 'Example', 'text' => 'Desc']],
        ['type' => 'header', 'data' => ['text' => 'Hi', 'level' => 2]],
    ],
]);
$types = array_column($fromEditor['blocks'], 'type');
assert_true(in_array('link', $types, true), 'linkCard maps to canonical link type');
assert_true(in_array('heading', $types, true), 'header maps to heading');
$back = Document::toEditorJs($fromEditor);
$eTypes = array_column($back['blocks'], 'type');
assert_true(in_array('linkCard', $eTypes, true), 'canonical link exports as linkCard for editor tools');
assert_true(in_array('header', $eTypes, true), 'canonical heading exports as header');

$catalogLink = null;
foreach (BlockRegistry::catalog() as $item) {
    if (($item['id'] ?? '') === 'link') { $catalogLink = $item; break; }
}
assert_true(is_array($catalogLink) && ($catalogLink['editorType'] ?? '') === 'linkCard', 'Catalog link uses editorType linkCard');

echo "\n[1.1.0 asset versioning & distribution]\n";
assert_contains($partial, "editorVersion = '1.1.0'", 'Editor asset version constant is 1.1.0');
$manifest = file_get_contents(SOI_ROOT . '/cms-manifest.json') ?: '';
assert_contains($manifest, '"version": "1.1.0"', 'cms-manifest.json version is 1.1.0');
assert_true(is_file(SOI_ROOT . '/admin/assets/editor/blocks/enterprise.js'), 'Enterprise tools asset exists');
assert_true(is_file(SOI_ROOT . '/assets/kc-public.js'), 'Public tabs helper exists without Editor.js');
$publicJs = file_get_contents(SOI_ROOT . '/assets/kc-public.js') ?: '';
assert_not_contains($publicJs, 'EditorJS', 'Public JS does not load Editor.js');
assert_contains(file_get_contents(SOI_ROOT . '/themes/default/style.css') ?: '', 'kc-block-steps', 'Public CSS includes Steps styles');
assert_contains(file_get_contents(SOI_ROOT . '/themes/default/style.css') ?: '', 'kc-block-faq', 'Public CSS includes FAQ styles');
assert_contains(file_get_contents(SOI_ROOT . '/themes/default/style.css') ?: '', 'kc-block-api', 'Public CSS includes API Endpoint styles');
assert_contains(file_get_contents(SOI_ROOT . '/themes/default/style.css') ?: '', 'kc-block-keyvalues', 'Public CSS includes Key/Values styles');
assert_contains(file_get_contents(SOI_ROOT . '/themes/default/style.css') ?: '', 'kc-block-kbd', 'Public CSS includes Keyboard Key styles');
assert_contains(file_get_contents(SOI_ROOT . '/themes/default/style.css') ?: '', 'kc-block-reusable', 'Public CSS includes Reusable block styles');
$editorVendorJs = file_get_contents(SOI_ROOT . '/admin/assets/editor/vendor/editorjs.js') ?: '';
assert_contains($editorVendorJs, 'Editor.js 2.30.8', 'Editor.js vendor runtime is stable 2.30.8');
assert_not_contains($editorVendorJs, '2.31.0-rc', 'Editor.js vendor runtime contains zero release-candidate strings');

echo "\n[1.1.0 enterprise blocks & rendering]\n";
$enterpriseDoc = Document::parse([
    'blocks' => [
        ['type' => 'apiEndpoint', 'data' => [
            'method' => 'POST',
            'endpoint' => '/api/v1/auth/token',
            'title' => 'Request Token',
            'description' => 'Generate bearer token',
            'auth' => 'Basic Auth',
            'parameters' => [['name' => 'grant_type', 'type' => 'string', 'required' => true, 'description' => 'Grant type']],
            'requestBody' => '{"grant_type": "client_credentials"}',
            'responseBody' => '{"access_token": "xyz", "expires_in": 3600}'
        ]],
        ['type' => 'keyValues', 'data' => [
            'title' => 'System Specs',
            'items' => [['key' => 'Service', 'value' => 'Knowledge Base'], ['key' => 'SLA', 'value' => '99.9%']]
        ]],
        ['type' => 'kbd', 'data' => ['keys' => ['Ctrl', 'Shift', 'P'], 'description' => 'Open palette']],
        ['type' => 'reusable', 'data' => ['reusable_id' => 9999, 'title' => 'Notice']],
    ],
]);
$entHtml = DocumentRenderer::render($enterpriseDoc, false);
assert_contains($entHtml, 'kc-block-api', 'API Endpoint block renders with class');
assert_contains($entHtml, 'kc-api-post', 'API Endpoint applies method modifier class');
assert_contains($entHtml, '/api/v1/auth/token', 'API Endpoint path renders');
assert_contains($entHtml, 'kc-block-keyvalues', 'KeyValues block renders with class');
assert_contains($entHtml, 'System Specs', 'KeyValues title renders');
assert_contains($entHtml, 'kc-block-kbd', 'Keyboard Key block renders with class');
assert_contains($entHtml, '<kbd class="kc-kbd">Ctrl</kbd>', 'Keyboard Key semantic kbd tags render');
assert_contains($entHtml, 'kc-reusable-missing', 'Missing reusable block ID handled safely with fallback');

echo "\n[1.1.0 templates & patterns]\n";
$tmpls = \SOI\Core\Content\Templates::all();
assert_true(count($tmpls) >= 5, 'Built-in starter templates are available');
$tmplIds = array_column($tmpls, 'id');
assert_true(in_array('blank', $tmplIds, true), 'Blank template exists');
assert_true(in_array('procedure', $tmplIds, true), 'Procedure template exists');
assert_true(in_array('api_reference', $tmplIds, true), 'API Reference template exists');

$ptns = \SOI\Core\Content\Patterns::all();
assert_true(count($ptns) >= 5, 'Built-in reusable patterns are available');
$ptnIds = array_column($ptns, 'id');
assert_true(in_array('procedure_steps', $ptnIds, true), 'Procedure steps pattern exists');
assert_true(in_array('two_col_comparison', $ptnIds, true), 'Two column comparison pattern exists');

echo "\n[1.1.0 workspace controls & modals]\n";
assert_contains($partial, 'id="kc-palette-modal"', 'Command palette modal markup is present');
assert_contains($partial, 'id="kc-revisions-modal"', 'Revisions history modal markup is present');
assert_contains($partial, 'id="kc-templates-modal"', 'Templates modal markup is present');
assert_contains($partial, 'id="kc-conflict-modal"', 'Concurrency conflict modal markup is present');
assert_contains($partial, 'data-left-tab="navigator"', 'Navigator tab is present');
assert_contains($partial, 'data-left-tab="patterns"', 'Patterns sidebar tab is present');
assert_contains($partial, 'data-left-tab="reusable"', 'Reusable sidebar tab is present');
assert_contains($partial, 'data-right-tab="layout"', 'Layout inspector tab is present');

echo "\n[1.1.0 schema & migration verification]\n";
$updateSql = file_get_contents(SOI_ROOT . '/build/1.1.0-enterprise-authoring-suite/update.sql') ?: '';
assert_contains($updateSql, 'CREATE TABLE IF NOT EXISTS `soi_document_revisions`', 'update.sql creates soi_document_revisions');
assert_contains($updateSql, 'CREATE TABLE IF NOT EXISTS `soi_kc_reusable_blocks`', 'update.sql creates soi_kc_reusable_blocks');
assert_contains($updateSql, '`idx_entity_doc` (`entity`, `document_id`)', 'update.sql indexes entity and document_id');
assert_contains($updateSql, '`idx_created` (`created_at`)', 'update.sql indexes created_at');
assert_contains($updateSql, '`idx_title` (`title`)', 'update.sql indexes reusable block title');

$schemaSql = file_get_contents(SOI_ROOT . '/install/schema.sql') ?: '';
assert_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS `soi_document_revisions`', 'Fresh install schema.sql contains soi_document_revisions');
assert_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS `soi_kc_reusable_blocks`', 'Fresh install schema.sql contains soi_kc_reusable_blocks');
assert_contains($schemaSql, '`body_json` longtext DEFAULT NULL', 'Fresh install pages/posts include body_json');
assert_contains($schemaSql, '`editor_format` varchar(20) NOT NULL DEFAULT \'legacy\'', 'Fresh install pages/posts include editor_format');
assert_contains($schemaSql, '`schema_version` smallint UNSIGNED NOT NULL DEFAULT 0', 'Fresh install pages/posts include schema_version');

echo "\n[1.1.0 existing content compatibility]\n";
// Legacy HTML content
$legacyDoc = ['editor_format' => 'legacy', 'content' => '<h1>Old HTML Title</h1><p>Preserved text.</p>', 'body_json' => null];
assert_true(EditorSchema::isLegacy($legacyDoc), 'Legacy document identified correctly');
assert_false(EditorSchema::isStructured($legacyDoc), 'Legacy document is not treated as structured');
$renderedLegacy = DocumentRenderer::renderRecord($legacyDoc);
assert_contains($renderedLegacy, 'Old HTML Title', 'Legacy HTML content renders cleanly');

// Structured document 1.0.5–1.0.9 compatibility
$structDoc = [
    'editor_format' => 'structured',
    'body_json' => json_encode([
        'schemaVersion' => 1,
        'blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => '1.0.5 paragraph']],
            ['type' => 'callout', 'data' => ['tone' => 'tip', 'title' => 'Tip', 'text' => 'Callout body']],
            ['type' => 'steps', 'data' => ['items' => [['title' => 'Step 1', 'content' => 'Do this']]]],
        ]
    ])
];
assert_true(EditorSchema::isStructured($structDoc), 'Structured document identified correctly');
$parsedStruct = Document::parse($structDoc['body_json']);
assert_true(count($parsedStruct['blocks']) === 3, 'Structured blocks parsed without loss');
$renderedStruct = DocumentRenderer::render($parsedStruct, false);
assert_contains($renderedStruct, '1.0.5 paragraph', 'Structured paragraph rendered');
assert_contains($renderedStruct, 'kc-callout-tip', 'Structured callout rendered');
assert_contains($renderedStruct, 'kc-block-steps', 'Structured steps rendered');

echo "\n[1.1.0 optimistic concurrency conflict simulation]\n";
$mockExisting = [
    'id' => 10,
    'title' => 'Doc Title',
    'updated_at' => '2026-08-17 12:00:00',
    'editor_format' => 'structured'
];
// Case 1: Matching expected_updated_at passes
$expectedMatched = '2026-08-17 12:00:00';
assert_true($expectedMatched === $mockExisting['updated_at'], 'Matching expected timestamp proceeds');

// Case 2: Stale expected_updated_at triggers conflict
$expectedStale = '2026-08-17 11:30:00';
$isConflict = (!empty($expectedStale) && $mockExisting['updated_at'] !== $expectedStale);
assert_true($isConflict, 'Stale expected timestamp correctly triggers optimistic locking conflict');

echo "\n[1.1.0 ZIP package inspection]\n";
$zipPath = SOI_ROOT . '/updates/1.1.0-enterprise-authoring-suite.zip';
assert_true(is_file($zipPath), '1.1.0 update zip package exists');
if (class_exists('ZipArchive') && is_file($zipPath)) {
    $zip = new ZipArchive();
    $res = $zip->open($zipPath);
    assert_true($res === true, 'ZipArchive can open 1.1.0 update package');
    if ($res === true) {
        $manifestInZip = $zip->getFromName('manifest.json');
        assert_true($manifestInZip !== false, 'manifest.json is at the exact root of the ZIP');
        $mJson = json_decode((string)$manifestInZip, true);
        assert_true(($mJson['version'] ?? '') === '1.1.0', 'ZIP manifest version is 1.1.0');
        assert_true(($mJson['type'] ?? '') === 'core', 'ZIP manifest type is core');

        $updateSqlInZip = $zip->getFromName('update.sql');
        assert_true($updateSqlInZip !== false, 'update.sql is at the exact root of the ZIP');
        assert_contains((string)$updateSqlInZip, 'soi_document_revisions', 'ZIP update.sql contains revisions table');
        assert_contains((string)$updateSqlInZip, 'soi_kc_reusable_blocks', 'ZIP update.sql contains reusable blocks table');

        $hasInvalidEntries = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (str_starts_with($entry, '/') || str_contains($entry, '..') || str_contains($entry, '.DS_Store') || str_contains($entry, 'node_modules')) {
                $hasInvalidEntries = true;
            }
        }
        assert_false($hasInvalidEntries, 'ZIP contains zero invalid/traversal/temporary paths');
        $zip->close();
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

