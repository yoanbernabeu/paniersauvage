<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Instance\Enum\AccentColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every accent of the palette keeps the contrasts of SPEC §8 (WCAG 2.2 AA), computed here with the
 * WCAG formula, and the stylesheet carries exactly the values of AccentColor.
 */
final class AccentPaletteTest extends TestCase
{
    private const string WHITE = '#ffffff';
    private const string CREAM = '#faf8f3';
    private const string PAPER = '#f7f6f1';
    private const string WHEAT = '#e8b931';
    private const string INK = '#1f2a24';

    public function testTheContrastFormulaGivesTheKnownRatios(): void
    {
        self::assertEqualsWithDelta(21.0, $this->contrast('#000000', self::WHITE), 0.001);
        self::assertEqualsWithDelta(1.0, $this->contrast('#777777', '#777777'), 0.001);
        self::assertEqualsWithDelta(4.48, $this->contrast('#777777', self::WHITE), 0.01);
        self::assertEqualsWithDelta(6.89, $this->contrast(AccentColor::Sauge->tokens()['sage-dark'], self::WHITE), 0.01);
        self::assertSame($this->contrast('#46604f', self::WHITE), $this->contrast(self::WHITE, '#46604f'), 'Symmetric.');
        self::assertSame('#597061', $this->blend(self::WHITE, '#46604f', 0.1), 'White at 10 % over sage-dark.');
    }

    /**
     * @return iterable<string, array{AccentColor}>
     */
    public static function accents(): iterable
    {
        foreach (AccentColor::cases() as $accent) {
            yield $accent->value => [$accent];
        }
    }

    #[DataProvider('accents')]
    public function testTheAccentKeepsTheAaContrasts(AccentColor $accent): void
    {
        $t = $accent->tokens();

        // Text and links (sage-dark) on every light background of the pages: 4.5:1.
        foreach (['white' => self::WHITE, 'cream' => self::CREAM, 'paper' => self::PAPER, 'sage-soft' => $t['sage-soft'], 'sage-mist' => $t['sage-mist']] as $name => $background) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast($t['sage-dark'], $background), 'sage-dark on '.$name);
        }
        // Text on light sage (sage-deep): 4.5:1, and ink everywhere.
        foreach (['sage-soft', 'sage-mist'] as $background) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast($t['sage-deep'], $t[$background]), 'sage-deep on '.$background);
            self::assertGreaterThanOrEqual(4.5, $this->contrast(self::INK, $t[$background]), 'ink on '.$background);
        }
        // Producer header and dark blocks: white text, white at 85 % and 75 % (secondary text, navigation).
        foreach (['sage-dark', 'sage-deep'] as $background) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast(self::WHITE, $t[$background]), 'white on '.$background);
            self::assertGreaterThanOrEqual(4.5, $this->contrast($this->blend(self::WHITE, $t[$background], 0.75), $t[$background]), 'white/75 on '.$background);
        }
        // Wheat on the dark blocks: large titles (3:1) on sage-deep must even reach 4.5:1, focus ring 3:1.
        self::assertGreaterThanOrEqual(4.5, $this->contrast(self::WHEAT, $t['sage-deep']), 'wheat on sage-deep');
        self::assertGreaterThanOrEqual(3.0, $this->contrast(self::WHEAT, $t['sage-dark']), 'wheat focus ring on sage-dark');
        // Non-text (1.4.11): active borders, map circle, focus ring on light backgrounds.
        foreach (['white' => self::WHITE, 'cream' => self::CREAM, 'paper' => self::PAPER] as $name => $background) {
            self::assertGreaterThanOrEqual(3.0, $this->contrast($t['sage'], $background), 'sage on '.$name);
            self::assertGreaterThanOrEqual(3.0, $this->contrast($t['sage-dark'], $background), 'focus ring on '.$name);
        }
        // The light tones stay light (backgrounds), the dark ones dark.
        self::assertGreaterThan($this->luminance($t['sage-mist']), $this->luminance($t['sage-soft']));
        self::assertGreaterThan($this->luminance($t['sage']), $this->luminance($t['sage-mist']));
        self::assertGreaterThan($this->luminance($t['sage-dark']), $this->luminance($t['sage']));
        self::assertGreaterThan($this->luminance($t['sage-deep']), $this->luminance($t['sage-dark']));
    }

    #[DataProvider('accents')]
    public function testTheStylesheetCarriesTheSameValues(AccentColor $accent): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        if ($accent->isDefault()) {
            // The default accent is the theme itself, with no attribute.
            self::assertStringNotContainsString('data-accent="'.$accent->value.'"', $css);
            $block = (string) preg_replace('/^.*?@theme \{(.*?)\}.*$/s', '$1', $css);
        } else {
            self::assertSame(1, preg_match('/:root\[data-accent="'.$accent->value.'"\] \{(?<block>[^}]*)\}/', $css, $matches), $accent->value);
            $block = $matches['block'] ?? '';
        }

        preg_match_all('/--color-(sage(?:-[a-z]+)?):\s*(#[0-9a-f]{6})/', $block, $declarations, \PREG_SET_ORDER);
        $values = [];
        foreach ($declarations as $declaration) {
            $values[$declaration[1]] = $declaration[2];
        }

        self::assertSame($accent->tokens(), $values);
    }

    public function testEveryAccentHasALabelAndTheHexHelpers(): void
    {
        self::assertSame('Sauge (Panier Sauvage)', AccentColor::Sauge->label());
        self::assertSame('Rivière (bleu)', AccentColor::Riviere->label());
        self::assertSame('Terre (brique)', AccentColor::Terre->label());
        self::assertSame('Prune (violet)', AccentColor::Prune->label());
        self::assertSame('Ardoise (gris bleu)', AccentColor::Ardoise->label());
        self::assertSame(['#46604F', '#2F4337', '#F1F5F2'], [AccentColor::Sauge->darkHex(), AccentColor::Sauge->deepHex(), AccentColor::Sauge->softHex()]);
        self::assertSame(['#3B5F6D', '#26404B', '#EFF4F6'], [AccentColor::Riviere->darkHex(), AccentColor::Riviere->deepHex(), AccentColor::Riviere->softHex()]);
        self::assertFalse(AccentColor::Prune->isDefault());
    }

    /**
     * WCAG 2.2 contrast ratio.
     */
    private function contrast(string $first, string $second): float
    {
        $luminances = [$this->luminance($first), $this->luminance($second)];

        return (max($luminances) + 0.05) / (min($luminances) + 0.05);
    }

    /**
     * WCAG relative luminance of an sRGB colour "#rrggbb".
     */
    private function luminance(string $hex): float
    {
        $channel = static function (string $component): float {
            $value = hexdec($component) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
    }

    /**
     * $color at $opacity over $background, as a browser paints it.
     */
    private function blend(string $color, string $background, float $opacity): string
    {
        $blended = '#';
        foreach ([1, 3, 5] as $offset) {
            $value = $opacity * hexdec(substr($color, $offset, 2)) + (1 - $opacity) * hexdec(substr($background, $offset, 2));
            $blended .= \sprintf('%02x', (int) round($value));
        }

        return $blended;
    }
}
