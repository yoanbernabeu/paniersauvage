<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\Entity\ShopVerification;
use App\Legal\Enum\VerificationStatus;
use App\Legal\Repository\ShopVerificationRepository;
use App\Legal\Verification\RetryShopVerificationsTask;
use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Legal\Support\FakeCompanyRegistry;
use App\Tests\Legal\Support\FakeCompanyRegistryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

/**
 * Light check of the producers (SPEC §12): SIRET looked up in the public company register when it
 * is saved, directory restricted to verified shops, review by the operator.
 */
final class ShopVerificationTest extends WebTestCase
{
    use Factories;
    use FakeCompanyRegistryTrait;

    private const string FARM = '12345678900015';
    private const string SOFTWARE = '13002526500013';

    public function testAFarmIsVerifiedWhenItsSiretIsSaved(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM, 'EARL DU PONT', '01.13Z');

        $shop = $this->shopWithSiret(self::FARM);

        $verification = $this->verificationOf($shop);
        self::assertSame(VerificationStatus::Verified, $verification->getStatus());
        self::assertSame(self::FARM, $verification->getSiret());
        self::assertSame('EARL DU PONT', $verification->getCompanyName());
        self::assertSame('01.13Z', $verification->getNafCode());
        self::assertNotNull($verification->getCheckedAt());
        self::assertEquals($verification->getCheckedAt(), $verification->getVerifiedAt());
        self::assertTrue($verification->isValid());
        self::assertTrue(self::getContainer()->get(ShopVerificationRepository::class)->isVerified($shop));

        // Only the SIRET is sent, with a generic User-Agent.
        self::assertSame([self::FARM], FakeCompanyRegistry::queries());
        self::assertStringContainsString('PanierSauvage-Verifier/1.0', FakeCompanyRegistry::userAgents()[0]);
        self::assertCount(0, self::getMailerMessages());
    }

    public function testAnUnchangedSiretIsNeverCheckedAgain(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        $shop = $this->shopWithSiret(self::FARM);

        $shop->setName('Nouveau nom');
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(Shop::class, $shop->getId());
        self::assertInstanceOf(Shop::class, $reloaded);
        $reloaded->setDescription('Autre description');
        $this->entityManager()->flush();

        self::assertCount(1, FakeCompanyRegistry::queries());
    }

    public function testAnotherActivityIsPutAsideAndTheOperatorIsTold(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::SOFTWARE, 'DINUM', '84.11Z');

        $shop = $this->shopWithSiret(self::SOFTWARE, 'Faux Maraîcher');

        self::assertSame(VerificationStatus::ToReview, $this->verificationOf($shop)->getStatus());
        self::assertNull($this->verificationOf($shop)->getVerifiedAt());
        self::assertFalse($this->verificationOf($shop)->isValid());

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'contact@panier-sauvage.test');
        self::assertEmailSubjectContains($email, 'Boutique à vérifier : Faux Maraîcher');
        self::assertEmailHtmlBodyContains($email, '84.11Z');
        self::assertEmailHtmlBodyContains($email, 'app:shops:review --approve='.$shop->getSlug());
        self::assertEmailTextBodyContains($email, 'DINUM');
    }

    public function testUnknownOrClosedEstablishmentsAreNotVerified(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows('13002526500013', active: false);
        FakeCompanyRegistry::knows('12345678900015', companyActive: false);

        self::assertSame(VerificationStatus::Closed, $this->verificationOf($this->shopWithSiret('13002526500013'))->getStatus());
        self::assertSame(VerificationStatus::Closed, $this->verificationOf($this->shopWithSiret('12345678900015'))->getStatus());
        self::assertSame(VerificationStatus::NotFound, $this->verificationOf($this->shopWithSiret('82345678900010'))->getStatus());
        self::assertCount(0, self::getMailerMessages());
    }

    public function testAWrongChecksumIsRefusedWithoutCallingTheRegister(): void
    {
        self::bootKernel();

        $verification = $this->verificationOf($this->shopWithSiret('12345678900012'));

        self::assertSame(VerificationStatus::NotFound, $verification->getStatus());
        self::assertNotNull($verification->getCheckedAt());
        self::assertSame([], FakeCompanyRegistry::queries());
    }

    public function testAnOutageNeverBlocksTheSaveAndTheCommandTriesAgain(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::down();

        $shop = $this->shopWithSiret(self::FARM);
        self::assertSame(VerificationStatus::Pending, $this->verificationOf($shop)->getStatus());
        self::assertNull($this->verificationOf($shop)->getCheckedAt());

        // Still down: the command says so.
        $tester = $this->command('app:shops:verify');
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('1 still pending', $this->display($tester));

        FakeCompanyRegistry::reset();
        FakeCompanyRegistry::knows(self::FARM);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 verified', $this->display($tester));

        $this->entityManager()->clear();
        self::assertSame(VerificationStatus::Verified, $this->verificationOf($shop)->getStatus());
    }

    public function testATimeoutOrAGarbledAnswerLeavesTheVerificationPending(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::timeout();
        self::assertSame(VerificationStatus::Pending, $this->verificationOf($this->shopWithSiret(self::FARM))->getStatus());

        FakeCompanyRegistry::garbled();
        self::assertSame(VerificationStatus::Pending, $this->verificationOf($this->shopWithSiret(self::SOFTWARE))->getStatus());
    }

    public function testThePeriodicTaskChecksShopsNeverChecked(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        $shop = ShopFactory::createOne();
        // Written behind Doctrine's back: a SIRET from before the check existed.
        $this->entityManager()->getConnection()->executeStatement('UPDATE shop SET siret = ? WHERE id = ?', [self::FARM, $shop->getId()]);
        $this->entityManager()->clear();

        $counts = self::getContainer()->get(RetryShopVerificationsTask::class)();

        self::assertSame(1, $counts[VerificationStatus::Verified->value]);
        self::assertSame(0, $counts[VerificationStatus::Pending->value]);
        $reloaded = $this->entityManager()->find(Shop::class, $shop->getId());
        self::assertInstanceOf(Shop::class, $reloaded);
        self::assertTrue(self::getContainer()->get(ShopVerificationRepository::class)->isVerified($reloaded));
    }

    public function testANewSiretIsCheckedAgainAndAnEmptyOneDropsTheVerification(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        FakeCompanyRegistry::knows(self::SOFTWARE, naf: '62.01Z');
        $shop = $this->shopWithSiret(self::FARM);
        $id = $this->verificationOf($shop)->getId();

        $shop->setSiret(self::SOFTWARE);
        $this->entityManager()->flush();
        $verification = $this->verificationOf($shop);
        self::assertSame($id, $verification->getId());
        self::assertSame(self::SOFTWARE, $verification->getSiret());
        self::assertSame(VerificationStatus::ToReview, $verification->getStatus());
        self::assertSame('62.01Z', $verification->getNafCode());

        $shop->setSiret(null);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        self::assertSame(0, self::getContainer()->get(ShopVerificationRepository::class)->count([]));
    }

    public function testAVerificationForAnOlderSiretIsNotValid(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        $shop = $this->shopWithSiret(self::FARM);
        $verification = $this->verificationOf($shop);

        // Changed in memory only: the verification no longer matches.
        $shop->setSiret(self::SOFTWARE);

        self::assertFalse($verification->isValid());
        self::assertFalse(self::getContainer()->get(ShopVerificationRepository::class)->isVerified($shop));
    }

    public function testTheOperatorListsApprovesAndRejects(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::SOFTWARE, 'DINUM', '84.11Z');
        FakeCompanyRegistry::knows(self::FARM, 'SARL REVENTE', '46.31Z');
        $first = $this->shopWithSiret(self::SOFTWARE, 'Épicerie fine');
        $second = $this->shopWithSiret(self::FARM, 'Revendeur');

        $tester = $this->command('app:shops:review');
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString($first->getSlug(), $tester->getDisplay());
        self::assertStringContainsString('84.11Z', $tester->getDisplay());
        self::assertStringContainsString('SARL REVENTE', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--approve' => $first->getSlug()]));
        self::assertStringContainsString('SIRET vérifié', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--reject' => $second->getSlug(), '--note' => '  Revente de produits achetés.  ']));

        $this->entityManager()->clear();
        $approved = $this->verificationOf($first);
        self::assertSame(VerificationStatus::Verified, $approved->getStatus());
        self::assertNotNull($approved->getVerifiedAt());
        self::assertNull($approved->getReviewNote());
        $rejected = $this->verificationOf($second);
        self::assertSame(VerificationStatus::Rejected, $rejected->getStatus());
        self::assertNull($rejected->getVerifiedAt());
        self::assertSame('Revente de produits achetés.', $rejected->getReviewNote());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No shop with the status "to_review"', $this->display($tester));
        self::assertSame(0, $tester->execute(['--status' => 'rejected']));
        self::assertStringContainsString($second->getSlug(), $tester->getDisplay());
    }

    public function testADecisionSurvivesALaterCheck(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::SOFTWARE, naf: '84.11Z');
        FakeCompanyRegistry::knows(self::FARM, naf: '46.31Z');
        $approved = $this->shopWithSiret(self::SOFTWARE);
        $rejected = $this->shopWithSiret(self::FARM);
        $tester = $this->command('app:shops:review');
        $tester->execute(['--approve' => $approved->getSlug()]);
        $tester->execute(['--reject' => $rejected->getSlug()]);

        $verify = $this->command('app:shops:verify');
        self::assertSame(0, $verify->execute(['--all' => true]));
        self::assertStringContainsString('1 verified, 0 to review', $this->display($verify));
        self::assertStringContainsString('1 rejected', $this->display($verify));

        // A closure is the only thing that overrides a decision.
        FakeCompanyRegistry::knows(self::SOFTWARE, active: false);
        self::getContainer()->get('company_registry.cache')->clear();
        $verify->execute(['--all' => true]);

        $this->entityManager()->clear();
        self::assertSame(VerificationStatus::Closed, $this->verificationOf($approved)->getStatus());
        self::assertSame(VerificationStatus::Rejected, $this->verificationOf($rejected)->getStatus());
    }

    public function testReviewCommandErrors(): void
    {
        self::bootKernel();
        $tester = $this->command('app:shops:review');

        self::assertSame(2, $tester->execute(['--approve' => 'a', '--reject' => 'b']));
        self::assertStringContainsString('either --approve or --reject', $this->display($tester));
        self::assertSame(2, $tester->execute(['--status' => 'unknown']));
        self::assertStringContainsString('Unknown status "unknown"', self::display($tester));
        self::assertSame(1, $tester->execute(['--approve' => 'boutique-inconnue']));
        self::assertStringContainsString('No verification for the current SIRET of the shop "boutique-inconnue"', self::display($tester));

        // A shop without verification for its current SIRET cannot be approved.
        $shop = ShopFactory::createOne();
        self::assertSame(1, $tester->execute(['--approve' => $shop->getSlug()]));
    }

    public function testOnlyVerifiedShopsAreInTheDirectory(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        FakeCompanyRegistry::knows(self::SOFTWARE, naf: '62.01Z');
        $this->listedShop('Vérifiée', self::FARM);
        $this->listedShop('À revoir', self::SOFTWARE);
        $this->listedShop('Sans SIRET', null);
        $changed = $this->listedShop('SIRET changé', '82345678900010');
        // A verification left for an older SIRET does not count.
        $this->entityManager()->getConnection()->executeStatement(
            "UPDATE shop_verification SET status = 'verified', siret = '82345678900028' WHERE shop_id = ?",
            [$changed->getId()],
        );

        $names = array_map(
            static fn ($result): string => $result->shop->getName(),
            self::getContainer()->get(ShopRepository::class)->findNearby(43.6363, 1.3906, 30, new \DateTimeImmutable(), 50),
        );

        self::assertSame(['Vérifiée'], $names);
    }

    public function testTheSettingsShowWhereTheCheckStands(): void
    {
        $client = self::createClient();
        FakeCompanyRegistry::knows(self::SOFTWARE, naf: '62.01Z');
        $producer = UserFactory::createOne();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/boutique');
        self::assertSelectorExists('[data-verification-status="none"]');
        self::assertSelectorTextContains('label[for="shop_settings_siret"]', 'SIRET');

        $client->submitForm('Enregistrer', ['shop_settings[siret]' => self::SOFTWARE]);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-verification-status="to_review"]', 'l\'équipe vérifie ta boutique');

        $client->submitForm('Enregistrer', ['shop_settings[siret]' => '13002526500014']);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-verification-status="not_found"]', 'SIRET introuvable');
    }

    public function testTheBadgeShowsOnlyForAVerifiedShop(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        $twig = self::getContainer()->get(Environment::class);

        $verified = $this->shopWithSiret(self::FARM);
        $html = $twig->render('shop/_verified_badge.html.twig', ['shop' => $verified]);
        self::assertStringContainsString('data-verified-badge', $html);
        self::assertStringContainsString('SIRET vérifié', $html);
        self::assertStringContainsString(self::FARM, $html);

        self::assertStringNotContainsString('data-verified-badge', $twig->render('shop/_verified_badge.html.twig', ['shop' => ShopFactory::createOne()]));
        self::assertStringNotContainsString('data-verified-badge', $twig->render('shop/_verified_badge.html.twig', ['shop' => $this->shopWithSiret('82345678900010')]));
    }

    public function testTheCommandOnlyRetriesPendingChecksUnlessAskedForAll(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        $this->shopWithSiret(self::FARM);
        self::assertCount(1, FakeCompanyRegistry::queries());

        $tester = $this->command('app:shops:verify');
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 verified', self::display($tester));
        self::assertCount(1, FakeCompanyRegistry::queries());

        self::getContainer()->get('company_registry.cache')->clear();
        self::assertSame(0, $tester->execute(['--all' => true]));
        self::assertStringContainsString('1 verified', self::display($tester));
        self::assertCount(2, FakeCompanyRegistry::queries());
    }

    public function testTheCommandTellsTheOperatorOnlyOnceAboutAShopToReview(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::down();
        $shop = $this->shopWithSiret(self::SOFTWARE, 'Agence web');
        FakeCompanyRegistry::reset();
        FakeCompanyRegistry::knows(self::SOFTWARE, naf: '62.01Z');

        $tester = $this->command('app:shops:verify');
        $tester->execute([]);
        self::assertStringContainsString('1 to review', self::display($tester));
        self::assertQueuedEmailCount(1);
        self::assertEmailSubjectContains(self::getMailerMessage() ?? throw new \LogicException('No e-mail.'), 'Agence web');

        self::getContainer()->get('company_registry.cache')->clear();
        $tester->execute(['--all' => true]);
        self::assertQueuedEmailCount(1);
        self::assertSame(VerificationStatus::ToReview, $this->verificationOf($shop)->getStatus());
    }

    public function testTheCommandFollowsASiretChangedBehindItsBack(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        FakeCompanyRegistry::knows(self::SOFTWARE, naf: '10.71C');
        $shop = $this->shopWithSiret(self::FARM);
        $this->entityManager()->getConnection()->executeStatement('UPDATE shop SET siret = ? WHERE id = ?', [self::SOFTWARE, $shop->getId()]);
        $this->entityManager()->clear();

        $this->command('app:shops:verify')->execute(['--all' => true]);

        $this->entityManager()->clear();
        $verification = $this->verificationOf($shop);
        self::assertSame(self::SOFTWARE, $verification->getSiret());
        self::assertSame('10.71C', $verification->getNafCode());
        self::assertTrue($verification->isValid());
    }

    public function testShopsSavedTogetherAreAllChecked(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::knows(self::FARM);
        FakeCompanyRegistry::knows(self::SOFTWARE);
        $first = $this->shopWithSiret(self::FARM);
        $second = ShopFactory::createOne();

        $first->setSiret(null);
        $second->setSiret(self::SOFTWARE);
        $this->entityManager()->flush();

        self::assertSame(VerificationStatus::Verified, $this->verificationOf($second)->getStatus());
        self::assertNull(self::getContainer()->get(ShopVerificationRepository::class)->findOneByShop($first));
    }

    public function testTheRepositoryFindsShopsNeverChecked(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(ShopVerificationRepository::class);
        $shops = [ShopFactory::createOne(), ShopFactory::createOne(), ShopFactory::createOne()];
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('UPDATE shop SET siret = ? WHERE id IN (?, ?)', [self::FARM, $shops[0]->getId(), $shops[1]->getId()]);

        self::assertSame([$shops[0]->getId(), $shops[1]->getId()], array_map(static fn (Shop $shop): ?int => $shop->getId(), $repository->findUncheckedShops()));
        self::assertSame([], $repository->findByStatus(VerificationStatus::Verified));
        self::assertNull($repository->findOneByShop(new Shop('Pas enregistrée', 'pas-enregistree')));
    }

    public function testTheReviewListShowsUncheckedVerificationsToo(): void
    {
        self::bootKernel();
        FakeCompanyRegistry::down();
        $shop = $this->shopWithSiret(self::FARM);

        $tester = $this->command('app:shops:review');
        self::assertSame(0, $tester->execute(['--status' => 'pending']));
        $display = self::display($tester);
        self::assertStringContainsString('Slug Shop SIRET Company (register) NAF Checked at', $display);
        self::assertStringContainsString($shop->getSlug(), $display);
        self::assertMatchesRegularExpression('/'.self::FARM.' - - - /', $display);
        self::assertStringContainsString('annuaire-entreprises.data.gouv.fr', $display);

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('Slug', self::display($tester));
        self::assertStringNotContainsString('annuaire-entreprises', self::display($tester));
    }

    public function testEveryStatusExplainsItself(): void
    {
        foreach (VerificationStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
            self::assertStringEndsWith('.', $status->explanation());
        }
        self::assertSame('SIRET vérifié', VerificationStatus::Verified->label());
    }

    private function shopWithSiret(string $siret, ?string $name = null): Shop
    {
        return ShopFactory::createOne(['siret' => $siret, ...(null === $name ? [] : ['name' => $name])]);
    }

    private function listedShop(string $name, ?string $siret): Shop
    {
        $shop = ShopFactory::new()->listed()->create(['name' => $name, 'siret' => $siret]);
        ShopSubscriptionFactory::createOne(['shop' => $shop]);
        DeliveryPointFactory::new()->located(43.6363, 1.3906)->create(['shop' => $shop]);

        return $shop;
    }

    private function verificationOf(Shop $shop): ShopVerification
    {
        $verification = self::getContainer()->get(ShopVerificationRepository::class)->findOneBy(['shop' => $shop->getId()]);
        self::assertInstanceOf(ShopVerification::class, $verification);

        return $verification;
    }

    private function command(string $name): CommandTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Boot the kernel first.'));

        return new CommandTester($application->find($name));
    }

    /** The output on one line: SymfonyStyle wraps long messages. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
