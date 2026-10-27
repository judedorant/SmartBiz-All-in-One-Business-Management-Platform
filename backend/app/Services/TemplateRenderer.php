<?php

namespace App\Services;

class TemplateRenderer
{
    public const TOKENS = ['{{id}}', '{{title}}', '{{status}}', '{{trigger}}'];

    public function render(string $template, array $context): string
    {
        return strtr($template, array_combine(self::TOKENS, array_map(fn ($key) => (string) ($context[$key] ?? ''), ['id', 'title', 'status', 'trigger'])));
    }

    public function valid(string $template): bool
    {
        return ! preg_match('/[{}]/', str_replace(self::TOKENS, '', $template));
    }
}
