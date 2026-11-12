<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\Service\PublisherIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

/**
 * The publisher and the host of the platform come from LEGAL_* variables: placeholders in the
 * committed .env, real values required by the production image.
 */
final class PublisherSettingsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const array REAL = [
        'APP_SECRET' => 'a3f9c2e1b7d84f6a9c0e2b4d6f8a1c3e5b7d9f0a2c4e6b8d',
        'DATABASE_URL' => 'postgresql://app:secret@database:5432/app',
        'MAILER_DSN' => 'brevo+api://xkeysib-0123456789abcdef@default',
        'MAILER_FROM' => 'Panier Sauvage <bonjour@paniersauvage.fr>',
        'STRIPE_SECRET_KEY' => 'sk_live_51Hx0123456789',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_0123456789abcdef0123',
        'STRIPE_PRICE_MONTHLY' => 'price_monthly0123',
        'STRIPE_PRICE_YEARLY' => 'price_yearly0123',
        'STRIPE_PRODUCT_SOLIDARITY' => 'prod_solidarity0123',
        'LEGAL_PUBLISHER_NAME' => 'Camille Martin',
        'LEGAL_PUBLISHER_FORM' => 'Entrepreneur individuel',
        'LEGAL_PUBLISHER_ADDRESS' => "12 rue des Tilleuls\n26000 Valence",
        'LEGAL_PUBLISHER_SIRET' => '13002526500013',
        'LEGAL_PUBLISHER_PHONE' => '04 75 00 00 00',
        'LEGAL_PUBLICATION_DIRECTOR' => 'Camille Martin',
        'LEGAL_CONTACT_EMAIL' => 'contact@paniersauvage.fr',
        'LEGAL_HOST_NAME' => 'OVH SAS',
        'LEGAL_HOST_ADDRESS' => '2 rue Kellermann, 59100 Roubaix',
        'LEGAL_HOST_PHONE' => '1007',
        'LEGAL_SOURCE_CODE_URL' => 'https://github.com/paniersauvage/panier-sauvage',
    ];

    public function testTheCommittedValuesAreMarkedAsPlaceholders(): void
    {
        $env = $this->dotenv('.env');

        foreach (['LEGAL_PUBLISHER_NAME', 'LEGAL_PUBLISHER_FORM', 'LEGAL_PUBLISHER_ADDRESS', 'LEGAL_PUBLISHER_PHONE', 'LEGAL_PUBLICATION_DIRECTOR', 'LEGAL_HOST_NAME', 'LEGAL_HOST_ADDRESS'] as $name) {
            self::assertStringContainsString(PublisherIdentity::PLACEHOLDER_MARKER, $env[$name] ?? '', $name);
        }
        self::assertStringEndsWith('.test', $env['LEGAL_CONTACT_EMAIL'] ?? '');
        self::assertStringContainsString('/OWNER/', $env['LEGAL_SOURCE_CODE_URL'] ?? '');

        $example = $this->dotenv('.env.prod.example');
        foreach (array_keys(self::REAL) as $name) {
            if (str_starts_with($name, 'LEGAL_')) {
                self::assertArrayHasKey($name, $example, $name.' must be documented in .env.prod.example.');
            }
        }
    }

    public function testThePlaceholdersAreSpotted(): void
    {
        $real = $this->identity(self::REAL);
        self::assertFalse($real->isPlaceholder());
        self::assertSame('Camille Martin', $real->name);

        self::assertTrue($this->identity(['LEGAL_PUBLISHER_NAME' => 'Éditeur à renseigner'] + self::REAL)->isPlaceholder());
        self::assertTrue($this->identity(['LEGAL_HOST_ADDRESS' => 'Adresse À RENSEIGNER'] + self::REAL)->isPlaceholder());
        self::assertTrue($this->identity(['LEGAL_PUBLISHER_PHONE' => ' '] + self::REAL)->isPlaceholder());
    }

    public function testTheImageAcceptsRealValues(): void
    {
        $process = $this->entrypoint(self::REAL);

        // Every setting passes: the entrypoint goes on to wait for the (unreachable) database.
        self::assertStringNotContainsString('to fix', $process->getErrorOutput());
        self::assertStringNotContainsString('LEGAL_', $process->getErrorOutput());
        self::assertStringContainsString('waiting for the database', $process->getOutput());
    }

    public function testTheImageRefusesPlaceholdersAndMissingValues(): void
    {
        $legal = array_filter($this->dotenv('.env'), static fn (string $name): bool => str_starts_with($name, 'LEGAL_'), \ARRAY_FILTER_USE_KEY);

        $process = $this->entrypoint($legal + self::REAL);

        self::assertSame(1, $process->getExitCode());
        $errors = $process->getErrorOutput();
        foreach (['LEGAL_PUBLISHER_NAME', 'LEGAL_PUBLISHER_FORM', 'LEGAL_PUBLISHER_ADDRESS', 'LEGAL_PUBLISHER_PHONE', 'LEGAL_PUBLICATION_DIRECTOR', 'LEGAL_CONTACT_EMAIL', 'LEGAL_HOST_NAME', 'LEGAL_HOST_ADDRESS', 'LEGAL_SOURCE_CODE_URL'] as $name) {
            self::assertStringContainsString($name.' still holds an example value', $errors);
        }
        // Optional, and empty in .env.
        self::assertStringNotContainsString('LEGAL_PUBLISHER_SIRET', $errors);
        self::assertStringNotContainsString('LEGAL_HOST_PHONE', $errors);

        $missing = $this->entrypoint(array_diff_key(self::REAL, array_flip(['LEGAL_PUBLISHER_NAME', 'LEGAL_CONTACT_EMAIL', 'LEGAL_SOURCE_CODE_URL'])));
        self::assertSame(1, $missing->getExitCode());
        self::assertStringContainsString('LEGAL_PUBLISHER_NAME is not set', $missing->getErrorOutput());
        self::assertStringContainsString('LEGAL_CONTACT_EMAIL is not set', $missing->getErrorOutput());
        self::assertStringContainsString('LEGAL_SOURCE_CODE_URL is not set', $missing->getErrorOutput());
        self::assertStringContainsString('3 setting(s) to fix', $missing->getErrorOutput());
    }

    public function testTheImageRefusesMalformedValues(): void
    {
        $process = $this->entrypoint([
            'LEGAL_PUBLISHER_SIRET' => '1300 2526 5000',
            'LEGAL_CONTACT_EMAIL' => 'contact',
            'LEGAL_SOURCE_CODE_URL' => 'http://github.com/paniersauvage/panier-sauvage',
        ] + self::REAL);

        $errors = $process->getErrorOutput();
        self::assertStringContainsString('LEGAL_PUBLISHER_SIRET does not look like a real value', $errors);
        self::assertStringContainsString('LEGAL_CONTACT_EMAIL does not look like a real value', $errors);
        self::assertStringContainsString('LEGAL_SOURCE_CODE_URL does not look like a real value', $errors);

        $demo = $this->entrypoint(['LEGAL_PUBLISHER_SIRET' => '12345678900012'] + self::REAL);
        self::assertStringContainsString('LEGAL_PUBLISHER_SIRET still holds an example value', $demo->getErrorOutput());
    }

    /**
     * @return array<string, string>
     */
    private function dotenv(string $file): array
    {
        $values = [];
        foreach (new Dotenv()->parse((string) file_get_contents(self::ROOT.'/'.$file)) as $name => $value) {
            $values[(string) $name] = \is_string($value) ? $value : '';
        }

        return $values;
    }

    /**
     * @param array<string, string> $env
     */
    private function identity(array $env): PublisherIdentity
    {
        return new PublisherIdentity(
            $env['LEGAL_PUBLISHER_NAME'], $env['LEGAL_PUBLISHER_FORM'], $env['LEGAL_PUBLISHER_ADDRESS'], $env['LEGAL_PUBLISHER_SIRET'],
            $env['LEGAL_PUBLISHER_PHONE'], $env['LEGAL_PUBLICATION_DIRECTOR'], $env['LEGAL_CONTACT_EMAIL'], $env['LEGAL_HOST_NAME'],
            $env['LEGAL_HOST_ADDRESS'], $env['LEGAL_HOST_PHONE'], $env['LEGAL_SOURCE_CODE_URL'],
        );
    }

    /**
     * The entrypoint with a command that boots the app (so the settings are checked), and a
     * single attempt to reach the database: it answers at once.
     *
     * @param array<string, string> $env
     */
    private function entrypoint(array $env): Process
    {
        $clean = [];
        foreach ([...array_keys(getenv()), ...array_keys($_ENV)] as $name) {
            $clean[(string) $name] = false;
        }
        $process = new Process(['sh', self::ROOT.'/docker/entrypoint.sh', 'php', '-r', 'echo "started";'], self::ROOT, ['PATH' => (string) getenv('PATH'), 'DATABASE_WAIT_ATTEMPTS' => '1'] + $env + $clean);
        $process->run();

        return $process;
    }
}
