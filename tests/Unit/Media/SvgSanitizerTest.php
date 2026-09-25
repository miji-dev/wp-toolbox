<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Unit\Media;

use Miji\Toolbox\Modules\Media\SvgSanitizer;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase {
	private const OK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40" width="120" height="40"><rect width="120" height="40" fill="#123456"/><path d="M0 0L10 10"/></svg>';

	public function test_keeps_a_harmless_svg(): void {
		$clean = SvgSanitizer::sanitize(self::OK);

		$this->assertNotNull($clean);
		$this->assertStringContainsString('<rect', $clean);
		$this->assertStringContainsString('fill="#123456"', $clean);
		$this->assertStringContainsString('viewBox="0 0 120 40"', $clean);
	}

	/**
	 * @dataProvider attacks
	 */
	public function test_removes_active_content(string $svg, string $forbidden): void {
		$clean = SvgSanitizer::sanitize($svg);

		$this->assertNotNull($clean);
		$this->assertStringNotContainsStringIgnoringCase($forbidden, $clean);
		$this->assertStringContainsString('<svg', $clean);
	}

	public static function attacks(): array {
		$svg = static fn (string $inner, string $attrs = ''): string => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ' . $attrs . '>' . $inner . '<rect width="1" height="1"/></svg>';

		return [
			'script element' => [$svg('<script>alert(1)</script>'), 'alert'],
			'event handler' => [$svg('', 'onload="alert(1)"'), 'onload'],
			'event handler on child' => [$svg('<circle r="5" onmouseover="alert(1)"/>'), 'onmouseover'],
			'javascript link' => [$svg('<a href="javascript:alert(1)"><text>x</text></a>'), 'javascript:'],
			'javascript xlink' => [$svg('<a xlink:href="javascript:alert(1)"><text>x</text></a>'), 'javascript:'],
			'foreignObject html' => [$svg('<foreignObject><iframe src="https://evil.example"></iframe></foreignObject>'), 'iframe'],
			'external reference' => [$svg('<image xlink:href="https://evil.example/track.png"/>'), 'evil.example'],
			'use of external file' => [$svg('<use href="https://evil.example/x.svg#a"/>'), 'evil.example'],
		];
	}

	public function test_rejects_documents_with_entity_definitions(): void {
		// XXE: an entity that would pull in a server file
		$xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';

		$this->assertNull(SvgSanitizer::sanitize($xxe));
	}

	/**
	 * @dataProvider notSvg
	 */
	public function test_rejects_files_that_are_not_svg(string $content): void {
		$this->assertNull(SvgSanitizer::sanitize($content));
	}

	public static function notSvg(): array {
		return [
			'html' => ['<html><body><script>alert(1)</script></body></html>'],
			'php' => ['<?php system($_GET["c"]); ?>'],
			'broken xml' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect'],
			'empty' => [''],
			'binary' => ["\x89PNG\r\n\x1a\n\0\0\0"],
		];
	}

	/**
	 * @dataProvider sizes
	 */
	public function test_reads_dimensions(string $svg, ?array $expected): void {
		$this->assertSame($expected, SvgSanitizer::dimensions($svg));
	}

	public static function sizes(): array {
		$ns = 'xmlns="http://www.w3.org/2000/svg"';
		return [
			'width and height' => ["<svg $ns width=\"120\" height=\"40\"/>", [120, 40]],
			'px units' => ["<svg $ns width=\"120px\" height=\"40.5px\"/>", [120, 41]],
			'viewBox only' => ["<svg $ns viewBox=\"0 0 300 150\"/>", [300, 150]],
			'percent sizes use viewBox' => ["<svg $ns width=\"100%\" height=\"100%\" viewBox=\"0,0,64,32\"/>", [64, 32]],
			'nothing usable' => ["<svg $ns/>", null],
			'not svg' => ['nope', null],
		];
	}
}
