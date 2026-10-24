<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_loads(): void
    {
        $this->withoutVite()->get('/')->assertOk();
    }

    public function test_the_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
