<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Repository\EtablissementRepository;
use App\Repository\FournisseurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class MercurialeImportUploadType extends AbstractType
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
                'placeholder' => 'Sélectionnez un fournisseur',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Veuillez sélectionner un fournisseur'),
                ],
                'query_builder' => function (FournisseurRepository $repo) use ($user) {
                    return $repo->createQueryBuilderForUserAccess($user);
                },
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('etablissement', EntityType::class, [
                'class' => Etablissement::class,
                'choice_label' => 'nom',
                'label' => 'Établissement (optionnel)',
                'placeholder' => 'Prix groupe (tous établissements)',
                'required' => false,
                'query_builder' => function (EtablissementRepository $repo) use ($user) {
                    return $repo->createQueryBuilderForUserAccess($user);
                },
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'Laissez vide pour appliquer les prix à tous les établissements du groupe',
            ])
            ->add('file', FileType::class, [
                'label' => 'Fichier mercuriale (CSV ou Excel)',
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Veuillez sélectionner un fichier'),
                    new File([
                        'maxSize' => '10M',
                        'mimeTypes' => [
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ],
                        'mimeTypesMessage' => 'Formats acceptés : CSV, XLSX',
                        'maxSizeMessage' => 'Le fichier est trop volumineux ({{ size }} {{ suffix }}). Taille maximale : {{ limit }} {{ suffix }}.',
                    ]),
                ],
                'attr' => [
                    'accept' => '.csv,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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
            'csrf_token_id' => 'mercuriale_import_upload',
            'user' => null,
        ]);

        $resolver->setAllowedTypes('user', ['null', Utilisateur::class]);
    }
}
