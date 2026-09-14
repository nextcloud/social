<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\GifRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\Gif;
use OCA\Social\Service\GifService;
use OCA\Social\Service\ImageMetadataService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The instance's shared picture library.
 *
 * The slug rules carry the weight here: a slug is a path segment of a public
 * URL *and* the stem of a filename in appdata, so anything it lets through is
 * something both of those have to survive.
 */
class GifServiceTest extends TestCase {
	private GifRequest&MockObject $gifRequest;
	private ISimpleFolder&MockObject $folder;
	private GifService $service;

	protected function setUp(): void {
		$this->gifRequest = $this->createMock(GifRequest::class);
		$this->folder = $this->createMock(ISimpleFolder::class);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $args): string => 'https://cloud.example/gif/' . $args['slug']
			);

		$metadata = $this->createMock(ImageMetadataService::class);
		$metadata->method('strip')->willReturnArgument(0);

		$this->service = new GifService(
			$this->gifRequest, $appData, $urlGenerator, $metadata, new NullLogger()
		);
	}

	public static function slugProvider(): array {
		return [
			'letters' => ['cat', true],
			'letters and digits' => ['cat2', true],
			'a hyphen' => ['happy-cat', true],
			'an underscore' => ['happy_cat', true],
			'too short' => ['c', false],
			'nothing' => ['', false],
			// the reason the rule is this narrow: the slug is a path segment
			// and a filename stem, so a dot or a slash is a traversal waiting
			// to be tried
			'a dot' => ['cat.gif', false],
			'a traversal' => ['../../etc/passwd', false],
			'a slash' => ['cat/2', false],
			'a null byte' => ["cat\0", false],
			'uppercase' => ['Cat', false],
			'a space' => ['happy cat', false],
			'longer than the column' => [str_repeat('a', 65), false],
		];
	}

	#[DataProvider('slugProvider')]
	public function testWhatMayNameAPicture(string $slug, bool $allowed): void {
		$this->assertSame($allowed, Gif::isSlug($slug));
	}

	/** @return Gif[] */
	private function library(): array {
		return [
			new Gif('happy-cat', 'happy-cat.gif', 'image/gif', 'A very happy cat', 1),
			new Gif('shipit', 'shipit.gif', 'image/gif', 'Ship it', 2),
			new Gif('nod', 'nod.webp', 'image/webp', '', 3),
		];
	}

	public function testEveryPictureKnowsWhereItIsServed(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$urls = array_map(static fn (Gif $gif): string => $gif->getUrl(), $this->service->all());

		$this->assertSame([
			'https://cloud.example/gif/happy-cat',
			'https://cloud.example/gif/shipit',
			'https://cloud.example/gif/nod',
		], $urls);
	}

	public function testAnEmptyTermIsTheWholeLibrary(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertCount(3, $this->service->search(''));
		$this->assertCount(3, $this->service->search('   '));
	}

	public function testSearchingMatchesTheTitle(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$found = array_map(static fn (Gif $gif): string => $gif->getSlug(), $this->service->search('happy'));

		$this->assertSame(['happy-cat'], $found);
	}

	/** "the one with the cat" is how anybody actually looks for one of these */
	public function testSearchingIgnoresCase(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertCount(1, $this->service->search('HAPPY'));
		$this->assertCount(1, $this->service->search('Ship It'));
	}

	public function testSearchingAlsoMatchesTheSlug(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$found = array_map(static fn (Gif $gif): string => $gif->getSlug(), $this->service->search('nod'));

		$this->assertSame(['nod'], $found);
	}

	public function testSearchingFindsNothingRatherThanEverything(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertSame([], $this->service->search('aardvark'));
	}

	public function testBySlugFindsOne(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertSame('Ship it', $this->service->bySlug('shipit')?->getTitle());
	}

	public function testBySlugIsCaseInsensitiveAndTrimmed(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertNotNull($this->service->bySlug('  SHIPIT '));
	}

	public function testBySlugAnswersNullForOneThatIsNotThere(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertNull($this->service->bySlug('nope'));
	}

	public function testAddingRefusesASlugThatIsNotOne(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->add('../escape', __FILE__);
	}

	public function testAddingRefusesAFileThatIsNotThere(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->add('cat', '/no/such/file.gif');
	}

	/** A library of stills is what the Files picker beside it is already for. */
	public function testAddingRefusesSomethingThatIsNotAnimated(): void {
		$path = tempnam(sys_get_temp_dir(), 'gif');
		// a one-pixel PNG, which is a picture but not an animation
		file_put_contents($path, base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
		));

		try {
			$this->expectException(InvalidActionException::class);
			$this->service->add('cat', $path);
		} finally {
			unlink($path);
		}
	}

	public function testAddingRefusesAnEmptyFile(): void {
		$path = tempnam(sys_get_temp_dir(), 'gif');

		try {
			$this->expectException(InvalidActionException::class);
			$this->service->add('cat', $path);
		} finally {
			unlink($path);
		}
	}

	public function testRemovingSaysWhenThereWasNothingToRemove(): void {
		$this->gifRequest->method('delete')->willReturn('');

		$this->assertFalse($this->service->remove('nope'));
	}

	public function testRemovingTakesTheBytesWithIt(): void {
		$this->gifRequest->method('delete')->willReturn('cat.gif');
		$file = $this->createMock(\OCP\Files\SimpleFS\ISimpleFile::class);
		$file->expects($this->once())->method('delete');
		$this->folder->method('getFile')->with('cat.gif')->willReturn($file);

		$this->assertTrue($this->service->remove('cat'));
	}

	/**
	 * The row is what decides whether a picture is in the library. A file left
	 * behind is a file, not a broken entry, and failing the removal over it
	 * would leave the entry in the picker.
	 */
	public function testRemovingSucceedsEvenIfTheBytesWillNotGo(): void {
		$this->gifRequest->method('delete')->willReturn('cat.gif');
		$this->folder->method('getFile')->willThrowException(new \Exception('busy'));

		$this->assertTrue($this->service->remove('cat'));
	}
}
