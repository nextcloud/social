<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The strings this app offers for translation, checked against what the
 * translation pipeline can actually carry.
 *
 * `.github/workflows/l10n.yml` extracts the source strings with gettext and
 * pushes them to Transifex; `l10n-pull.yml` brings the finished translations
 * back. That pipeline is not run here — it needs `xgettext` and a network — but
 * the mistakes that make a string permanently untranslatable are all visible in
 * the source, and each of them is silent: the app builds, the tests pass, the
 * string reaches Transifex or does not, and the only way to find out is to read
 * a translated instance in a language you do not speak.
 *
 * So they are caught here instead, on the PR that introduces them.
 */
class TranslatableStringsTest extends TestCase {
	/** Directories the extractor reads, as `.l10nignore` leaves them. */
	private const SOURCES = ['lib', 'src', 'templates'];

	/**
	 * Every `t('social', '…')` in the source, by the message it translates.
	 *
	 * @return array<string, string[]> message => the places it is written
	 */
	private static function singulars(): array {
		return self::messages('/(?<![\w$>])t\(\s*[\'"]social[\'"]\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/');
	}

	/**
	 * Every `n('social', '…', '…', …)`, by its singular form.
	 *
	 * @return array<string, string[]> singular => the places it is written
	 */
	private static function plurals(): array {
		return self::messages('/(?<![\w$>])n\(\s*[\'"]social[\'"]\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/');
	}

	/**
	 * @param string $pattern a regular expression whose first group is the message
	 *
	 * @return array<string, string[]> message => the places it is written
	 */
	private static function messages(string $pattern): array {
		$found = [];
		foreach (self::sourceFiles() as $path) {
			$body = (string)file_get_contents($path);
			if (preg_match_all($pattern, $body, $matches) === 0) {
				continue;
			}

			foreach ($matches[1] as $message) {
				$found[stripcslashes($message)][] = self::relative($path);
			}
		}

		return $found;
	}

	/** @return string[] every file the extractor reads */
	private static function sourceFiles(): array {
		$files = [];
		foreach (self::SOURCES as $directory) {
			$root = dirname(__DIR__) . '/' . $directory;
			if (!is_dir($root)) {
				continue;
			}

			$walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
			foreach ($walk as $file) {
				if (in_array($file->getExtension(), ['php', 'js', 'vue'], true)) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	private static function relative(string $path): string {
		return substr($path, strlen(dirname(__DIR__)) + 1);
	}

	/**
	 * A message written once as `t()` and once as the singular of `n()`.
	 *
	 * gettext cannot hold both: it folds them into one plural entry, keyed
	 * `_singular_::_plural_`, and `t()` looks the bare message up — finds
	 * nothing, and falls back to English in every language for ever. The app
	 * looks correct in English, which is the only language anybody testing it
	 * is likely to be reading.
	 *
	 * The fix is to reword one of the two. There is no message context in this
	 * pipeline to disambiguate them with.
	 */
	public function testNoMessageIsWrittenBothWithAndWithoutAPlural(): void {
		$both = array_intersect_key(self::singulars(), self::plurals());

		$this->assertSame(
			[],
			array_map(
				static fn (array $places): array => array_values(array_unique($places)),
				$both
			),
			'these messages are used as both a singular and the singular of a plural, '
			. 'so t() will never find a translation for them'
		);
	}

	/**
	 * A message that is only a URL, an address or a bare number.
	 *
	 * Ninety-eight locales are asked to translate it, every one of them can
	 * only copy it back, and any one of them that does not copies a typo into
	 * an example somebody will follow. These belong in the attribute, not in
	 * the catalogue.
	 */
	public function testNothingUntranslatableIsOfferedForTranslation(): void {
		$offered = [];
		foreach (array_merge(array_keys(self::singulars()), array_keys(self::plurals())) as $message) {
			if (preg_match('#^(https?://\S+|[\w.+-]+@[\w-]+\.\w+|[\d\s.,:/-]+)$#', $message) === 1) {
				$offered[] = $message;
			}
		}

		$this->assertSame([], $offered, 'a translator can only copy these back');
	}

	/**
	 * A message the extractor cannot see at all.
	 *
	 * `xgettext` reads the source as text, so it only finds a message written
	 * as a literal. `t('social', someVariable)` compiles, runs, and reaches
	 * translators as nothing — the string is untranslatable and nothing says
	 * so. A message built from parts belongs in one string with a placeholder.
	 */
	public function testEveryTranslatedMessageIsALiteral(): void {
		// the message argument, up to the first thing that could end it
		$pattern = '/(?<![\w$>])(?:t|n)\(\s*[\'"]social[\'"]\s*,\s*([^\s\'"][^,)]{0,40})/';

		$dynamic = [];
		foreach (self::sourceFiles() as $path) {
			$body = (string)file_get_contents($path);
			// a call broken across lines still starts its message with a quote
			$body = preg_replace('/,\s*\n\s*/', ', ', $body) ?? $body;
			if (preg_match_all($pattern, $body, $matches) === 0) {
				continue;
			}

			foreach ($matches[1] as $argument) {
				$dynamic[] = self::relative($path) . ': ' . trim($argument);
			}
		}

		$this->assertSame([], $dynamic, 'xgettext cannot read a message that is not a literal');
	}

	/**
	 * The extractor has to be told what not to read, or it offers the built
	 * bundle's copy of every string a second time and every dependency's
	 * strings besides.
	 */
	public function testTheExtractorIsToldToSkipWhatIsNotSource(): void {
		$ignored = array_filter(array_map(
			'trim',
			explode("\n", (string)file_get_contents(dirname(__DIR__) . '/.l10nignore'))
		), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'));

		foreach (['js/', 'node_modules/', 'vendor/', 'tests/'] as $directory) {
			$this->assertContains($directory, $ignored, $directory . ' is not source and must not be extracted');
		}
	}
}
