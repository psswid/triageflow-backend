<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SmokeTest extends KernelTestCase
{
    public function testInfrastructureWorks(): void
    {
        self::bootKernel();
        $this->assertNotEmpty(self::$kernel->getEnvironment());
    }
}
