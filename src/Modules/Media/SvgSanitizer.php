<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Media;

use DOMDocument;
use DOMElement;
use Miji\Toolbox\Vendor\enshrined\svgSanitize\Sanitizer;

/**
 * Makes uploaded SVGs safe: removes scripts, event handlers, javascript: links, foreign HTML and
 * references to other servers (enshrined/svg-sanitize, bundled under our namespace).
 */
final class SvgSanitizer {
	/**
	 * @return string|null the cleaned SVG, or null if the input is not a usable SVG
	 */
	public static function sanitize(string $svg): ?string {
		if (!preg_match('/<svg[\s>\/]/i', $svg)) {
			return null;
		}

		$sanitizer = new Sanitizer();
		$sanitizer->removeRemoteReferences(true);
		$clean = $sanitizer->sanitize($svg);

		if (!is_string($clean) || !preg_match('/<svg[\s>\/]/i', $clean)) {
			return null;
		}
		return $clean;
	}

	/**
	 * Pixel size from width/height, or from the viewBox if those are missing or relative.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function dimensions(string $svg): ?array {
		$root = self::root($svg);
		if ($root === null) {
			return null;
		}

		$width = self::length($root->getAttribute('width'));
		$height = self::length($root->getAttribute('height'));
		if ($width !== null && $height !== null) {
			return [$width, $height];
		}

		$box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
		if (is_array($box) && count($box) === 4 && is_numeric($box[2]) && is_numeric($box[3]) && (float) $box[2] > 0 && (float) $box[3] > 0) {
			return [(int) round((float) $box[2]), (int) round((float) $box[3])];
		}
		return null;
	}

	private static function root(string $svg): ?DOMElement {
		if (trim($svg) === '') {
			return null;
		}
		$previous = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$loaded = $dom->loadXML($svg, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$root = $loaded ? $dom->documentElement : null;
		return $root instanceof DOMElement && strtolower($root->localName ?? '') === 'svg' ? $root : null;
	}

	private static function length(string $value): ?int {
		return preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/i', $value, $m) && (float) $m[1] > 0 ? (int) round((float) $m[1]) : null;
	}
}
