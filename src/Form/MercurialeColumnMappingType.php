<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\Import\ColumnMappingConfig;
use App\Entity\Unite;
use App\Repository\UniteRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MercurialeColumnMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string> $headers */
        $headers = $options['headers'];

        // Create choices for column mapping
        $columnChoices = [
            'Ignorer cette colonne' => ColumnMappingConfig::FIELD_IGNORE,
        ];

        foreach ($headers as $index => $header) {
            $label = sprintf('%s (colonne %d)', $header ?: '(vide)', $index + 1);
            $columnChoices[$label] = (string) $index;
        }

        // Field mappings
        $fields = [
            'code_fournisseur' => [
                'label' => 'Code produit fournisseur *',
                'required' => true,
            ],
            'designation' => [
                'label' => 'Désignation *',
                'required' => true,
            ],
            'prix' => [
                'label' => 'Prix unitaire HT *',
                'required' => true,
            ],
            'unite' => [
                'label' => 'Unité',
                'required' => false,
            ],
            'conditionnement' => [
                'label' => 'Conditionnement',
                'required' => false,
            ],
            'date_debut' => [
                'label' => 'Date de début',
                'required' => false,
            ],
            'date_fin' => [
                'label' => 'Date de fin',
                'required' => false,
            ],
        ];

        // Create mapping dropdowns
        foreach ($fields as $fieldName => $fieldConfig) {
            $builder->add('mapping_' . $fieldName, ChoiceType::class, [
                'label' => $fieldConfig['label'],
                'choices' => $columnChoices,
                'placeholder' => $fieldConfig['required'] ? 'Sélectionner une colonne' : 'Non mappé',
                'required' => $fieldConfig['required'],
                'attr' => [
                    'class' => 'form-select mapping-select',
                    'data-field' => $fieldName,
                ],
            ]);
        }

        // Options
        $builder
            ->add('hasHeaderRow', CheckboxType::class, [
                'label' => 'La première ligne contient les en-têtes',
                'required' => false,
                'data' => true,
                'attr' => [
                    'class' => 'form-check-input',
                ],
            ])
            ->add('defaultUnite', EntityType::class, [
                'class' => Unite::class,
                'choice_label' => fn (Unite $u) => sprintf('%s (%s)', $u->getNom(), $u->getCode()),
                'label' => 'Unité par défaut',
                'placeholder' => 'Pièce (PC)',
                'required' => false,
                'query_builder' => function (UniteRepository $repo) {
                    return $repo->createQueryBuilder('u')
                        ->orderBy('u.ordre', 'ASC');
                },
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'Utilisée si l\'unité n\'est pas spécifiée dans le fichier',
            ])
            ->add('defaultDateDebut', DateType::class, [
                'label' => 'Date de début par défaut',
                'required' => false,
                'widget' => 'single_text',
                'data' => new \DateTime(),
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'Utilisée si la date de début n\'est pas spécifiée dans le fichier',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'mercuriale_column_mapping',
            'headers' => [],
        ]);

        $resolver->setAllowedTypes('headers', 'array');
    }
}
