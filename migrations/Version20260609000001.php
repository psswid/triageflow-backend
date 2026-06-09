<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create system user for synthetic case generation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO users (id, email, roles, password, created_at)
            VALUES (
                '00000000-0000-0000-0000-000000000001',
                'system@triageflow.local',
                '[\"ROLE_SYSTEM\"]',
                '',
                '2026-06-09T00:00:00+00:00'
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM users WHERE id = '00000000-0000-0000-0000-000000000001'");
    }
}
