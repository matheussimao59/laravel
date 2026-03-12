<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upsert_and_read_app_settings(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/settings/test_setting', [
            'config_data' => [
                'foo' => 'bar',
                'enabled' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('setting.id', 'test_setting')
            ->assertJsonPath('setting.config_data.foo', 'bar');

        $this->getJson('/api/settings/test_setting')
            ->assertOk()
            ->assertJsonPath('setting.config_data.enabled', true);
    }

    public function test_authenticated_user_can_create_and_list_pricing_materials(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pricing/materials', [
            'name' => 'Papel Offset 180g',
            'unit_cost' => 3.75,
            'unit_of_measure' => 'folha',
        ])
            ->assertCreated()
            ->assertJsonPath('material.name', 'Papel Offset 180g')
            ->assertJsonPath('material.unit_cost', 3.75);

        $this->getJson('/api/pricing/materials')
            ->assertOk()
            ->assertJsonCount(1, 'materials')
            ->assertJsonPath('materials.0.unit_of_measure', 'folha');
    }
}
