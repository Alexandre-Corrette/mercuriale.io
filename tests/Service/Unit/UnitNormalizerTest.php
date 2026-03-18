<?php

declare(strict_types=1);

namespace App\Tests\Service\Unit;

use App\Service\Unit\UnitNormalizer;
use App\Service\Unit\UnitNormalizerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnitNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizeProvider(): iterable
    {
        // Lowercase input
        yield 'kg lowercase' => ['kg', 'KG'];
        yield 'kilo' => ['kilo', 'KG'];
        yield 'kilogramme' => ['kilogramme', 'KG'];

        // Uppercase input
        yield 'KG uppercase' => ['KG', 'KG'];
        yield 'COL uppercase' => ['COL', 'COL'];

        // Mixed case
        yield 'Kg mixed' => ['Kg', 'KG'];
        yield 'cL mixed' => ['cL', 'CL'];

        // Volume
        yield 'litre' => ['litre', 'L'];
        yield 'l lowercase' => ['l', 'L'];
        yield 'ml' => ['ml', 'ML'];
        yield 'centilitre' => ['centilitre', 'CL'];

        // Pièce / unité
        yield 'piece' => ['piece', 'PU'];
        yield 'pièce with accent' => ['pièce', 'PU'];
        yield 'p' => ['p', 'PU'];
        yield 'unite' => ['unite', 'UNI'];
        yield 'unité with accent' => ['unité', 'UNI'];

        // Conditionnements
        yield 'barquette' => ['barquette', 'BQT'];
        yield 'bouteille' => ['bouteille', 'BOT'];
        yield 'carton' => ['carton', 'CAR'];
        yield 'colis' => ['colis', 'COL'];
        yield 'sac' => ['sac', 'SAC'];
        yield 'fût with accent' => ['fût', 'FUT'];
        yield 'fut' => ['fut', 'FUT'];

        // Abbreviations
        yield 'bq → BQT' => ['bq', 'BQT'];
        yield 'bt → BOT' => ['bt', 'BOT'];
        yield 'ct → CAR' => ['ct', 'CAR'];
        yield 'col → COL' => ['col', 'COL'];

        // Whitespace
        yield 'trimmed kg' => ['  kg  ', 'KG'];

        // Already canonical
        yield 'PU already canonical' => ['PU', 'PU'];
        yield 'BOT already canonical' => ['BOT', 'BOT'];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, UnitNormalizer::normalize($input));
    }

    public function testNormalizeConsistentAcrossFlows(): void
    {
        // The whole point: import and OCR produce the same canonical code
        self::assertSame(
            UnitNormalizer::normalize('kg'),
            UnitNormalizer::normalize('KG'),
        );
        self::assertSame(
            UnitNormalizer::normalize('piece'),
            UnitNormalizer::normalize('PU'),
        );
        self::assertSame(
            UnitNormalizer::normalize('colis'),
            UnitNormalizer::normalize('COL'),
        );
    }

    public function testNormalizeUnknownFallback(): void
    {
        // Non-strict: returns uppercased value
        self::assertSame('UNKNOWN', UnitNormalizer::normalize('unknown'));
    }

    public function testNormalizeUnknownStrict(): void
    {
        $this->expectException(UnitNormalizerException::class);
        UnitNormalizer::normalize('xyz_invalid', strict: true);
    }

    public function testNormalizeEmptyStrict(): void
    {
        $this->expectException(UnitNormalizerException::class);
        UnitNormalizer::normalize('', strict: true);
    }

    public function testNormalizeEmptyNonStrict(): void
    {
        self::assertSame('', UnitNormalizer::normalize(''));
    }

    public function testToDbCode(): void
    {
        self::assertSame('kg', UnitNormalizer::toDbCode('KG'));
        self::assertSame('p', UnitNormalizer::toDbCode('PU'));
        self::assertSame('bt', UnitNormalizer::toDbCode('BOT'));
        self::assertSame('cL', UnitNormalizer::toDbCode('CL'));
        self::assertSame('ct', UnitNormalizer::toDbCode('CAR'));
        // Unknown canonical → returned as-is
        self::assertSame('FUT', UnitNormalizer::toDbCode('FUT'));
    }
}
