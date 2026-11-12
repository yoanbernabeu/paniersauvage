<?php

declare(strict_types=1);

namespace App\Instance;

use App\Instance\Enum\AccentColor;
use App\Instance\Enum\PricingMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Settings of this deployment, read from the INSTANCE_* environment variables (docs/INSTANCE.md).
 *
 * A local authority or a territorial food project (PAT) may run its own instance of the free
 * software, with its name, logo, accent colour, territory and carrier, and may pay for the
 * subscription of its producers. It is a separate deployment of the same code, never a tenant of a
 * shared database. The defaults committed in .env give exactly Panier Sauvage: same name, sage
 * accent, all of France, no carrier, producers paying their own subscription.
 *
 * Every value is checked here: an unusable one throws InvalidInstanceSettingException (and
 * `app:instance:check`, run by the production entrypoint, refuses to start).
 */
final readonly class InstanceSettings
{
    public const string DEFAULT_NAME = 'Panier Sauvage';

    /** Name of the software, always credited on a renamed instance (AGPL, "propulsé par"). */
    public const string SOFTWARE_NAME = 'Panier Sauvage';

    public const int MAX_NAME_LENGTH = 60;

    public string $name;

    /** Path of the logo inside public/ ("images/instance/logo.svg"), null for the Panier Sauvage logo. */
    public ?string $logoPath;

    public AccentColor $accent;

    public Territory $territory;

    /** Optional words of the territory on the home page (plain text, line breaks kept). */
    public string $welcomeText;

    /** Organisation that carries the instance ("Valence Romans Agglo"), empty without carrier. */
    public string $carrierName;

    public ?string $carrierUrl;

    public PricingMode $pricing;

    public function __construct(
        #[Autowire(env: 'INSTANCE_NAME')]
        string $name = self::DEFAULT_NAME,
        #[Autowire(env: 'INSTANCE_LOGO')]
        string $logo = '',
        #[Autowire(env: 'INSTANCE_ACCENT')]
        string $accent = 'sauge',
        #[Autowire(env: 'INSTANCE_TERRITORY')]
        string $territory = '',
        #[Autowire(env: 'INSTANCE_TERRITORY_NAME')]
        string $territoryName = '',
        #[Autowire(env: 'INSTANCE_TERRITORY_CENTER')]
        string $territoryCenter = '',
        #[Autowire(env: 'INSTANCE_TERRITORY_RADIUS_KM')]
        string $territoryRadiusKm = '',
        #[Autowire(env: 'INSTANCE_WELCOME_TEXT')]
        string $welcomeText = '',
        #[Autowire(env: 'INSTANCE_CARRIER_NAME')]
        string $carrierName = '',
        #[Autowire(env: 'INSTANCE_CARRIER_URL')]
        string $carrierUrl = '',
        #[Autowire(env: 'INSTANCE_PRICING')]
        string $pricing = 'standard',
        #[Autowire('%kernel.project_dir%/public')]
        ?string $publicDir = null,
    ) {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw InvalidInstanceSettingException::for('INSTANCE_NAME', \sprintf('a name of 1 to %d characters', self::MAX_NAME_LENGTH), $name);
        }
        $this->name = $name;

        $this->logoPath = $this->logo(trim($logo), $publicDir);

        $this->accent = AccentColor::tryFrom(mb_strtolower(trim($accent)))
            ?? throw InvalidInstanceSettingException::for('INSTANCE_ACCENT', 'one of '.implode(', ', array_column(AccentColor::cases(), 'value')), $accent);

        $this->territory = Territory::fromSettings($territory, $territoryName, $territoryCenter, $territoryRadiusKm);
        $this->welcomeText = trim($welcomeText);
        $this->carrierName = trim($carrierName);

        $carrierUrl = trim($carrierUrl);
        if ('' !== $carrierUrl && 1 !== preg_match('#^https?://[^\s"<>]+$#', $carrierUrl)) {
            throw InvalidInstanceSettingException::for('INSTANCE_CARRIER_URL', 'an http(s) URL', $carrierUrl);
        }
        $this->carrierUrl = '' === $carrierUrl ? null : $carrierUrl;

        $this->pricing = PricingMode::tryFrom(mb_strtolower(trim($pricing)))
            ?? throw InvalidInstanceSettingException::for('INSTANCE_PRICING', 'standard or sponsored', $pricing);
        if (PricingMode::Sponsored === $this->pricing && '' === $this->carrierName) {
            throw InvalidInstanceSettingException::for('INSTANCE_CARRIER_NAME', 'required with INSTANCE_PRICING=sponsored (who offers the subscription)', '');
        }
    }

    /**
     * Is this instance renamed (a territorial instance)? It then credits Panier Sauvage everywhere.
     */
    public function isRenamed(): bool
    {
        return self::DEFAULT_NAME !== $this->name;
    }

    public function hasCarrier(): bool
    {
        return '' !== $this->carrierName;
    }

    /**
     * Does the carrier pay the subscription of every producer of the instance?
     */
    public function isSponsored(): bool
    {
        return PricingMode::Sponsored === $this->pricing;
    }

    /**
     * "Abonnement offert par Valence Romans Agglo", empty in the standard mode.
     */
    public function sponsorshipMention(): string
    {
        return $this->isSponsored() ? 'Abonnement offert par '.$this->carrierName : '';
    }

    private function logo(string $logo, ?string $publicDir): ?string
    {
        if ('' === $logo) {
            return null;
        }

        $logo = ltrim($logo, '/');
        if (1 !== preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*(?:\.[A-Za-z0-9_-]+)*\.(?:svg|png|webp)$#', $logo)) {
            throw InvalidInstanceSettingException::for('INSTANCE_LOGO', 'a path inside public/ to an .svg, .png or .webp file, "images/instance/logo.svg"', $logo);
        }

        if (null !== $publicDir && !is_file($publicDir.'/'.$logo)) {
            throw InvalidInstanceSettingException::for('INSTANCE_LOGO', 'no such file in public/', $logo);
        }

        return $logo;
    }
}
