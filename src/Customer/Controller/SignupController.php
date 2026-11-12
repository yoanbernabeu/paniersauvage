<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Customer\Entity\Customer;
use App\Customer\Form\LoginRequestType;
use App\Customer\Repository\CustomerRepository;
use App\Customer\Security\LocalTargetPath;
use App\Legal\LegalDocuments;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Account creation from the signed link e-mailed to an unknown address (LoginLinkSender): clicking
 * it proves the visitor owns the address. GET shows a confirmation, POST creates the account and
 * signs in, once the terms of use are accepted (date and version recorded). The link works once:
 * when the account exists, it is refused.
 */
final class SignupController extends AbstractController
{
    use TargetPathTrait;

    public const string CSRF_TOKEN_ID = 'authenticate';

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly CustomerRepository $customers,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/connexion/nouveau-compte', name: 'customer_signup', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Security $security): Response
    {
        if ($this->getUser() instanceof Customer) {
            return $this->redirectToRoute('customer_account');
        }

        $targetPath = LocalTargetPath::sanitize($request->query->get('_target_path'));
        $email = $this->email($request);

        if (null === $email || !$this->uriSigner->checkRequest($request) || $this->customers->findOneByEmail($email) instanceof Customer) {
            return $this->renderInvalidLink($request, $email, $targetPath);
        }

        if ($request->isMethod('GET')) {
            return $this->renderConfirmation($request, $email);
        }

        $token = $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, \is_string($token) ? $token : null)) {
            return $this->renderInvalidLink($request, $email, $targetPath);
        }

        if (!$request->request->getBoolean('accept_terms')) {
            return $this->renderConfirmation($request, $email, true);
        }

        $customer = new Customer($email, $this->clock->now());
        $customer->acceptTerms(LegalDocuments::TERMS_VERSION, $this->clock->now());
        try {
            $this->entityManager->persist($customer);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Created in the meantime (double click, order placed at the same moment).
            return $this->renderInvalidLink($request, $email, $targetPath);
        }

        // Fires LoginSuccessEvent: last sign-in recorded, pending follow applied.
        $security->login($customer, 'login_link', 'main');

        // Not the success handler's response: it would trust a `_target_path` sent in the form body.
        return $this->redirect($targetPath ?? $this->generateUrl('customer_account'));
    }

    private function renderConfirmation(Request $request, string $email, bool $termsError = false): Response
    {
        return $this->privatePage($this->render('customer/security/confirm_login.html.twig', [
            'email' => $email,
            'action' => $request->getRequestUri(),
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'signup' => true,
            'terms_error' => $termsError,
        ], $termsError ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null));
    }

    private function email(Request $request): ?string
    {
        $email = $request->query->get('email');
        if (!\is_string($email) || '' === $email || \count($this->validator->validate($email, [new Assert\Email(), new Assert\Length(max: 180)])) > 0) {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    private function renderInvalidLink(Request $request, ?string $email, ?string $targetPath): Response
    {
        if (null !== $targetPath) {
            $this->saveTargetPath($request->getSession(), 'main', $targetPath);
        }

        $form = $this->createForm(LoginRequestType::class, null === $email ? null : ['email' => $email], [
            'action' => $this->generateUrl('customer_login'),
        ]);

        return $this->privatePage($this->render('customer/security/login.html.twig', [
            'form' => $form,
            'link_error' => true,
        ]));
    }

    private function privatePage(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
