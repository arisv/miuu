<?php

namespace App\Tests\Service;

use App\Service\FileNameRules;
use PHPUnit\Framework\TestCase;

/** Splitting names into base and extension, and what a rename may produce. */
final class FileNameRulesTest extends TestCase
{
    public function testSplitTakesTheTextAfterTheLastDot(): void
    {
        self::assertSame(['base' => 'photo', 'extension' => 'jpg'], FileNameRules::split('photo.jpg'));
        self::assertSame(['base' => 'archive.tar', 'extension' => 'gz'], FileNameRules::split('archive.tar.gz'));
        self::assertSame(['base' => 'README', 'extension' => null], FileNameRules::split('README'));
    }

    public function testSplitIgnoresLeadingTrailingAndSpacedDots(): void
    {
        self::assertSame(['base' => '.bashrc', 'extension' => null], FileNameRules::split('.bashrc'));
        self::assertSame(['base' => 'notes.', 'extension' => null], FileNameRules::split('notes.'));
        self::assertSame(['base' => 'Meeting v1.2 final', 'extension' => null], FileNameRules::split('Meeting v1.2 final'));
    }

    public function testJoin(): void
    {
        self::assertSame('photo.jpg', FileNameRules::join('photo', 'jpg'));
        self::assertSame('README', FileNameRules::join('README', null));
    }

    public function testRejectedNames(): void
    {
        self::assertNotNull(FileNameRules::problem('', 'png'));
        self::assertNotNull(FileNameRules::problem('a/b', 'png'));
        self::assertNotNull(FileNameRules::problem("a\0b", 'png'));
        self::assertNotNull(FileNameRules::problem("\xff", 'png'));
        self::assertNotNull(FileNameRules::problem('.', null));
        self::assertNotNull(FileNameRules::problem('..', null));
    }

    public function testLengthIsCountedInBytes(): void
    {
        self::assertNull(FileNameRules::problem(str_repeat('a', 251), 'png'), '255 bytes');
        self::assertNotNull(FileNameRules::problem(str_repeat('a', 252), 'png'), '256 bytes');
        self::assertNotNull(FileNameRules::problem(str_repeat('é', 126), 'png'), '126 two-byte characters + ".png" is 256 bytes');
    }

    public function testNamesOnlyOtherSystemsReserveAreAccepted(): void
    {
        foreach (['a<b>c:d"e\\f|g?h*', 'CON', "tab\there", ' leading', 'trailing.', 'dots..inside'] as $base) {
            self::assertNull(FileNameRules::problem($base, 'txt'), $base);
        }
    }

    public function testForDispositionReplacesSlashes(): void
    {
        self::assertSame('a_b_c.png', FileNameRules::forDisposition('a\\b/c.png'));
    }
}
