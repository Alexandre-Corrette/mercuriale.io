<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Etablissement;
use App\Entity\Utilisateur;
use App\Repository\EtablissementRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class BonLivraisonUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Utilisateur|null $user */
        $user = $options['user'];

        $builder
            ->add('etablissement', EntityType::class, [
                'class' => Etablissement::class,
                'choice_label' => 'nom',
                'label' => 'Établissement',
                'placeholder' => 'Sélectionnez un établissement',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Veuillez sélectionner un établissement'),
                ],
                'query_builder' => function (EtablissementRepository $repo) use ($user) {
                    return $repo->createQueryBuilderForUserAccess($user);
                },
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('files', FileType::class, [
                'label' => 'Bon de livraison (images ou PDF)',
                'mapped' => false,
                'required' => true,
                'multiple' => true,
                'constraints' => [
                    new Count([
                        'min' => 1,
                        'max' => 10,
                        'minMessage' => 'Veuillez sélectionner au moins un fichier',
                        'maxMessage' => 'Vous ne pouvez pas uploader plus de {{ limit }} fichiers',
                    ]),
                    new All([
                        'constraints' => [
                            new File([
                                'maxSize' => '20M',
                                'mimeTypes' => [
                                    'image/jpeg',
                                    'image/png',
                                    'image/heic',
                                    'image/heif',
                                    'application/pdf',
                                ],
                                'mimeTypesMessage' => 'Formats acceptés : JPEG, PNG, HEIC, PDF',
                                'maxSizeMessage' => 'Le fichier est trop volumineux ({{ size }} {{ suffix }}). Taille maximale : {{ limit }} {{ suffix }}.',
                            ]),
                        ],
                    ]),
                ],
                'attr' => [
                    'accept' => 'image/jpeg,image/png,image/heic,image/heif,application/pdf',
                    'class' => 'form-control d-none',
                    'id' => 'bl-file-input',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'bl_upload',
            'user' => null,
        ]);

        $resolver->setAllowedTypes('user', ['null', Utilisateur::class]);
    }
}
