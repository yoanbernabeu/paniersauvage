<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\Entity\ShopVerification;
use App\Legal\Enum\VerificationStatus;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\TestCase;

final class ShopVerificationEntityTest extends TestCase
{
    private const string SIRET = '12345678900015';

    public function testANewCheckKeepsTheDateOfTheFirstVerification(): void
    {
        $first = new \DateTimeImmutable('2026-10-01 10:00');
        $later = new \DateTimeImmutable('2026-11-01 10:00');
        $verification = ShopVerification::verified($this->shop(), self::SIRET, 'EARL', '01.13Z', $first);

        $verification->record(VerificationStatus::Verified, 'EARL DU PONT', '01.13Z', $later);

        self::assertEquals($first, $verification->getVerifiedAt());
        self::assertEquals($later, $verification->getCheckedAt());
        self::assertSame('EARL DU PONT', $verification->getCompanyName());

        $verification->record(VerificationStatus::Closed, 'EARL DU PONT', '01.13Z', $later);
        self::assertNull($verification->getVerifiedAt());
    }

    public function testStartingOverForAnotherSiretForgetsEverything(): void
    {
        $verification = ShopVerification::verified($this->shop(), self::SIRET, 'EARL', '01.13Z', new \DateTimeImmutable());
        $verification->decide(false, 'Revente', new \DateTimeImmutable());

        $verification->restartFor('13002526500013');

        self::assertSame('13002526500013', $verification->getSiret());
        self::assertSame(VerificationStatus::Pending, $verification->getStatus());
        self::assertNull($verification->getCompanyName());
        self::assertNull($verification->getNafCode());
        self::assertNull($verification->getCheckedAt());
        self::assertNull($verification->getVerifiedAt());
        self::assertNull($verification->getReviewNote());
    }

    public function testTheOperatorNoteIsTrimmedAndBounded(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 09:00');
        $verification = new ShopVerification($this->shop(), self::SIRET);

        $verification->decide(true, '   ', $at);
        self::assertNull($verification->getReviewNote());
        self::assertSame(VerificationStatus::Verified, $verification->getStatus());
        self::assertEquals($at, $verification->getVerifiedAt());

        $verification->decide(false, ' '.str_repeat('é', 600).' ', $at);
        self::assertSame(str_repeat('é', 500), $verification->getReviewNote());
        self::assertSame(VerificationStatus::Rejected, $verification->getStatus());
        self::assertNull($verification->getVerifiedAt());

        $verification->decide(true, null, $at);
        self::assertNull($verification->getReviewNote());
    }

    private function shop(): Shop
    {
        return new Shop('Ferme', 'ferme')->setSiret(self::SIRET);
    }
}
