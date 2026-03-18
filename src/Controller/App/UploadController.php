<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\BonLivraison;
use App\Repository\BonLivraisonRepository;
use App\Repository\FournisseurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @deprecated Migrated to BonLivraisonUploadController, BonLivraisonExtractionController, MercurialeImportController.
 *             This class is kept temporarily for reference. All routes are now handled by the new controllers.
 */
#[IsGranted('ROLE_USER')]
class UploadController extends AbstractController
{
    // All methods have been migrated to:
    // - BonLivraisonUploadController (upload, validate, valider, rejeter, corrigerLigne, setFournisseur, batchValidate)
    // - BonLivraisonExtractionController (extraction, extraire)
    // - MercurialeImportController (mercurialeImport, mercurialeMapping, mercurialePreview, mercurialeConfirm, mercurialeResult, mercurialeCancel)
}
