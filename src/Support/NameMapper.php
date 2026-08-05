<?php

declare(strict_types=1);

namespace Vitis\RestDB\OpenApi\Support;

/**
 * Attribute/relationship name mapping between Eloquent's snake_case and the
 * API's property-name style. OpenAPI documents most commonly use camelCase
 * property names (the default here); some use snake_case ('none') or
 * kebab-case. Mirrors the JSON:API adapter's mapper so codegen behaves the
 * same across adapters.
 */
final class NameMapper
{
    public function __construct(private readonly string $style = 'camel') {}

    /** snake_case -> API style. */
    public function toApi(string $name): string
    {
        return match ($this->style) {
            'kebab' => str_replace('_', '-', $name),
            'camel' => lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name)))),
            default => $name,
        };
    }

    /** API style -> snake_case. */
    public function toModel(string $name): string
    {
        return match ($this->style) {
            'kebab' => str_replace('-', '_', $name),
            'camel' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name)),
            default => $name,
        };
    }
}
