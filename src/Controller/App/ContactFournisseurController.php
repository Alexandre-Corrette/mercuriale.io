<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\ContactFournisseur;
use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Form\ContactFournisseurType;
use App\Repository\ContactFournisseurRepository;
use App\Security\Voter\FournisseurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/fournisseurs/{id}/contacts', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
class ContactFournisseurController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContactFournisseurRepository $contactRepo,
    ) {
    }

    #[Route('', name: 'app_fournisseur_contact_create', methods: ['POST'])]
    public function create(Fournisseur $fournisseur, Request $request): Response
    {
        $this->denyAccessUnlessGranted(FournisseurVoter::EDIT, $fournisseur);

        $contact = new ContactFournisseur();
        $contact->setFournisseur($fournisseur);

        $form = $this->createForm(ContactFournisseurType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->wrapInTransaction(function () use ($contact, $fournisseur): void {
                if ($contact->isPrincipal()) {
                    $this->demoteCurrentPrimary($fournisseur);
                }
                $this->entityManager->persist($contact);
            });

            $this->addFlash('success', 'Contact ajoute avec succes.');
        } else {
            $this->addFlash('error', 'Erreur lors de l\'ajout du contact.');
        }

        return $this->redirectToRoute('app_fournisseur_show', ['id' => $fournisseur->getId()]);
    }

    #[Route('/{contactId}/edit', name: 'app_fournisseur_contact_edit', methods: ['POST'], requirements: ['contactId' => '[0-9a-f-]+'])]
    public function edit(Fournisseur $fournisseur, string $contactId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(FournisseurVoter::EDIT, $fournisseur);

        $contact = $this->findContactOrThrow($fournisseur, $contactId);

        $form = $this->createForm(ContactFournisseurType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->wrapInTransaction(function () use ($contact, $fournisseur): void {
                if ($contact->isPrincipal()) {
                    $this->demoteCurrentPrimary($fournisseur, $contact);
                }
                $this->entityManager->flush();
            });

            $this->addFlash('success', 'Contact modifie avec succes.');
        } else {
            $this->addFlash('error', 'Erreur lors de la modification du contact.');
        }

        return $this->redirectToRoute('app_fournisseur_show', ['id' => $fournisseur->getId()]);
    }

    #[Route('/{contactId}/delete', name: 'app_fournisseur_contact_delete', methods: ['POST'], requirements: ['contactId' => '[0-9a-f-]+'])]
    public function delete(Fournisseur $fournisseur, string $contactId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(FournisseurVoter::EDIT, $fournisseur);

        $contact = $this->findContactOrThrow($fournisseur, $contactId);

        if (!$this->isCsrfTokenValid('delete_contact_' . $contactId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_fournisseur_show', ['id' => $fournisseur->getId()]);
        }

        $this->entityManager->remove($contact);
        $this->entityManager->flush();

        $this->addFlash('success', 'Contact supprime.');

        return $this->redirectToRoute('app_fournisseur_show', ['id' => $fournisseur->getId()]);
    }

    #[Route('/{contactId}/set-primary', name: 'app_fournisseur_contact_set_primary', methods: ['POST'], requirements: ['contactId' => '[0-9a-f-]+'])]
    public function setPrimary(Fournisseur $fournisseur, string $contactId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(FournisseurVoter::EDIT, $fournisseur);

        $contact = $this->findContactOrThrow($fournisseur, $contactId);

        if (!$this->isCsrfTokenValid('set_primary_' . $contactId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_fournisseur_show', ['id' => $fournisseur->getId()]);
        }

        $this->entityManager->wrapInTransaction(function () use ($contact, $fournisseur): void {
            $this->demoteCurrentPrimary($fournisseur);
            $contact->setPrincipal(true);
        });

        $this->addFlash('success', sprintf('%s est maintenant le contact principal.', $contact->getNomComplet()));

        return $this->redirectToRoute('app_fournisseur_show', ['id' => $fournisseur->getId()]);
    }

    private function findContactOrThrow(Fournisseur $fournisseur, string $contactId): ContactFournisseur
    {
        $contact = $this->contactRepo->find($contactId);

        if ($contact === null || $contact->getFournisseur()?->getId() !== $fournisseur->getId()) {
            throw $this->createNotFoundException('Contact introuvable.');
        }

        return $contact;
    }

    private function demoteCurrentPrimary(Fournisseur $fournisseur, ?ContactFournisseur $except = null): void
    {
        foreach ($fournisseur->getContacts() as $existing) {
            if ($existing->isPrincipal() && $existing !== $except) {
                $existing->setPrincipal(false);
            }
        }
    }
}