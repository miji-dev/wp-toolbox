<?php
// Usage: php bin/check-translations.php <template.pot>
// Fails if a translation in languages/*.po lacks a string of the template (i.e. of the code) or leaves it empty.

/**
 * @return array<string, bool> msgid => translated
 */
function entries(string $file): array {
	$entries = [];
	// entries are separated by blank lines; the first one is the header
	foreach (array_slice(preg_split('/\n\s*\n/', (string) file_get_contents($file)) ?: [], 1) as $entry) {
		if (!preg_match('/^msgid ((?:".*"\n?)+)/m', $entry, $id)) {
			continue;
		}
		preg_match_all('/^msgstr(?:\[\d+\])? ((?:".*"\n?)+)/m', $entry, $strings);
		// fuzzy: carried over from a changed text, needs review
		$translated = $strings[1] !== [] && !preg_match('/^#,.*\bfuzzy\b/m', $entry);
		foreach ($strings[1] as $string) {
			preg_match_all('/"(.*)"/', $string, $parts);
			$translated = $translated && implode('', $parts[1]) !== '';
		}
		// the text itself: long strings may be wrapped over several quoted lines
		preg_match_all('/"(.*)"/', $id[1], $idParts);
		$entries['"' . implode('', $idParts[1]) . '"'] = $translated;
	}
	return $entries;
}

$template = entries($argv[1] ?? __DIR__ . '/../languages/wptb.pot');
$failed = false;
foreach (glob(__DIR__ . '/../languages/*.po') ?: [] as $file) {
	$translations = entries($file);
	$missing = array_keys(array_filter($template, static fn (bool $_, string $id): bool => !($translations[$id] ?? false), ARRAY_FILTER_USE_BOTH));
	printf("%s: %d of %d strings translated\n", basename($file), count($template) - count($missing), count($template));
	foreach ($missing as $id) {
		echo "  missing: $id\n";
	}
	$failed = $failed || $missing;
}
exit($failed ? 1 : 0);
