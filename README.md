# Mercuriale.io

Application de contrôle des bons de livraison et gestion de mercuriale pour la restauration.

Développée pour le **Groupe Horao** (multi-établissements).

## Prérequis

- Docker & Docker Compose
- PHP 8.2+
- Composer

## Stack technique

- **Framework** : Symfony 7.4
- **Base de données** : MySQL 8.0
- **Front** : Twig + Stimulus
- **Back-office** : EasyAdmin 4
- **API** : API Platform 4
- **Authentification** : JWT (Lexik)
- **Tests** : PHPUnit 12
- **Qualité** : PHPStan niveau 6

## Installation

### 1. Cloner le repository

```bash
git clone git@github.com:groupe-horao/mercuriale-io.git
cd mercuriale-io
```

### 2. Démarrer les conteneurs Docker

```bash
docker-compose up -d --build
```

### 3. Installer les dépendances

```bash
docker-compose exec php composer install
```

### 4. Créer la base de données et exécuter les migrations

```bash
docker-compose exec php bin/console doctrine:database:create --if-not-exists
docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

### 5. Générer les clés JWT

```bash
docker-compose exec php bin/console lexik:jwt:generate-keypair
```

### 6. Charger les fixtures (dev uniquement)

```bash
docker-compose exec php bin/console doctrine:fixtures:load --no-interaction
```

## Accès

- **Application** : http://localhost:8080
- **Mailpit** : http://localhost:8025

## Commandes utiles

```bash
# Lancer les tests
docker-compose exec php bin/phpunit

# Analyse statique PHPStan
docker-compose exec php vendor/bin/phpstan analyse

# Créer une migration
docker-compose exec php bin/console make:migration

# Vider le cache
docker-compose exec php bin/console cache:clear
```

## Structure du projet

```
src/
├── Controller/
│   ├── Admin/          # Controllers EasyAdmin
│   ├── App/            # Controllers applicatifs
│   └── Api/            # Controllers API
├── Entity/             # Entités Doctrine
├── Repository/         # Repositories
├── Service/
│   ├── Ocr/            # Extraction OCR
│   ├── Controle/       # Moteur de contrôle prix/quantités
│   └── Import/         # Import mercuriale (CSV/Excel)
├── Security/
│   └── Voter/          # Contrôle d'accès multi-établissements
├── EventListener/      # Listeners Doctrine
└── Twig/
    └── Components/     # Composants Twig réutilisables
```

## Licence

Propriétaire - Groupe Horao - Tous droits réservés.
