<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260318101024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MERC-122: Add type_etablissement (enum) and code_naf fields to etablissement';
    }

    public function up(Schema $schema): void
    {
        $columns = array_map(fn ($c) => $c->getName(), $this->connection->createSchemaManager()->listTableColumns('etablissement'));
        $this->skipIf(in_array('type_etablissement', $columns, true), 'Columns already exist');

        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE etablissement ADD type_etablissement VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE etablissement ADD code_naf VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE etablissement DROP type_etablissement');
        $this->addSql('ALTER TABLE etablissement DROP code_naf');
    }
}
