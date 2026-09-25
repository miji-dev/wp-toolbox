<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Media;

use Normalizer;

/**
 * Turns upload file names into lowercase ASCII slugs: "Größe Übersicht (1).JPG" -> "groesse-uebersicht-1.jpg".
 */
final class FilenameCleaner {
	/** German transliteration first; remove_accents() would turn "ä" into "a". */
	private const MAP = [
		'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', 'ẞ' => 'SS',
	];

	/**
	 * Writes out German umlauts and ß ("Größe" -> "Groesse"); everything else is left as is.
	 */
	public static function transliterate(string $text): string {
		// macOS sends decomposed characters ("o" + combining diaeresis); compose them so the map matches
		if (class_exists(Normalizer::class)) {
			$text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
		}
		return strtr($text, self::MAP);
	}

	public static function clean(string $filename): string {
		$extension = '';
		$name = $filename;
		if (preg_match('/^(.+)\.([A-Za-z0-9]{1,10})$/s', $filename, $m)) {
			$name = $m[1];
			$extension = strtolower($m[2]);
		}

		$name = remove_accents(self::transliterate($name));
		$name = strtolower($name);
		$name = (string) preg_replace('/[^a-z0-9._-]+/', '-', $name);
		$name = (string) preg_replace('/-{2,}/', '-', $name);
		$name = (string) preg_replace('/-*\.-*/', '.', $name);
		// keep a trailing "_": core's guard against double extensions ("shell.php_.jpg") relies on it
		$name = rtrim(ltrim($name, '-._'), '-.');

		if ($name === '') {
			$name = 'file';
		}

		return $extension === '' ? $name : $name . '.' . $extension;
	}
}
