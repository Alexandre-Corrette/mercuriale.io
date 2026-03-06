<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\ContactFournisseur;
use App\Entity\Fournisseur;
use App\Entity\OrganisationFournisseur;
use App\Entity\Utilisateur;
use App\Form\ContactFournisseurType;
use App\Form\FournisseurCreateType;
use App\Repository\AvoirFournisseurRepository;
use App\Repository\BonLivraisonRepository;
use App\Repository\ContactFournisseurRepository;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Security\Voter\FournisseurVoter;
use App\Service\SirenApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/fournisseurs')]
#[IsGranted('ROLE_USER')]
class FournisseurController extends AbstractController
{
    public function __construct(
        private readonly FournisseurRepository $fournisseurRepo,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContactFournisseurRepository $contactRepo,
    ) {
    }

    #[Route('', name: 'app_fournisseurs_hub', methods: ['GET'])]
    public function hub(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/fournisseur/hub.html.twig', [
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
        ]);
    }

    #[Route('/nouveau', name: 'app_fournisseur_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted(FournisseurVoter::CREATE);

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $fournisseur = new Fournisseur();
        $form = $this->createForm(FournisseurCreateType::class, $fournisseur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $orgFournisseur = new OrganisationFournisseur();
            $orgFournisseur->setOrganisation($org);
            $orgFournisseur->setFournisseur($fournisseur);
            $orgFournisseur->setActif(true);

            $this->entityManager->wrapInTransaction(function () use ($fournisseur, $orgFournisseur): void {
                $this->entityManager->persist($fournisseur);
                $this->entityManager->persist($orgFournisseur);
            });

            $this->addFlash('success', 'Fournisseur cree avec succes.');

            return $this->redirectToRoute('app_fournisseur_show', ['id' => $fournisseur->getId()]);
        }

        return $this->render('app/fournisseur/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/siren-lookup', name: 'app_fournisseur_siren_lookup', methods: ['GET'])]
    public function sirenLookup(Request $request, SirenApiService $sirenApi): JsonResponse
    {
        $this->denyAccessUnlessGranted(FournisseurVoter::CREATE);

        $query = $request->query->getString('q');

        if ($query === '') {
            return $this->json(['error' => 'Parametre q requis.'], Response::HTTP_BAD_REQUEST);
        }

        $result = $sirenApi->lookup($query);

        if ($result === null) {
            return $this->json(['error' => 'Aucune entreprise trouvee.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($result);
    }

    #[Route('/liste', name: 'app_fournisseurs_liste', methods: ['GET'])]
    public function liste(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/fournisseur/liste.html.twig', [
            'fournisseurs' => $this->fournisseurRepo->findWithStatsForOrganisation($org),
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
        ]);
    }

    #[Route('/{id}', name: 'app_fournisseur_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Fournisseur $fournisseur,
        ProduitFournisseurRepository $produitRepo,
        BonLivraisonRepository $blRepo,
        AvoirFournisseurRepository $avoirRepo,
    ): Response {
        $this->denyAccessUnlessGranted('VIEW', $fournisseur);

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $contactForm = $this->createForm(ContactFournisseurType::class, new ContactFournisseur(), [
            'action' => $this->generateUrl('app_fournisseur_contact_create', ['id' => $fournisseur->getId()]),
        ]);

        return $this->render('app/fournisseur/show.html.twig', [
            'fournisseur' => $fournisseur,
            'produits' => $produitRepo->findByFournisseur($fournisseur),
            'bons_livraison' => $blRepo->findRecentByFournisseurForOrganisation($fournisseur, $org),
            'avoirs' => $avoirRepo->findByFournisseurForOrganisation($fournisseur, $org),
            'total_avoirs_imputes' => $avoirRepo->sumImputesByFournisseurForOrganisation($fournisseur, $org),
            'contacts' => $this->contactRepo->findByFournisseur($fournisseur),
            'contact_form' => $contactForm,
        ]);
    }

    #[Route('/{id}/contacts', name: 'app_fournisseur_contact_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function contactCreate(Fournisseur $fournisseur, Request $request): Response
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

    #[Route('/{id}/contacts/{contactId}/edit', name: 'app_fournisseur_contact_edit', methods: ['POST'], requirements: ['id' => '\d+', 'contactId' => '[0-9a-f-]+'])]
    public function contactEdit(Fournisseur $fournisseur, string $contactId, Request $request): Response
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

    #[Route('/{id}/contacts/{contactId}/delete', name: 'app_fournisseur_contact_delete', methods: ['POST'], requirements: ['id' => '\d+', 'contactId' => '[0-9a-f-]+'])]
    public function contactDelete(Fournisseur $fournisseur, string $contactId, Request $request): Response
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

    #[Route('/{id}/contacts/{contactId}/set-primary', name: 'app_fournisseur_contact_set_primary', methods: ['POST'], requirements: ['id' => '\d+', 'contactId' => '[0-9a-f-]+'])]
    public function contactSetPrimary(Fournisseur $fournisseur, string $contactId, Request $request): Response
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
