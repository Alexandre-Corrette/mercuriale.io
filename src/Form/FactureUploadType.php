<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Repository\FournisseurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class FactureUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Utilisateur|null $user */
        $user = $options['user'];

        $builder
            ->add('fournisseur', EntityType::class, [
                'class' => Fournisseur::class,
                'choice_label' => 'nom',
                'label' => 'Fournisseur',
                'placeholder' => 'Sélectionnez un fournisseur (optionnel)',
                'required' => false,
                'query_builder' => function (FournisseurRepository $repo) use ($user) {
                    if ($user === null) {
                        return $repo->createQueryBuilder('f')->where('1 = 0');
                    }
                    $organisation = $user->getOrganisation();
                    if ($organisation === null) {
                        return $repo->createQueryBuilder('f')->where('1 = 0');
                    }
                    return $repo->createQueryBuilder('f')
                        ->join('f.etablissements', 'fe')
                        ->join('fe.organisation', 'o')
                        ->where('o = :org')
                        ->setParameter('org', $organisation)
                        ->orderBy('f.nom', 'ASC');
                },
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('fichier', FileType::class, [
                'label' => 'Facture (image ou PDF)',
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Veuillez sélectionner un fichier'),
                    new File(
                        maxSize: '20M',
                        mimeTypes: [
                            'image/jpeg',
                            'image/png',
                            'image/heic',
                            'image/heif',
                            'application/pdf',
                        ],
                        mimeTypesMessage: 'Formats acceptés : JPEG, PNG, HEIC, PDF',
                        maxSizeMessage: 'Le fichier est trop volumineux ({{ size }} {{ suffix }}). Taille maximale : {{ limit }} {{ suffix }}.',
                    ),
                ],
                'attr' => [
                    'accept' => 'image/jpeg,image/png,image/heic,image/heif,application/pdf',
                    'class' => 'form-control',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'facture_upload',
            'user' => null,
        ]);

        $resolver->setAllowedTypes('user', ['null', Utilisateur::class]);
    }
}
