<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

if (!function_exists('e')) {
    function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('rich_text_html')) {
    function rich_text_html(mixed $value): string
    {
        $html = trim((string) $value);
        if ($html === '') return '';
        if (!preg_match('/<\/?[a-z][^>]*>/i', $html)) {
            $paragraphs = preg_split('/\R{2,}/', $html) ?: [$html];
            return implode('', array_map(
                static fn(string $paragraph): string => '<p>' . nl2br(e(trim($paragraph)), false) . '</p>',
                array_filter($paragraphs, static fn(string $paragraph): bool => trim($paragraph) !== '')
            ));
        }

        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];
        if (!class_exists(DOMDocument::class)) {
            $clean = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><blockquote><a>');
            return preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', $clean) ?? '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="rich-text-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('rich-text-root');
        if (!$root) return '';

        $elements = [];
        foreach ($root->getElementsByTagName('*') as $element) $elements[] = $element;
        foreach ($elements as $element) {
            $tag = strtolower($element->nodeName);
            if (!in_array($tag, $allowed, true)) {
                if (in_array($tag, ['script', 'style', 'iframe', 'object'], true)) {
                    $element->parentNode?->removeChild($element);
                    continue;
                }
                $parent = $element->parentNode;
                if (!$parent) continue;
                while ($element->firstChild) $parent->insertBefore($element->firstChild, $element);
                $parent->removeChild($element);
                continue;
            }

            $attributes = [];
            foreach ($element->attributes ?? [] as $attribute) $attributes[] = $attribute->name;
            foreach ($attributes as $attribute) {
                if ($tag !== 'a' || $attribute !== 'href') $element->removeAttribute($attribute);
            }
            if ($tag === 'a') {
                $href = trim($element->getAttribute('href'));
                if (!preg_match('#^(https?://|mailto:|/(?!/)|\#)#i', $href)) {
                    $element->removeAttribute('href');
                } elseif (preg_match('#^https?://#i', $href)) {
                    $element->setAttribute('target', '_blank');
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        $output = '';
        foreach ($root->childNodes as $child) $output .= $document->saveHTML($child);
        return trim($output);
    }
}

if (!function_exists('base_url_path')) {
    function base_url_path(): string
    {
        if (PHP_SAPI === 'cli') return '';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if (str_ends_with($basePath, '/public')) $basePath = substr($basePath, 0, -7);
        return $basePath;
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return base_url_path() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('absolute_url')) {
    function absolute_url(string $path = '/'): string
    {
        $configured = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if (str_ends_with($configured, '/public')) $configured = substr($configured, 0, -7);
        if ($configured !== '') return $configured . '/' . ltrim($path, '/');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        return $scheme . '://' . $host . url($path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $url = url('/assets/' . $relative);
        if (str_contains($relative, '..')) return $url;
        $absolute = dirname(__DIR__, 2) . '/public/assets/' . str_replace('\\', '/', $relative);
        $modified = is_file($absolute) ? filemtime($absolute) : false;
        return $modified === false ? $url : $url . '?v=' . $modified;
    }
}

if (!function_exists('upload_asset')) {
    function upload_asset(string $path): string { return url('/uploads/' . ltrim($path, '/')); }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string { return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">'; }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        $oldInput = View::shared('oldInput', []);
        return is_array($oldInput) ? ($oldInput[$key] ?? $default) : $default;
    }
}

if (!function_exists('error')) {
    function error(string $key): ?string
    {
        $validationErrors = View::shared('validationErrors', []);
        $value = is_array($validationErrors) ? ($validationErrors[$key] ?? null) : null;
        return is_string($value) ? $value : null;
    }
}

if (!function_exists('platform_theme_style')) {
    /** @param array<string,mixed> $settings */
    function platform_theme_style(array $settings): string
    {
        $color = static function(mixed $value, string $fallback): string {
            $value = (string) $value;
            return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtoupper($value) : $fallback;
        };
        $primary = $color($settings['primary_color'] ?? null, '#D20A16');
        $secondary = $color($settings['secondary_color'] ?? null, '#080808');
        $accent = $color($settings['accent_color'] ?? null, '#AA8A46');
        return "--brand-red:{$primary};--brand-red-dark:{$primary};--green-800:{$primary};--green-700:{$primary};--brand-black:{$secondary};--green-950:{$secondary};--brand-gold:{$accent}";
    }
}

if (!function_exists('platform_theme_classes')) {
    /** @param array<string,mixed> $settings */
    function platform_theme_classes(array $settings): string
    {
        $mode = in_array(($settings['color_mode'] ?? ''), ['light','dark','automatic'], true) ? $settings['color_mode'] : 'light';
        $font = in_array(($settings['typography'] ?? ''), ['editorial','modern','system'], true) ? $settings['typography'] : 'editorial';
        $buttons = in_array(($settings['button_style'] ?? ''), ['rounded','soft','square'], true) ? $settings['button_style'] : 'rounded';
        return 'theme-mode-' . $mode . ' theme-font-' . $font . ' theme-buttons-' . $buttons;
    }
}

if (!function_exists('platform_link_href')) {
    function platform_link_href(mixed $value, string $fallback = '/'): string
    {
        $value = trim((string) $value);
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) return $value;
        }
        if ($value !== '' && str_starts_with($value, '/') && !str_starts_with($value, '//') && !preg_match('/[\x00-\x1F\x7F\\\\]/', $value)) {
            return url($value);
        }
        return url($fallback);
    }
}

if (!function_exists('platform_link_is_external')) {
    function platform_link_is_external(string $href): bool
    {
        return preg_match('#^https?://#i', $href) === 1;
    }
}
