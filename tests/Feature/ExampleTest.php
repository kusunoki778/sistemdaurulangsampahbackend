<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman akar backend hanya memberi informasi status API.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertJson([
                'aplikasi' => 'Livora API',
                'status' => 'ok',
            ]);
    }
}
