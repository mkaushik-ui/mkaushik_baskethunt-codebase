<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class ApiEndpointBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    public function type(): string
    {
        return 'apiEndpoint';
    }

    public function label(): string
    {
        return 'API Endpoint';
    }

    public function group(): string
    {
        return 'technical';
    }

    public function description(): string
    {
        return 'HTTP API endpoint with parameters and response preview';
    }

    public function keywords(): string
    {
        return 'api endpoint http rest get post put delete request response';
    }

    public function icon(): string
    {
        return '🔌';
    }

    public function editorType(): string
    {
        return 'apiEndpoint';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'method' => 'GET',
            'endpoint' => '/api/v1/resource',
            'title' => 'Get Resource',
            'description' => 'Retrieves resource information',
            'parameters' => [],
            'requestBody' => '',
            'responseBody' => '',
        ];
    }

    public function sanitize(array $data): array
    {
        $method = strtoupper(trim((string) ($data['method'] ?? 'GET')));
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            $method = 'GET';
        }
        $endpoint = Html::plainText((string) ($data['endpoint'] ?? ''));
        $title = Html::plainText((string) ($data['title'] ?? ''));
        $description = Html::sanitizeInline((string) ($data['description'] ?? ''), 4000);
        $auth = Html::plainText((string) ($data['auth'] ?? ''));

        $params = [];
        if (is_array($data['parameters'] ?? null)) {
            foreach (array_values($data['parameters']) as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $pName = Html::plainText((string) ($p['name'] ?? ''));
                if ($pName === '') {
                    continue;
                }
                $params[] = [
                    'name' => $pName,
                    'type' => Html::plainText((string) ($p['type'] ?? 'string')),
                    'required' => !empty($p['required']),
                    'description' => Html::plainText((string) ($p['description'] ?? '')),
                ];
                if (count($params) >= 30) {
                    break;
                }
            }
        }

        return [
            'method' => $method,
            'endpoint' => Html::clampText($endpoint, 300),
            'title' => Html::clampText($title, 200),
            'description' => $description,
            'auth' => Html::clampText($auth, 100),
            'parameters' => $params,
            'requestBody' => Html::clampText((string) ($data['requestBody'] ?? ''), 20000),
            'responseBody' => Html::clampText((string) ($data['responseBody'] ?? ''), 20000),
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $method = strtolower($d['method']);
        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<div class="kc-block kc-block-api kc-api-' . Html::escape($method) . '"' . $dataAttr . '>';
        $html .= '<div class="kc-api-header">';
        $html .= '<span class="kc-api-method kc-api-badge-' . Html::escape($method) . '">' . Html::escape($d['method']) . '</span>';
        $html .= '<code class="kc-api-path">' . Html::escape($d['endpoint']) . '</code>';
        $html .= '</div>';

        if ($d['title'] !== '') {
            $html .= '<div class="kc-api-title">' . Html::escape($d['title']) . '</div>';
        }
        if (Html::plainText($d['description']) !== '') {
            $html .= '<div class="kc-api-desc">' . $d['description'] . '</div>';
        }
        if ($d['auth'] !== '') {
            $html .= '<div class="kc-api-auth"><span class="kc-api-meta-label">Auth:</span> ' . Html::escape($d['auth']) . '</div>';
        }

        if ($d['parameters'] !== []) {
            $html .= '<div class="kc-api-params"><h5>Parameters</h5><table class="kc-api-params-table"><thead><tr><th>Name</th><th>Type</th><th>Required</th><th>Description</th></tr></thead><tbody>';
            foreach ($d['parameters'] as $p) {
                $html .= '<tr>';
                $html .= '<td><code>' . Html::escape($p['name']) . '</code></td>';
                $html .= '<td>' . Html::escape($p['type']) . '</td>';
                $html .= '<td>' . ($p['required'] ? 'Yes' : 'No') . '</td>';
                $html .= '<td>' . Html::escape($p['description']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        if ($d['requestBody'] !== '') {
            $html .= '<div class="kc-api-req"><h5>Request Body</h5><pre><code>' . Html::escape($d['requestBody']) . '</code></pre></div>';
        }
        if ($d['responseBody'] !== '') {
            $html .= '<div class="kc-api-resp"><h5>Response Body</h5><pre><code>' . Html::escape($d['responseBody']) . '</code></pre></div>';
        }

        $html .= '</div>';

        return $html;
    }
}
