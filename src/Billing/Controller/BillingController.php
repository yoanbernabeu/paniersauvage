<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SolidarityCriterion;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Exception\BillingGatewayException;
use App\Billing\Form\SolidarityDeclaration;
use App\Billing\Form\SolidarityDeclarationType;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Billing\Service\SolidarityPlan;
use App\Billing\Service\SolidarityPlanManager;
use App\Billing\Stripe\BillingGateway;
use App\Billing\Stripe\CheckoutRequest;
use App\Instance\InstanceSettings;
use App\Shop\Entity\User;
use App\Shop\Service\CurrentShop;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The producer's own SaaS subscription. Always the subscription of the current shop:
 * no id ever comes from the request. Only the owner of the shop manages it (members get 403).
 */
#[Route('/pro/abonnement')]
#[IsGranted(User::ROLE_SHOP_OWNER)]
final class BillingController extends AbstractController
{
    public const string CSRF_CHECKOUT = 'billing_checkout';
    public const string CSRF_PORTAL = 'billing_portal';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ShopSubscriptionRepository $subscriptions,
        private readonly BillingGateway $gateway,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly InstanceSettings $instance,
    ) {
    }

    #[Route('', name: 'pro_billing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->instance->isSponsored()) {
            return $this->render('instance/billing_sponsored.html.twig');
        }

        return $this->renderIndex($request, $this->solidarityForm());
    }

    /**
     * Declaration on honour of the solidarity plan (pay what you can, 0 to 10 € a month).
     */
    #[Route('/solidaire', name: 'pro_billing_solidarity', methods: ['POST'])]
    public function solidarity(Request $request, SolidarityPlanManager $manager): Response
    {
        if ($this->instance->isSponsored()) {
            // A sponsored instance (docs/INSTANCE.md) offers the subscription: nothing to declare.
            return $this->redirectToRoute('pro_billing', status: Response::HTTP_SEE_OTHER);
        }

        $form = $this->solidarityForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderIndex($request, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $declaration = $form->getData();

        try {
            $url = $manager->apply($this->currentShop->get(), $declaration, [
                'email' => (string) $this->currentShop->getUser()?->getEmail(),
                'success_url' => $this->generateUrl('pro_billing', ['retour' => 'succes'], UrlGeneratorInterface::ABSOLUTE_URL),
                'cancel_url' => $this->generateUrl('pro_billing', ['retour' => 'abandon'], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (BillingGatewayException $exception) {
            return $this->gatewayFailed($exception);
        }

        if (null !== $url) {
            return $this->redirect($url, Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', 0 === $declaration->amountCents()
            ? \sprintf('C\'est fait : ton palier solidaire gratuit est actif pour %d mois. 🌾', SolidarityPlan::FREE_PERIOD_MONTHS)
            : \sprintf('C\'est noté : ton abonnement solidaire passe à %d € par mois à partir de ta prochaine échéance.', $declaration->amountEuros));

        return $this->redirectToRoute('pro_billing', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/souscrire', name: 'pro_billing_checkout', methods: ['POST'])]
    public function checkout(Request $request): RedirectResponse
    {
        // Nothing to buy nor change on a sponsored instance: a plain redirect, before any token check.
        if ($this->instance->isSponsored()) {
            return $this->sponsored();
        }
        $this->denyUnlessCsrfTokenIsValid(self::CSRF_CHECKOUT, $request);

        $shop = $this->currentShop->get();
        $shopId = $shop->getId();
        $user = $this->currentShop->getUser();
        $subscription = $this->subscriptions->findOneByShop($shop);
        // The solidarity plan goes through its own declaration (pro_billing_solidarity).
        $plan = SubscriptionPlan::tryFrom($request->getPayload()->getString('plan'));
        $plan = SubscriptionPlan::Solidarity === $plan ? null : $plan;

        if (in_array(null, [$plan, $shopId, $user], true)) {
            $this->addFlash('error', 'Choisis une formule, mensuelle ou annuelle.');

            return $this->redirectToRoute('pro_billing');
        }

        if (!$this->canSubscribe($subscription)) {
            $this->addFlash('info', 'Tu as déjà un abonnement en cours. Tu peux le gérer depuis cette page.');

            return $this->redirectToRoute('pro_billing');
        }

        try {
            $url = $this->gateway->createCheckoutSession(new CheckoutRequest(
                $shopId,
                $plan,
                $user->getEmail(),
                $subscription?->getStripeCustomerId(),
                $this->generateUrl('pro_billing', ['retour' => 'succes'], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('pro_billing', ['retour' => 'abandon'], UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        } catch (BillingGatewayException $exception) {
            return $this->gatewayFailed($exception);
        }

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }

    #[Route('/gerer', name: 'pro_billing_portal', methods: ['POST'])]
    public function portal(Request $request): RedirectResponse
    {
        // Nothing to buy nor change on a sponsored instance: a plain redirect, before any token check.
        if ($this->instance->isSponsored()) {
            return $this->sponsored();
        }
        $this->denyUnlessCsrfTokenIsValid(self::CSRF_PORTAL, $request);

        $customerId = $this->subscriptions->findOneByShop($this->currentShop->get())?->getStripeCustomerId();
        if (null === $customerId) {
            $this->addFlash('info', 'Tu n\'as pas encore d\'abonnement à gérer. Choisis d\'abord une formule.');

            return $this->redirectToRoute('pro_billing');
        }

        try {
            $url = $this->gateway->createPortalSession($customerId, $this->generateUrl('pro_billing', [], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (BillingGatewayException $exception) {
            return $this->gatewayFailed($exception);
        }

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<SolidarityDeclaration> $solidarityForm
     */
    private function renderIndex(Request $request, FormInterface $solidarityForm, int $status = Response::HTTP_OK): Response
    {
        $subscription = $this->subscriptions->findOneByShop($this->currentShop->get());

        return $this->render('billing/index.html.twig', [
            'subscription' => $subscription,
            'now' => $this->clock->now(),
            'can_subscribe' => $this->canSubscribe($subscription),
            'returned_from' => $request->query->getString('retour'),
            'solidarity_form' => $solidarityForm,
            'solidarity' => [
                'min' => SolidarityPlan::MIN_EUROS,
                'max' => SolidarityPlan::MAX_EUROS,
                'suggested' => SolidarityPlan::SUGGESTED_EUROS,
                'months' => SolidarityPlan::FREE_PERIOD_MONTHS,
                'descriptions' => array_column(array_map(static fn (SolidarityCriterion $criterion): array => [$criterion->value, $criterion->description()], SolidarityCriterion::cases()), 1, 0),
            ],
        ], new Response(status: $status));
    }

    /**
     * @return FormInterface<SolidarityDeclaration>
     */
    private function solidarityForm(): FormInterface
    {
        $declaration = new SolidarityDeclaration();
        $subscription = $this->subscriptions->findOneByShop($this->currentShop->get());
        if ($subscription instanceof ShopSubscription && $subscription->isSolidarity()) {
            // A renewal or a change of amount starts from the last declaration.
            $declaration->criteria = $subscription->getSolidarityCriteria();
            $amountCents = $subscription->getAmountCents();
            $declaration->amountEuros = null === $amountCents ? SolidarityPlan::SUGGESTED_EUROS : intdiv($amountCents, 100);
        }

        return $this->createForm(SolidarityDeclarationType::class, $declaration, [
            'action' => $this->generateUrl('pro_billing_solidarity'),
        ]);
    }

    /**
     * A shop with a running Stripe subscription manages it in the portal instead of buying a second one.
     */
    private function canSubscribe(?ShopSubscription $subscription): bool
    {
        return !$subscription instanceof ShopSubscription
            || null === $subscription->getStripeSubscriptionId()
            || !\in_array($subscription->getStatus(), [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true);
    }

    private function denyUnlessCsrfTokenIsValid(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Sponsored instance: nothing to buy nor manage, Stripe is never called.
     */
    private function sponsored(): RedirectResponse
    {
        $this->addFlash('info', \sprintf('%s : tu n\'as rien à payer.', $this->instance->sponsorshipMention()));

        return $this->redirectToRoute('pro_billing');
    }

    private function gatewayFailed(BillingGatewayException $exception): RedirectResponse
    {
        $this->logger->error('Billing gateway failure: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
        $this->addFlash('error', 'Impossible de joindre Stripe pour le moment. Réessaie dans quelques minutes.');

        return $this->redirectToRoute('pro_billing');
    }
}
