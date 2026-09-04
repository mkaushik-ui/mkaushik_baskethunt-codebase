<?php
declare(strict_types=1);

namespace SOI\Tests\Editor;

use SOI\Core\Auth;
use SOI\Core\Content\ContentStore;
use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentException;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Content\Html;

/**
 * Task A4-T09: Security Regression & Audit Suite.
 * Automated security test verifying XSS sanitization, CSRF token checks,
 * IDOR protection, and SSRF prevention.
 */
final class SecurityTests
{
    public static function run(): array
    {
        $passed = 0;
        $failed = 0;
        $messages = [];

        $assert = static function (bool $condition, string $label) use (&$passed, &$failed, &$messages): void {
            if ($condition) {
                $passed++;
                $messages[] = "  PASS  [Security] {$label}";
            } else {
                $failed++;
                $messages[] = "  FAIL  [Security] {$label}";
            }
        };

        // 1. XSS Audit Across Integrated Block Outputs
        $xssPayload = Document::parse([
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'data' => ['text' => 'Safe <script>alert("p-xss")</script> <img src=x onerror="alert(\'img-xss\')"> <a href="javascript:alert(\'a-xss\')">Click</a>']
                ],
                [
                    'type' => 'heading',
                    'data' => ['text' => '<script>alert("h-xss")</script>Heading <iframe src="https://evil.com"></iframe>', 'level' => 2]
                ],
                [
                    'type' => 'faq',
                    'data' => ['items' => [['question' => '<script>q</script>Q?', 'answer' => '<svg onload=alert(1)>Ans']]]
                ],
                [
                    'type' => 'accordion',
                    'data' => ['items' => [['title' => '<script>acc</script>Sec', 'content' => '<body onload=alert(1)>Body']]]
                ],
                [
                    'type' => 'steps',
                    'data' => ['items' => [['title' => '<script>step</script>S1', 'content' => '<object data="x"></object>StepBody']]]
                ],
                [
                    'type' => 'cards',
                    'data' => ['items' => [[
                        'title' => '<script>card</script>T',
                        'description' => '<embed src="x">D',
                        'linkUrl' => 'javascript:alert("card-link")',
                        'imageUrl' => 'javascript:alert("card-img")'
                    ]]]
                ],
                [
                    'type' => 'statusBadge',
                    'data' => ['status' => '"><script>alert(1)</script><div class="', 'label' => '<script>badge</script>Badge']
                ],
                [
                    'type' => 'keyValues',
                    'data' => ['title' => '<script>kv</script>Title', 'items' => [['key' => '<script>k</script>K', 'value' => '<script>v</script>V']]]
                ],
                [
                    'type' => 'kbd',
                    'data' => ['keys' => ['<script>kbd</script>Ctrl', 'Alt'], 'description' => '<script>desc</script>Desc']
                ],
                [
                    'type' => 'code',
                    'data' => ['code' => '<script>alert("code")</script>', 'language' => 'html']
                ],
                [
                    'type' => 'codeGroup',
                    'data' => ['items' => [['label' => 'JS', 'code' => '<script>console.log("cg")</script>', 'language' => 'js']]]
                ],
                [
                    'type' => 'legacy',
                    'data' => ['html' => '<div>Ok HTML</div><script>alert("legacy")</script><iframe src="javascript:alert(1)"></iframe><img src=x onerror=alert(1)>']
                ],
            ]
        ]);

        $rendered = DocumentRenderer::render($xssPayload, false);

        // Assertions for XSS sanitization
        $assert(!str_contains($rendered, '<script>alert'), 'Stripped all unescaped user-injected <script> tags from rendered blocks');
        $assert(!str_contains($rendered, 'alert("p-xss")') && !str_contains($rendered, 'alert("h-xss")'), 'Stripped executable alert calls from paragraph and heading content');
        $assert(!str_contains($rendered, '<iframe'), 'Stripped all <iframe> elements');
        $assert(!str_contains($rendered, '<object'), 'Stripped all <object> elements');
        $assert(!str_contains($rendered, '<embed'), 'Stripped all <embed> elements');
        $assert(!str_contains($rendered, 'onerror='), 'Stripped all onerror= inline event handlers');
        $assert(!str_contains($rendered, 'onload='), 'Stripped all onload= inline event handlers');
        $assert(!str_contains($rendered, 'href="javascript:'), 'Stripped javascript: URLs from href attributes');
        $assert(!str_contains($rendered, 'src="javascript:'), 'Stripped javascript: URLs from src attributes');

        // Verify code blocks encode script tags rather than rendering them
        $assert(str_contains($rendered, '&lt;script&gt;alert'), 'Code block content is HTML escaped safely');

        // 2. CSRF Token Checks Verification
        $_SESSION['soi_csrf'] = 'valid_secret_csrf_token_123';
        $assert(Auth::verifyCsrf('valid_secret_csrf_token_123'), 'Auth::verifyCsrf accepts valid session token');
        $assert(!Auth::verifyCsrf('invalid_token_456'), 'Auth::verifyCsrf rejects mismatched token');
        $assert(!Auth::verifyCsrf(''), 'Auth::verifyCsrf rejects empty token string');
        unset($_SESSION['soi_csrf']);
        $assert(!Auth::verifyCsrf('valid_secret_csrf_token_123'), 'Auth::verifyCsrf rejects request when session token is missing');

        // 3. IDOR & Entity Whitelisting Verification
        $assert(ContentStore::tableFor('page') === 'pages', 'ContentStore::tableFor allows valid "page" entity');
        $assert(ContentStore::tableFor('post') === 'posts', 'ContentStore::tableFor allows valid "post" entity');

        $idorExceptionThrown = false;
        try {
            ContentStore::tableFor("page'; DROP TABLE soi_users; --");
        } catch (DocumentException $e) {
            $idorExceptionThrown = true;
        }
        $assert($idorExceptionThrown, 'ContentStore::tableFor throws DocumentException on SQL injection attempt');

        $unknownEntityThrown = false;
        try {
            ContentStore::tableFor('users');
        } catch (DocumentException $e) {
            $unknownEntityThrown = true;
        }
        $assert($unknownEntityThrown, 'ContentStore::tableFor throws DocumentException on unwhitelisted entity access');

        // 4. SSRF Prevention & URL Sanitization Verification
        $assert(Html::sanitizeUrl('javascript:alert(1)') === '', 'Sanitizer blocks javascript: protocol');
        $assert(Html::sanitizeUrl('data:text/html,<script>alert(1)</script>') === '', 'Sanitizer blocks data: protocol');
        $assert(Html::sanitizeUrl('file:///etc/passwd') === '', 'Sanitizer blocks file:// protocol');
        $assert(Html::sanitizeUrl('gopher://127.0.0.1:11211/') === '', 'Sanitizer blocks gopher:// protocol');
        $assert(Html::sanitizeUrl('http://169.254.169.254/latest/meta-data/') === '', 'Sanitizer blocks cloud metadata endpoint (169.254.169.254)');
        $assert(Html::sanitizeUrl('https://example.com/api/v1') === 'https://example.com/api/v1', 'Sanitizer allows valid https URL');
        $assert(Html::sanitizeUrl('/media/view/photo.jpg') === '/media/view/photo.jpg', 'Sanitizer allows relative media paths');

        return [
            'passed' => $passed,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }
}
