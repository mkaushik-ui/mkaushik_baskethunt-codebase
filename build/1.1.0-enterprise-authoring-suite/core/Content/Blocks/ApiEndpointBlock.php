<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class ApiEndpointBlock extends AbstractBlock
{
    private const METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    public function type(): string
    {
        return 'apiEndpoint';
    }

    public function label(): string
    {
        return 'API Endpoint';
    }

    public function sanitize(array $data): array
    {
        $method = strtoupper(trim($this->text($data['method'] ?? 'GET', 10)));
        if (!in_array($method, self::METHODS, true)) {
            $method = 'GET';
        }

        $endpoint = trim($this->text($data['endpoint'] ?? $data['path'] ?? '/api', 500));
        $title = trim($this->inline($data['title'] ?? '', 300));
        $description = trim($this->inline($data['description'] ?? '', 2000));
        $auth = trim($this->text($data['auth'] ?? '', 200));

        $params = [];
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            foreach ($data['parameters'] as $p) {
                if (!is_array($p)) continue;
                $params[] = [
                    'name' => trim($this->text($p['name'] ?? '', 100)),
                    'type' => trim($this->text($p['type'] ?? 'string', 50)),
                    'required' => !empty($p['required']),
                    'description' => trim($this->inline($p['description'] ?? $p['desc'] ?? '', 500)),
                ];
            }
        }

        $requestBody = trim($this->text($data['requestBody'] ?? $data['request'] ?? '', 5000));
        $responseBody = trim($this->text($data['responseBody'] ?? $data['response'] ?? '', 5000));

        return [
            'method' => $method,
            'endpoint' => $endpoint,
            'title' => $title,
            'description' => $description,
            'auth' => $auth,
            'parameters' => $params,
            'requestBody' => $requestBody,
            'responseBody' => $responseBody,
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $method = Html::escape($d['method']);
        $endpoint = Html::escape($d['endpoint']);
        $title = $d['title'] !== '' ? '<div class="kc-api-title">' . $d['title'] . '</div>' : '';
        $desc = $d['description'] !== '' ? '<div class="kc-api-desc">' . $d['description'] . '</div>' : '';
        $auth = $d['auth'] !== '' ? '<span class="kc-api-auth">Auth: ' . Html::escape($d['auth']) . '</span>' : '';

        $paramsHtml = '';
        if (!empty($d['parameters'])) {
            $rows = '';
            foreach ($d['parameters'] as $p) {
                $reqBadge = $p['required'] ? '<span class="kc-api-req">Required</span>' : '<span class="kc-api-opt">Optional</span>';
                $rows .= '<tr><td><code>' . Html::escape($p['name']) . '</code></td><td>' . Html::escape($p['type']) . '</td><td>' . $reqBadge . '</td><td>' . $p['description'] . '</td></tr>';
            }
            $paramsHtml = '<div class="kc-api-section"><h4>Parameters</h4><div class="kc-table-wrap"><table class="kc-api-params"><thead><tr><th>Name</th><th>Type</th><th>Required</th><th>Description</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
        }

        $reqHtml = '';
        if ($d['requestBody'] !== '') {
            $reqHtml = '<div class="kc-api-section"><h4>Request payload</h4><pre class="kc-api-code"><code>' . Html::escape($d['requestBody']) . '</code></pre></div>';
        }

        $resHtml = '';
        if ($d['responseBody'] !== '') {
            $resHtml = '<div class="kc-api-section"><h4>Response</h4><pre class="kc-api-code"><code>' . Html::escape($d['responseBody']) . '</code></pre></div>';
        }

        $blockId = Html::escape($block['id'] ?? '');

        return '<div class="kc-block kc-block-api kc-api-' . strtolower($d['method']) . '" data-block-id="' . $blockId . '">'
            . '<div class="kc-api-header">'
            . '<span class="kc-api-method">' . $method . '</span>'
            . '<code class="kc-api-endpoint">' . $endpoint . '</code>'
            . $auth
            . '</div>'
            . $title
            . $desc
            . $paramsHtml
            . $reqHtml
            . $resHtml
            . '</div>';
    }

    public function outlineTitle(array $data): ?string
    {
        $d = $this->sanitize($data);
        return $d['method'] . ' ' . $d['endpoint'];
    }
}
