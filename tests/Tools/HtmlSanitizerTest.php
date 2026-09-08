<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use OCA\Social\Tools\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase {
	public function testKeepsMastodonFormatting(): void {
		$html = '<p>Hello <strong>world</strong></p><blockquote><p>quoted</p></blockquote>'
			. '<ul><li>one</li><li>two</li></ul><pre><code>x &lt; y</code></pre><h2>Title</h2>';

		$this->assertSame($html, HtmlSanitizer::sanitize($html));
	}

	public function testDropsEventHandlerAttributes(): void {
		$result = HtmlSanitizer::sanitize('<p onclick="alert(1)" onmouseover="alert(2)">text</p>');

		$this->assertSame('<p>text</p>', $result);
	}

	public function testDropsJavascriptLinks(): void {
		$result = HtmlSanitizer::sanitize('<a href="javascript:alert(1)">click</a>');

		$this->assertSame('<a>click</a>', $result);
	}

	public function testDropsObfuscatedJavascriptLinks(): void {
		// Browsers ignore control characters and whitespace inside a scheme
		$result = HtmlSanitizer::sanitize("<a href=\"  java\tscript:alert(1)\">click</a>");

		$this->assertSame('<a>click</a>', $result);
	}

	public function testDropsUnknownSchemes(): void {
		$this->assertSame('<a>d</a>', HtmlSanitizer::sanitize('<a href="data:text/html,x">d</a>'));
		$this->assertSame('<a>v</a>', HtmlSanitizer::sanitize('<a href="vbscript:x">v</a>'));
		$this->assertSame('<a>m</a>', HtmlSanitizer::sanitize('<a href="mailto:a@b.c">m</a>'));
	}

	public function testKeepsAllowedSchemesAndRelativeLinks(): void {
		$result = HtmlSanitizer::sanitize(
			'<a href="https://example.org/@alice">a</a>'
			. '<a href="gemini://example.org">g</a>'
			. '<a href="//example.org/x">r</a>'
			. '<a href="/local">l</a>'
		);

		$this->assertStringContainsString('href="https://example.org/@alice"', $result);
		$this->assertStringContainsString('href="gemini://example.org"', $result);
		$this->assertStringContainsString('href="//example.org/x"', $result);
		$this->assertStringContainsString('href="/local"', $result);
	}

	public function testForcesSafeLinkRelAndTarget(): void {
		$result = HtmlSanitizer::sanitize('<a href="https://example.org" rel="opener" target="_self">x</a>');

		$this->assertSame(
			'<a href="https://example.org" rel="nofollow noopener noreferrer" target="_blank">x</a>',
			$result,
		);
	}

	public function testRemovesScriptAndStyleWithTheirContent(): void {
		$result = HtmlSanitizer::sanitize('<p>a</p><script>alert(1)</script><style>p{}</style><p>b</p>');

		$this->assertSame('<p>a</p><p>b</p>', $result);
	}

	public function testUnwrapsUnknownElementsButKeepsTheirText(): void {
		$result = HtmlSanitizer::sanitize('<div class="x"><p>inside <font color="red">red</font></p></div>');

		$this->assertSame('<p>inside red</p>', $result);
	}

	public function testDropsImagesAndTheirAttributes(): void {
		$result = HtmlSanitizer::sanitize('<p>pic <img src="x" onerror="alert(1)"> after</p>');

		$this->assertSame('<p>pic  after</p>', $result);
	}

	public function testDoesNotResurrectEncodedMarkup(): void {
		// The old strip_tags-then-decode order turned this into a live <img>
		$result = HtmlSanitizer::sanitize('<p>&lt;img src=x onerror=alert(1)&gt;</p>');

		$this->assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $result);
	}

	public function testSanitizesElementsLiftedOutOfAnUnwrappedParent(): void {
		// The children of a removed wrapper must be checked too, not skipped
		$result = HtmlSanitizer::sanitize('<div><a href="javascript:x" onclick="y">l</a><script>z</script></div>');

		$this->assertSame('<a>l</a>', $result);
	}

	public function testKeepsOnlyKnownClasses(): void {
		$result = HtmlSanitizer::sanitize(
			'<span class="h-card evil"><a href="https://e.org/@a" class="u-url mention">@a</a></span>'
			. '<span class="evil">x</span>'
		);

		$this->assertStringContainsString('<span class="h-card">', $result);
		$this->assertStringContainsString('class="u-url mention"', $result);
		$this->assertStringContainsString('<span>x</span>', $result);
	}

	public function testDropsStyleAndIdAttributes(): void {
		$result = HtmlSanitizer::sanitize('<p style="position:fixed" id="app-content">x</p>');

		$this->assertSame('<p>x</p>', $result);
	}

	public function testValidatesListAttributes(): void {
		$this->assertSame('<ol start="3"><li value="7">x</li></ol>', HtmlSanitizer::sanitize('<ol start="3"><li value="7">x</li></ol>'));
		$this->assertSame('<ol><li>x</li></ol>', HtmlSanitizer::sanitize('<ol start="a"><li value="b">x</li></ol>'));
	}

	public function testPreservesMultibyteText(): void {
		$html = '<p>Ünïcödé 日本語 🙂</p>';

		$this->assertSame($html, HtmlSanitizer::sanitize($html));
	}

	public function testRemovesComments(): void {
		$this->assertSame('<p>a</p>', HtmlSanitizer::sanitize('<p>a</p><!-- <script>x</script> -->'));
	}

	public function testEmptyInputStaysEmpty(): void {
		$this->assertSame('', HtmlSanitizer::sanitize(''));
		$this->assertSame('', HtmlSanitizer::sanitize('   '));
	}

	public function testIsAllowedUrl(): void {
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('https://example.org'));
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('HTTP://EXAMPLE.ORG'));
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('/relative'));
		$this->assertTrue(HtmlSanitizer::isAllowedUrl('#fragment'));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl('javascript:alert(1)'));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl("java\nscript:alert(1)"));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl('data:text/html,x'));
		$this->assertFalse(HtmlSanitizer::isAllowedUrl(''));
	}
}
