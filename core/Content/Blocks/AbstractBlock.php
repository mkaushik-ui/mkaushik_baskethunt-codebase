<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\BlockType;
use SOI\Core\Content\Html;

abstract class AbstractBlock implements BlockType
{
    public function category(): string
    {
        return 'basic';
    }

    public function keywords(): string
    {
        return $this->type() . ' ' . $this->label();
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            'nestable' => false,
            'reusable' => true,
            'wide' => false,
            'interactive' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedParents(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectorSchema(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultData(): array
    {
        return [];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function outlineTitle(array $data): ?string
    {
        return null;
    }

    protected function text(mixed $value, int $max = 50000): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return Html::clampText((string) $value, $max);
    }

    protected function inline(mixed $value, int $max = 50000): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return Html::sanitizeInline((string) $value, $max);
    }

    /**
     * @param list<string> $allowed
     */
    protected function enum(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return in_array($value, $allowed, true) ? $value : $default;
    }

    protected function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (int) $value;
        return $n > 0 ? $n : null;
    }
}
