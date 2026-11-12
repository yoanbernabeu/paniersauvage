<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Shop\Entity\ShopInvitation;
use App\Shop\Entity\User;
use App\Shop\Form\InvitationAcceptanceType;
use App\Shop\Form\InvitationType;
use App\Shop\Form\Model\InvitationAcceptanceData;
use App\Shop\Form\Model\InvitationData;
use App\Shop\Repository\ShopInvitationRepository;
use App\Shop\Service\CurrentShop;
use App\Shop\Team\TeamException;
use App\Shop\Team\TeamManager;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mon équipe": the accounts of the current shop. Everybody sees the team; only the owner invites,
 * cancels an invitation or removes a member. The invitation link itself is public (the invited
 * person has no account yet).
 */
final class TeamController extends AbstractController
{
    private const string TOKEN_SESSION_KEY = 'team_invitation_token';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly TeamManager $team,
    ) {
    }

    #[Route('/pro/equipe', name: 'pro_team', methods: ['GET'])]
    public function index(ShopInvitationRepository $invitations, ClockInterface $clock): Response
    {
        return $this->renderTeam($this->createForm(InvitationType::class, new InvitationData(), ['action' => $this->generateUrl('pro_team_invite')]), $invitations, $clock);
    }

    #[Route('/pro/equipe/inviter', name: 'pro_team_invite', methods: ['POST'])]
    #[IsGranted(User::ROLE_SHOP_OWNER)]
    public function invite(Request $request, ShopInvitationRepository $invitations, ClockInterface $clock, #[CurrentUser] User $owner): Response
    {
        $data = new InvitationData();
        // Shown again after an error: the form posts to this same URL.
        $form = $this->createForm(InvitationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $invitation = $this->team->invite($this->currentShop->get(), $owner, $data->email);
                $this->addFlash('success', \sprintf('Invitation envoyée à %s. Le lien est valable %d jours.', $invitation->getEmail(), ShopInvitation::LIFETIME_DAYS));

                return $this->redirectToRoute('pro_team', status: Response::HTTP_SEE_OTHER);
            } catch (TeamException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->renderTeam($form, $invitations, $clock, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/pro/equipe/invitations/{id}/annuler', name: 'pro_team_invitation_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(User::ROLE_SHOP_OWNER)]
    public function cancel(int $id, Request $request, ShopInvitationRepository $invitations): RedirectResponse
    {
        // Another shop's invitation answers 404 before anything else.
        $invitation = $invitations->findPendingInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException('No such invitation in this shop.');
        $this->denyUnlessCsrfTokenIsValid('pro_team_invitation_cancel_'.$id, $request);

        $this->team->cancel($invitation);
        $this->addFlash('success', 'Invitation annulée : le lien ne marche plus.');

        return $this->redirectToRoute('pro_team', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/pro/equipe/membres/{id}/retirer', name: 'pro_team_member_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(User::ROLE_SHOP_OWNER)]
    public function remove(int $id, Request $request): RedirectResponse
    {
        $shop = $this->currentShop->get();
        $member = $shop->getUsers()->findFirst(static fn (int $key, User $user): bool => $user->getId() === $id) ?? throw $this->createNotFoundException('Not a member of this shop.');
        $this->denyUnlessCsrfTokenIsValid('pro_team_member_remove_'.$id, $request);

        try {
            $this->team->remove($shop, $member);
            $this->addFlash('success', \sprintf('%s ne fait plus partie de ton équipe : son accès est supprimé.', $member->getFullName()));
        } catch (TeamException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('pro_team', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * The link of the e-mail: the token moves to the session, so that it never stays in the address
     * bar, the history or a Referer header.
     */
    #[Route('/pro/invitation/{token}', name: 'pro_team_invitation_token', requirements: ['token' => '[A-Za-z0-9_-]{20,100}'], methods: ['GET'])]
    public function token(Request $request, string $token): RedirectResponse
    {
        $request->getSession()->set(self::TOKEN_SESSION_KEY, $token);

        return $this->redirectToRoute('pro_team_invitation_accept');
    }

    #[Route('/pro/invitation', name: 'pro_team_invitation_accept', methods: ['GET', 'POST'])]
    public function accept(Request $request, Security $security): Response
    {
        $token = $request->hasPreviousSession() ? $request->getSession()->get(self::TOKEN_SESSION_KEY) : null;
        $invitation = \is_string($token) ? $this->team->findUsable($token) : null;

        if (!$invitation instanceof ShopInvitation) {
            return $this->render('shop/team/invalid.html.twig', ['days' => ShopInvitation::LIFETIME_DAYS], new Response(status: Response::HTTP_GONE));
        }

        $existing = $this->team->existingAccount($invitation);
        if ($existing instanceof User) {
            // One account, one shop: the address already has its own producer space.
            return $this->render('shop/team/taken.html.twig', [
                'invitation' => $invitation,
                'same_shop' => $existing->getShop() === $invitation->getShop(),
            ], new Response(status: Response::HTTP_CONFLICT));
        }

        $data = new InvitationAcceptanceData();
        $form = $this->createForm(InvitationAcceptanceType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user = $this->team->accept($invitation, $data->firstName, $data->lastName, $data->plainPassword);
            } catch (TeamException) {
                return $this->render('shop/team/invalid.html.twig', ['days' => ShopInvitation::LIFETIME_DAYS], new Response(status: Response::HTTP_GONE));
            }

            // The token left in the session is dead: the invitation is marked accepted.
            $security->login($user, 'form_login', 'producer');
            $this->addFlash('success', \sprintf('Bienvenue dans l\'équipe de %s !', $invitation->getShop()->getName()));

            return $this->redirectToRoute('pro_dashboard');
        }

        return $this->render('shop/team/accept.html.twig', [
            'form' => $form,
            'invitation' => $invitation,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    /**
     * @param FormInterface<InvitationData> $form
     */
    private function renderTeam(FormInterface $form, ShopInvitationRepository $invitations, ClockInterface $clock, int $status = Response::HTTP_OK): Response
    {
        $shop = $this->currentShop->get();

        // The layout provides `shop` (the current one).
        return $this->render('shop/team/index.html.twig', [
            'members' => $shop->getUsers(),
            'invitations' => $invitations->findPending($shop, $clock->now()),
            'form' => $form,
            'me' => $this->currentShop->getUser(),
        ], Response::HTTP_OK === $status ? null : new Response(status: $status));
    }

    private function denyUnlessCsrfTokenIsValid(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
