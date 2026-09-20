<?php

namespace App\Tests\Service;

use App\Service\NameSearch;
use PHPUnit\Framework\TestCase;

final class NameSearchTest extends TestCase
{
    public function testNormalizeTrimsCollapsesAndCaps(): void
    {
        self::assertNull(NameSearch::normalize(null));
        self::assertNull(NameSearch::normalize('   '));
        self::assertSame('a b', NameSearch::normalize("  a \t\n b  "));
        self::assertSame(NameSearch::MAX_LENGTH, mb_strlen(NameSearch::normalize(str_repeat('x', 500))));
    }

    public function testTermsSplitOnSpacesAndAreCapped(): void
    {
        self::assertSame(['holiday', '2024'], NameSearch::terms('holiday 2024'));
        self::assertCount(NameSearch::MAX_TERMS, NameSearch::terms(implode(' ', range(1, 20))));
    }

    public function testLikePatternTranslatesGlobsAndEscapesMetacharacters(): void
    {
        self::assertSame('%holi%', NameSearch::likePattern('holi'));
        self::assertSame('%img%.jpg%', NameSearch::likePattern('img*.jpg'));
    }

    public function testLikePatternEscapes(): void
    {
        self::assertSame('%IMG!_0001%', NameSearch::likePattern('IMG_0001'));
        self::assertSame('%100!%%', NameSearch::likePattern('100%'));
        self::assertSame('%a!!b%', NameSearch::likePattern('a!b'));
        self::assertSame('%a_b%', NameSearch::likePattern('a?b'));
    }
}
