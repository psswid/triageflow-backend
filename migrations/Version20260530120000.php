<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create triage_submissions table with TriageOutcome embeddable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE triage_submissions (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            status VARCHAR(20) NOT NULL,
            current_turn INT NOT NULL,
            conversation_history JSON NOT NULL,
            is_synthetic BOOLEAN NOT NULL,
            submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            outcome_specialist VARCHAR(50) DEFAULT NULL,
            outcome_urgency VARCHAR(20) DEFAULT NULL,
            outcome_justification TEXT DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_TRIAGE_SUBMISSIONS_USER_ID ON triage_submissions (user_id)');
        $this->addSql('COMMENT ON COLUMN triage_submissions.submitted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN triage_submissions.processed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE triage_submissions ADD CONSTRAINT FK_TRIAGE_SUBMISSIONS_USER FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE triage_submissions DROP CONSTRAINT FK_TRIAGE_SUBMISSIONS_USER');
        $this->addSql('DROP TABLE triage_submissions');
    }
}
