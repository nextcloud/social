<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

/**
 * Cells of the CSV files people download and open in a spreadsheet.
 *
 * Excel, LibreOffice and Google Sheets read a cell that starts with `=`, `+`,
 * `-`, `@`, a tab or a carriage return as a formula, and much of what goes in
 * these files is named by other servers: a remote account's name, the host
 * its DID document gives. Such a cell is written with a leading `'`, which
 * makes a spreadsheet read it as text, and the importers here take the `'`
 * off again so that their own files read back unchanged.
 */
final class Csv {
	private const FORMULA_START = "=+-@\t\r";

	/** A value a spreadsheet will not run; a number is left as it is. */
	public static function neutralise(string $value): string {
		if ($value === '' || is_numeric($value) || !str_contains(self::FORMULA_START, $value[0])) {
			return $value;
		}

		return "'" . $value;
	}

	/** A cell as neutralise() wrote it, read back. */
	public static function restore(string $value): string {
		if (strlen($value) > 1 && $value[0] === "'" && str_contains(self::FORMULA_START, $value[1])) {
			return substr($value, 1);
		}

		return $value;
	}

	/** A value as one CSV cell: neutralised, and quoted only where it has to be. */
	public static function cell(string $value): string {
		$value = self::neutralise($value);
		if (strpbrk($value, ",\"\r\n") === false) {
			return $value;
		}

		return '"' . str_replace('"', '""', $value) . '"';
	}
}
