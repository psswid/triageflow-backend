<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processing_duration column to triage_submissions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE triage_submissions ADD processing_duration INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE triage_submissions DROP processing_duration');
    }
}
