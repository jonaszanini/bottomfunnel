<?php

namespace App\Services\Pages;

use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlSanitizer
{
    /** @var list<string> */
    private array $allowedTags = [
        'a', 'article', 'blockquote', 'br', 'button', 'details', 'div', 'em', 'footer',
        'h1', 'h2', 'h3', 'header', 'li', 'nav', 'ol', 'p', 'section', 'span', 'strong',
        'summary', 'ul',
    ];

    /** @var list<string> */
    private array $allowedAttributes = [
        'class', 'id', 'href', 'type', 'hidden', 'aria-label', 'data-outbound', 'data-age-gate', 'data-age-confirm', 'data-popup',
    ];

    public function sanitize(string $html): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"><body>'.$html.'</body>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->walk($body);

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private function walk(DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (! in_array(strtolower($child->tagName), $this->allowedTags, true)) {
                $node->removeChild($child);

                continue;
            }

            $this->cleanAttributes($child);
            $this->walk($child);
        }
    }

    private function cleanAttributes(DOMElement $element): void
    {
        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on') || ! in_array($name, $this->allowedAttributes, true)) {
                $remove[] = $attribute->name;

                continue;
            }
            if ($name === 'href' && ! preg_match('/^#[A-Za-z0-9_-]*$/', $attribute->value)) {
                $remove[] = $attribute->name;
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }
    }
}
