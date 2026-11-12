<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Customer\Entity\Customer;
use App\Customer\Form\LoginRequestType;
use App\Customer\Security\LocalTargetPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkExceptionInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Magic-link sign-in and logout. The POST requests are handled by the `main` firewall itself.
 */
final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    /**
     * GET: the link of the e-mail lands here. Nothing is consumed: the page asks "sign in as x@…?"
     * and its button POSTs the same URL, which the login_link authenticator handles
     * (`check_post_only`). Protects from login CSRF and from mail scanners that open every link.
     */
    #[Route('/connexion/verification', name: 'customer_login_check', methods: ['GET', 'POST'])]
    public function check(
        Request $request,
        #[Autowire(service: 'security.authenticator.login_link_handler.main')]
        LoginLinkHandlerInterface $loginLinkHandler,
        #[CurrentUser] ?Customer $current = null,
    ): Response {
        if (!$request->isMethod('GET')) {
            throw new \LogicException('The POST is intercepted by the login_link authenticator of the "main" firewall.');
        }

        $targetPath = LocalTargetPath::sanitize($request->query->get('_target_path'));

        try {
            // Only checks the signature: no `max_uses` is configured, so nothing is recorded.
            $customer = $loginLinkHandler->consumeLoginLink($request);
        } catch (InvalidLoginLinkExceptionInterface) {
            return $this->renderInvalidLink($request, $targetPath);
        }

        if (!$customer instanceof Customer) {
            return $this->renderInvalidLink($request, $targetPath);
        }

        // Already signed in with this account (e.g. a second link of the same e-mail thread).
        if ($current instanceof Customer && $current->getId() === $customer->getId()) {
            return $this->redirect($targetPath ?? $this->generateUrl('customer_account'));
        }

        return $this->privatePage($this->render('customer/security/confirm_login.html.twig', [
            'email' => $customer->getEmail(),
            'action' => $request->getRequestUri(),
            'csrf_token_id' => 'authenticate',
            'signup' => false,
        ]));
    }

    #[Route('/deconnexion', name: 'customer_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This route is intercepted by the logout listener of the "main" firewall.');
    }

    /**
     * Expired, already used or tampered link: the sign-in form, ready to send a new one that will
     * lead to the same page.
     */
    private function renderInvalidLink(Request $request, ?string $targetPath): Response
    {
        if (null !== $targetPath) {
            $this->saveTargetPath($request->getSession(), 'main', $targetPath);
        }

        $email = $request->query->get('user');
        $form = $this->createForm(LoginRequestType::class, \is_string($email) && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? ['email' => $email] : null, [
            'action' => $this->generateUrl('customer_login'),
        ]);

        return $this->privatePage($this->render('customer/security/login.html.twig', [
            'form' => $form,
            'link_error' => true,
        ]));
    }

    private function privatePage(Response $response): Response
    {
        // The URL carries a credential: no index, no cache, no Referer to the next page.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
