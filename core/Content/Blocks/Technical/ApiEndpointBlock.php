<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: API Endpoint Block Provider (Skeleton).
 */
class ApiEndpointBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'apiEndpoint';
    }

    public function label(): string
    {
        return 'API Endpoint';
    }

    public function category(): string
    {
        return 'technical';
    }

    public function sanitize(array $data): array
    {
        return [
            'method' => $this->enum($data['method'] ?? 'GET', ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], 'GET'),
            'endpoint' => $this->text($data['endpoint'] ?? '/api/v1/endpoint'),
            'title' => $this->inline($data['title'] ?? ''),
            'description' => $this->inline($data['description'] ?? ''),
            'parameters' => is_array($data['parameters'] ?? null) ? $data['parameters'] : [],
            'responseBody' => (string)($data['responseBody'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $method = htmlspecialchars((string)($data['method'] ?? 'GET'), ENT_QUOTES, 'UTF-8');
        $endpoint = htmlspecialchars((string)($data['endpoint'] ?? ''), ENT_QUOTES, 'UTF-8');
        $title = (string)($data['title'] ?? '');

        return "<div class=\"kc-api-endpoint\"><div class=\"kc-api-header\"><span class=\"kc-method-badge kc-method-{$method}\">{$method}</span><code>{$endpoint}</code></div><h4>{$title}</h4></div>";
    }
}
