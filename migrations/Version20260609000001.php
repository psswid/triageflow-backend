<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the system user that owns all synthetic triage submissions.
 *
 * Design decisions:
 * - Password is intentionally empty — this user never authenticates.
 *   Only scheduler tasks and admin handlers impersonate via the entity
 *   reference, never via login. The User entity implements
 *   PasswordAuthenticatedUserInterface for Symfony auth but the
 *   system user is excluded from all authentication flows.
 * - ROLE_SYSTEM distinguishes synthetic ownership from real users.
 *   The user is never loaded in a security context, so ROLE_USER
 *   is not needed. Only the UUID is used to associate submissions.
 * - created_at uses T00:00:00 as a sentinel — the system account
 *   predates all real users in the demo.
 */
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
                '2026-06-09 00:00:00'
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM users WHERE id = '00000000-0000-0000-0000-000000000001'");
    }
}
