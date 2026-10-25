<?php

declare(strict_types=1);

namespace App\Support\Images;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * SVG sanitizing by reconstruction: the file is parsed without network
 * access or a DTD (documents with a DOCTYPE or entities are refused), and a
 * new document is built from an allow-list of drawing elements and
 * attributes. Scripts, event handlers, foreignObject, images, links, animation
 * and every external reference (`href` other than `#id`, `url(...)` other
 * than `url(#id)`, `@import`) are gone. The result is still only ever
 * served as an image with a sandboxing CSP (ProjectIconController).
 */
final class Svg
{
    public const string NAMESPACE = 'http://www.w3.org/2000/svg';

    private const string XLINK = 'http://www.w3.org/1999/xlink';

    private const int MAX_NODES = 5000;

    private const array ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'linearGradient', 'radialGradient', 'stop',
        'clipPath', 'mask', 'pattern', 'filter',
        'feGaussianBlur', 'feOffset', 'feBlend', 'feColorMatrix', 'feComposite',
        'feFlood', 'feMerge', 'feMergeNode', 'feMorphology', 'feDropShadow',
    ];

    /** Elements whose text content is kept. */
    private const array TEXT = ['title', 'desc', 'style', 'text', 'tspan'];

    private const array ATTRIBUTES = [
        'id', 'class', 'style', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points', 'transform', 'preserveAspectRatio',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity',
        'clip-path', 'clip-rule', 'mask', 'filter', 'color', 'display', 'visibility', 'overflow',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'patternUnits', 'patternContentUnits', 'patternTransform', 'maskUnits', 'maskContentUnits',
        'clipPathUnits', 'filterUnits', 'primitiveUnits', 'stdDeviation', 'dx', 'dy', 'in', 'in2',
        'result', 'mode', 'operator', 'k1', 'k2', 'k3', 'k4', 'values', 'type', 'radius',
        'flood-color', 'flood-opacity', 'font-family', 'font-size', 'font-weight', 'font-style',
        'text-anchor', 'dominant-baseline', 'letter-spacing', 'media', 'shape-rendering',
        'vector-effect', 'paint-order', 'mix-blend-mode', 'isolation', 'color-interpolation-filters',
        'href',
    ];

    /**
     * @throws InvalidImage
     */
    public static function sanitize(string $svg): string
    {
        if (str_starts_with($svg, "\xEF\xBB\xBF")) {
            $svg = substr($svg, 3);
        }

        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg) === 1 || ! mb_check_encoding($svg, 'UTF-8')) {
            throw InvalidImage::because('The SVG image uses features launchroom doesn’t support.');
        }

        $source = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $source->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $source->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || $root->localName !== 'svg' || ! in_array($root->namespaceURI, [null, self::NAMESPACE], true)) {
            throw InvalidImage::because('This is not an SVG image.');
        }

        $target = new DOMDocument('1.0', 'UTF-8');
        $budget = self::MAX_NODES;
        $copy = self::copy($root, $target, $budget);

        if (! $copy instanceof DOMElement) {
            throw InvalidImage::because('This is not an SVG image.');
        }

        $target->appendChild($copy);
        $out = $target->saveXML($copy);

        if ($out === false || $out === '') {
            throw InvalidImage::because('This is not an SVG image.');
        }

        return $out;
    }

    private static function copy(DOMElement $element, DOMDocument $target, int &$budget): ?DOMElement
    {
        if (--$budget < 0) {
            throw InvalidImage::because('The SVG image is too complex.');
        }

        $name = $element->localName;

        if ($name === null || ! in_array($name, self::ELEMENTS, true) || ! in_array($element->namespaceURI, [null, self::NAMESPACE], true)) {
            return null;
        }

        $copy = $target->createElementNS(self::NAMESPACE, $name);

        foreach ($element->attributes as $attribute) {
            self::copyAttribute($attribute, $copy);
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $nested = self::copy($child, $target, $budget);

                if ($nested instanceof DOMElement) {
                    $copy->appendChild($nested);
                }
            } elseif (self::isText($child) && in_array($name, self::TEXT, true)) {
                $text = $child->textContent ?? '';
                $copy->appendChild($target->createTextNode($name === 'style' ? self::css($text) : $text));
            }
        }

        return $copy;
    }

    private static function copyAttribute(DOMAttr $attribute, DOMElement $copy): void
    {
        $name = $attribute->localName;
        $value = $attribute->value;
        $namespace = $attribute->namespaceURI;

        if ($name === null || ! in_array($name, self::ATTRIBUTES, true) || strlen($value) > 65536) {
            return;
        }

        if ($name === 'href') {
            // Only fragment references to elements in this file (`<use>`).
            if (! in_array($namespace, [null, self::XLINK], true) || preg_match('/^#[A-Za-z_][\w.:-]*$/D', trim($value)) !== 1) {
                return;
            }

            $copy->setAttribute('href', trim($value));

            return;
        }

        if ($namespace !== null) {
            return;
        }

        if ($name === 'style') {
            $value = self::css($value);
        } elseif (! self::safeValue($value)) {
            return;
        }

        $copy->setAttribute($name, $value);
    }

    private static function isText(DOMNode $node): bool
    {
        return $node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE;
    }

    /**
     * No scheme-like values (`javascript:`), and `url()` only for `#id`.
     */
    private static function safeValue(string $value): bool
    {
        if (preg_match('/(?:javascript|vbscript|data)\s*:/i', $value) === 1) {
            return false;
        }

        $stripped = preg_replace('/url\(\s*[\'"]?#[\w.:-]+[\'"]?\s*\)/i', '', $value) ?? '';

        return stripos($stripped, 'url(') === false && ! str_contains($stripped, '\\');
    }

    /**
     * CSS without external references: `@import`, `url()` other than
     * `url(#id)`, escapes (which could spell either) and legacy script hooks
     * are removed declaration by declaration.
     */
    private static function css(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        if (str_contains($css, '\\') || preg_match('/@import|@font-face|expression\s*\(|behavior\s*:|-moz-binding/i', $css) === 1) {
            return '';
        }

        return preg_replace_callback('/[^;{}]*url\([^)]*\)[^;{}]*/i', static fn (array $match): string => self::safeValue($match[0]) ? $match[0] : '', $css) ?? '';
    }
}
