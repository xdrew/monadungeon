<?php

declare(strict_types=1);

namespace App\Tests\Game\Field;

use App\Game\Field\FieldPlace;
use App\Game\Field\TileSide;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldPlace::class)]
final class FieldPlaceTest extends TestCase
{
    public function testConstructAndToString(): void
    {
        $place = new FieldPlace(2, 3);
        self::assertSame('2,3', $place->toString());
    }

    public function testFromString(): void
    {
        $place = FieldPlace::fromString('4,5');
        self::assertSame(4, $place->positionX);
        self::assertSame(5, $place->positionY);
    }

    public function testFromArray(): void
    {
        $place = FieldPlace::fromArray(['positionX' => 7, 'positionY' => 8]);
        self::assertSame(7, $place->positionX);
        self::assertSame(8, $place->positionY);
    }

    public function testFromAnything(): void
    {
        $a = FieldPlace::fromString('1,2');
        $b = FieldPlace::fromAnything($a);
        self::assertSame($a, $b);
        $c = FieldPlace::fromAnything('3,4');
        self::assertSame('3,4', $c->toString());
        $d = FieldPlace::fromAnything(['positionX' => 5, 'positionY' => 6]);
        self::assertSame('5,6', $d->toString());
    }

    public function testEquals(): void
    {
        $a = new FieldPlace(1, 2);
        $b = new FieldPlace(1, 2);
        $c = new FieldPlace(2, 1);
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testGetSiblingBySide(): void
    {
        $place = new FieldPlace(0, 0);
        $top = $place->getSiblingBySide(TileSide::TOP);
        $right = $place->getSiblingBySide(TileSide::RIGHT);
        $left = $place->getSiblingBySide(TileSide::LEFT);
        $bottom = $place->getSiblingBySide(TileSide::BOTTOM);
        self::assertSame('-1,0', $left->toString());
        self::assertSame('1,0', $right->toString());
        self::assertSame('0,-1', $top->toString());
        self::assertSame('0,1', $bottom->toString());
    }

    public function testGetAllSiblingsBySides(): void
    {
        $place = new FieldPlace(10, 10);
        $siblings = $place->getAllSiblingsBySides();
        self::assertCount(4, $siblings);
        self::assertSame('10,9', $siblings[TileSide::TOP->value]->toString());
        self::assertSame('11,10', $siblings[TileSide::RIGHT->value]->toString());
        self::assertSame('9,10', $siblings[TileSide::LEFT->value]->toString());
        self::assertSame('10,11', $siblings[TileSide::BOTTOM->value]->toString());
    }
} 