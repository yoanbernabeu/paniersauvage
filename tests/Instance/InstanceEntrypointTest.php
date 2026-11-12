<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The production entrypoint checks the INSTANCE_* settings (app:instance:check), and does not ask
 * a sponsored instance for Stripe keys it never uses.
 */
final class InstanceEntrypointTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const array SETTINGS = [
        'APP_SECRET' => 'f3b1c7a9e5d2048b6a1f9c3e7d5b2a8046c1e9f7b3d5a2c8e4f6b1d9a7c3e5f2',
        'DATABASE_URL' => 'postgresql://app:secret@127.0.0.1:1/app',
        'MAILER_DSN' => 'brevo+api://a1b2c3@default',
        'MAILER_FROM' => 'Panier Toqué <bonjour@panier-toque.fr>',
        'LEGAL_PUBLISHER_NAME' => 'Valence Romans Agglo',
        'LEGAL_PUBLISHER_FORM' => 'Communauté d\'agglomération',
        'LEGAL_PUBLISHER_ADDRESS' => 'Rue Dupont, Valence',
        'LEGAL_PUBLISHER_PHONE' => '04 00 00 00 00',
        'LEGAL_PUBLICATION_DIRECTOR' => 'Le président',
        'LEGAL_CONTACT_EMAIL' => 'contact@panier-toque.fr',
        'LEGAL_HOST_NAME' => 'Hébergeur SAS',
        'LEGAL_HOST_ADDRESS' => 'Rue Hébergeur, Lyon',
        'LEGAL_SOURCE_CODE_URL' => 'https://forge.panier-toque.fr/code',
        'DATABASE_WAIT_ATTEMPTS' => '1',
    ];

    public function testASponsoredInstanceNeedsNoStripeKeys(): void
    {
        $process = $this->entrypoint(['INSTANCE_PRICING' => 'sponsored', 'INSTANCE_CARRIER_NAME' => 'Valence Romans Agglo']);

        $errors = $process->getErrorOutput();
        self::assertStringNotContainsString('STRIPE', $errors);
        self::assertStringNotContainsString('INSTANCE', $errors);
        self::assertStringNotContainsString('not starting', $errors);
        self::assertStringContainsString('the database is still unreachable', $errors, 'The settings passed: it waits for the database.');
    }

    public function testTheStandardModeStillNeedsStripe(): void
    {
        $process = $this->entrypoint([]);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('STRIPE_SECRET_KEY is not set', $process->getErrorOutput());
        self::assertStringNotContainsString('INSTANCE', $process->getErrorOutput());
    }

    public function testAnUnusableInstanceSettingStopsTheStart(): void
    {
        $process = $this->entrypoint(['INSTANCE_PRICING' => 'sponsored', 'INSTANCE_ACCENT' => 'fuchsia']);

        self::assertSame(1, $process->getExitCode());
        $errors = $process->getErrorOutput();
        self::assertStringContainsString('INSTANCE_ACCENT: one of sauge', (string) preg_replace('/\s+/', ' ', $errors));
        self::assertStringContainsString('an INSTANCE_* setting is not usable', $errors);
        self::assertStringContainsString('1 setting(s) to fix, not starting.', $errors);
    }

    /**
     * @param array<string, string> $env
     */
    private function entrypoint(array $env): Process
    {
        $clean = [];
        foreach ([...array_keys(getenv()), ...array_keys($_ENV)] as $name) {
            $clean[(string) $name] = false;
        }
        $process = new Process(['sh', self::ROOT.'/docker/entrypoint.sh', 'php', '-r', 'echo "started";'], self::ROOT, ['PATH' => (string) getenv('PATH')] + $env + self::SETTINGS + $clean);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }
}
