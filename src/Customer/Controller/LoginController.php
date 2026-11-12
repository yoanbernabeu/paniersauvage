<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Customer\Entity\Customer;
use App\Customer\Form\LoginRequestType;
use App\Customer\Service\LoginLinkSender;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Passwordless sign-in: the customer types their e-mail and receives a magic link.
 */
final class LoginController extends AbstractController
{
    use TargetPathTrait;

    private const string SENT_EMAIL_KEY = 'customer.login_email';

    public function __construct(
        private readonly RateLimiterFactoryInterface $customerLoginEmailLimiter,
        private readonly RateLimiterFactoryInterface $customerLoginIpLimiter,
        private readonly LoginLinkSender $loginLinkSender,
    ) {
    }

    #[Route('/connexion', name: 'customer_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof Customer) {
            return $this->redirectToRoute('customer_account');
        }

        $form = $this->createForm(LoginRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $email = mb_strtolower(trim(\is_string($email) ? $email : ''));

            // Both limiters are consumed whatever the address: the answer never tells whether an account exists.
            $ipLimit = $this->customerLoginIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            $emailLimit = $this->customerLoginEmailLimiter->create($email)->consume();

            if (!$ipLimit->isAccepted() || !$emailLimit->isAccepted()) {
                $form->addError(new FormError('Tu as demandé beaucoup de liens d\'un coup. Patiente quelques minutes avant de réessayer.'));

                return $this->render('customer/security/login.html.twig', ['form' => $form, 'link_error' => false], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            $this->loginLinkSender->send($email, $this->localTargetPath($request));
            $request->getSession()->set(self::SENT_EMAIL_KEY, $email);

            return $this->redirectToRoute('customer_login_sent');
        }

        return $this->render('customer/security/login.html.twig', [
            'form' => $form,
            // Set by the firewall when a magic link is expired, already replaced or tampered with.
            'link_error' => $authenticationUtils->getLastAuthenticationError() instanceof AuthenticationException,
        ]);
    }

    #[Route('/connexion/lien-envoye', name: 'customer_login_sent', methods: ['GET'])]
    public function sent(Request $request): Response
    {
        if ($this->getUser() instanceof Customer) {
            return $this->redirectToRoute('customer_account');
        }

        $email = $request->hasPreviousSession() ? $request->getSession()->get(self::SENT_EMAIL_KEY) : null;

        if (!\is_string($email) || '' === $email) {
            return $this->redirectToRoute('customer_login');
        }

        return $this->render('customer/security/login_sent.html.twig', ['email' => $email]);
    }

    /**
     * The page the visitor wanted before being sent to the sign-in screen, as a local path: it
     * travels in the magic link, so it survives when the e-mail is opened in another browser.
     */
    private function localTargetPath(Request $request): ?string
    {
        $target = $this->getTargetPath($request->getSession(), 'main');

        if (null === $target) {
            return null;
        }

        $host = parse_url($target, \PHP_URL_HOST);
        $path = parse_url($target, \PHP_URL_PATH);
        $query = parse_url($target, \PHP_URL_QUERY);

        if ((\is_string($host) && $host !== $request->getHost()) || !\is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path.(\is_string($query) && '' !== $query ? '?'.$query : '');
    }
}
