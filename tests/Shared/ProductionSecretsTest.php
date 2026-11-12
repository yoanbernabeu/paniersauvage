<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

/**
 * The committed .env files are compiled into the production image: they must hold no usable
 * secret, and the image must refuse to start without real ones.
 */
final class ProductionSecretsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testCommittedEnvFilesHoldNoSecret(): void
    {
        $dotenv = new Dotenv();
        $env = $dotenv->parse((string) file_get_contents(self::ROOT.'/.env'));

        foreach (['APP_SECRET', 'STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET'] as $name) {
            self::assertArrayHasKey($name, $env);
            self::assertSame('', $env[$name], \sprintf('%s must be empty in the committed .env.', $name));
        }

        self::assertArrayNotHasKey('APP_SECRET', $dotenv->parse((string) file_get_contents(self::ROOT.'/.env.dev')));
    }

    public function testTheImageRefusesToStartWithoutRealSecrets(): void
    {
        $process = $this->entrypoint([
            'APP_SECRET' => '2fdee91914a386dd95b0326a8eb201f3',
            'DATABASE_URL' => 'postgresql://app:secret@database:5432/app',
            'MAILER_DSN' => 'smtp://127.0.0.1:1025',
            'MAILER_FROM' => 'Panier Sauvage <bonjour@example.fr>',
            'STRIPE_SECRET_KEY' => 'sk_test_changeme',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_changeme',
            'STRIPE_PRICE_MONTHLY' => '',
        ]);

        self::assertSame(1, $process->getExitCode());
        $errors = $process->getErrorOutput();
        self::assertStringContainsString('APP_SECRET still holds an example value', $errors);
        self::assertStringContainsString('MAILER_DSN points to a development mailer', $errors);
        self::assertStringContainsString('MAILER_FROM still holds an example value', $errors);
        self::assertStringContainsString('STRIPE_SECRET_KEY still holds an example value', $errors);
        self::assertStringContainsString('STRIPE_WEBHOOK_SECRET does not look like a real value', $errors);
        self::assertStringContainsString('STRIPE_PRICE_MONTHLY is not set', $errors);
        self::assertStringContainsString('STRIPE_PRICE_YEARLY is not set', $errors);
        self::assertStringNotContainsString('DATABASE_URL', $errors);
        self::assertStringNotContainsString('started', $process->getOutput());
    }

    public function testCommandsThatDoNotBootTheAppAreNotChecked(): void
    {
        $process = $this->entrypoint([], ['echo', 'started']);

        self::assertSame(0, $process->getExitCode());
        self::assertSame("started\n", $process->getOutput());
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $command
     */
    private function entrypoint(array $env, array $command = ['php', '-r', 'echo "started";']): Process
    {
        // Only the given variables: nothing inherited from the test environment.
        $clean = [];
        foreach ([...array_keys(getenv()), ...array_keys($_ENV)] as $name) {
            $clean[(string) $name] = false;
        }
        $process = new Process(['sh', self::ROOT.'/docker/entrypoint.sh', ...$command], self::ROOT, ['PATH' => (string) getenv('PATH')] + $env + $clean);
        $process->run();

        return $process;
    }
}
