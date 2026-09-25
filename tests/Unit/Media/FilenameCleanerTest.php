<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Unit\Media;

use Miji\Toolbox\Modules\Media\FilenameCleaner;
use PHPUnit\Framework\TestCase;

final class FilenameCleanerTest extends TestCase {
	/**
	 * @dataProvider names
	 */
	public function test_cleans(string $input, string $expected): void {
		$this->assertSame($expected, FilenameCleaner::clean($input));
	}

	public static function names(): array {
		return [
			'german umlauts' => ['Größe Übersicht Ä.JPG', 'groesse-uebersicht-ae.jpg'],
			'capital sharp s' => ['STRAẞE.png', 'strasse.png'],
			'accents' => ['Café  Crème (1).png', 'cafe-creme-1.png'],
			'other latin letters' => ['Æble Øl Åse.png', 'aeble-ol-ase.png'],
			'dots and underscores stay' => ['Mein_Bild.final.v2.jpeg', 'mein_bild.final.v2.jpeg'],
			'runs of dashes' => ['---Hello---World---.pdf', 'hello-world.pdf'],
			'special characters' => ['a&b=c?#.png', 'a-b-c.png'],
			'emoji' => ['emoji 😀 test.png', 'emoji-test.png'],
			'non-latin script' => ['日本語.jpg', 'file.jpg'],
			'uppercase extension' => ['photo.JPG', 'photo.jpg'],
			'no extension' => ['README', 'readme'],
			'core double-extension guard is kept' => ['shell.php_.jpg', 'shell.php_.jpg'],
			'already clean' => ['logo-2026.svg', 'logo-2026.svg'],
			'dash before extension' => ['Bild -.png', 'bild.png'],
		];
	}

	public function test_decomposed_umlauts_from_macos(): void {
		if (!class_exists(\Normalizer::class)) {
			$this->markTestSkipped('intl extension not available');
		}
		$this->assertSame('groesse.jpg', FilenameCleaner::clean("Gro\u{0308}\u{00DF}e.jpg"));
	}

	/**
	 * @dataProvider names
	 */
	public function test_is_idempotent(string $input): void {
		$once = FilenameCleaner::clean($input);
		$this->assertSame($once, FilenameCleaner::clean($once));
	}

	/**
	 * @dataProvider names
	 */
	public function test_result_only_contains_safe_characters(string $input): void {
		$this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]*$/', FilenameCleaner::clean($input));
	}
}
