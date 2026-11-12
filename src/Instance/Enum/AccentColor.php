<?php

declare(strict_types=1);

namespace App\Instance\Enum;

/**
 * Accent colour of an instance (INSTANCE_ACCENT): a small palette checked for the AA contrasts of
 * SPEC §8, never a free colour. Each accent replaces the five sage tokens of the design system;
 * wheat (the primary button), cream, ink and the lines stay the same.
 *
 * The stylesheet (assets/styles/app.css, `:root[data-accent=…]`) repeats these values:
 * AccentPaletteTest checks both, and every contrast the pages rely on.
 */
enum AccentColor: string
{
    case Sauge = 'sauge';
    case Riviere = 'riviere';
    case Terre = 'terre';
    case Prune = 'prune';
    case Ardoise = 'ardoise';

    public function label(): string
    {
        return match ($this) {
            self::Sauge => 'Sauge (Panier Sauvage)',
            self::Riviere => 'Rivière (bleu)',
            self::Terre => 'Terre (brique)',
            self::Prune => 'Prune (violet)',
            self::Ardoise => 'Ardoise (gris bleu)',
        };
    }

    public function isDefault(): bool
    {
        return self::Sauge === $this;
    }

    /**
     * The five tokens it sets, by name of the CSS variable without `--color-`.
     *
     * @return array{sage: string, sage-dark: string, sage-deep: string, sage-soft: string, sage-mist: string}
     */
    public function tokens(): array
    {
        return match ($this) {
            self::Sauge => ['sage' => '#5b7b6a', 'sage-dark' => '#46604f', 'sage-deep' => '#2f4337', 'sage-soft' => '#f1f5f2', 'sage-mist' => '#e3ebe5'],
            self::Riviere => ['sage' => '#4f7a8a', 'sage-dark' => '#3b5f6d', 'sage-deep' => '#26404b', 'sage-soft' => '#eff4f6', 'sage-mist' => '#dde8ec'],
            self::Terre => ['sage' => '#9a5f48', 'sage-dark' => '#7f4a36', 'sage-deep' => '#553022', 'sage-soft' => '#f9f1ed', 'sage-mist' => '#f0e1da'],
            self::Prune => ['sage' => '#7d6283', 'sage-dark' => '#644d6a', 'sage-deep' => '#433247', 'sage-soft' => '#f6f2f7', 'sage-mist' => '#ebe3ed'],
            self::Ardoise => ['sage' => '#5f6f7d', 'sage-dark' => '#4a5866', 'sage-deep' => '#323c46', 'sage-soft' => '#f2f4f6', 'sage-mist' => '#e3e8ec'],
        };
    }

    /** Text, links and the producer header (`sage-dark`), upper case for the e-mails and theme-color. */
    public function darkHex(): string
    {
        return strtoupper($this->tokens()['sage-dark']);
    }

    /** Titles on light backgrounds (`sage-deep`). */
    public function deepHex(): string
    {
        return strtoupper($this->tokens()['sage-deep']);
    }

    /** Soft backgrounds (`sage-soft`). */
    public function softHex(): string
    {
        return strtoupper($this->tokens()['sage-soft']);
    }
}
