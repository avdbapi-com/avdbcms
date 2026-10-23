<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreflightPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_admin(): void
    {
        // IsAdmin middleware returns 404 for guests and non-admins so the
        // existence of the admin tree is not leaked.
        $this->get('/admin/preflight')->assertNotFound();
    }

    public function test_renders_for_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.preflight.index'))
            ->assertOk()
            ->assertSee('Environment Preflight', false)
            ->assertSee('Streaming accelerator', false);
    }

    public function test_json_endpoint_returns_structured_report(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson(route('admin.preflight.json'))
            ->assertOk()
            ->assertJsonStructure([
                'snapshot' => ['os', 'php', 'web', 'runtime'],
                'checks',
                'adjustments',
                'counts' => ['ok', 'warn', 'danger'],
                'streaming' => ['mode', 'prefix'],
                'queue' => ['connection', 'avdb_mode'],
            ]);
    }

    public function test_refresh_clears_cached_probes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.preflight.refresh'))
            ->assertRedirect(route('admin.preflight.index'));
    }

    public function test_fix_runs_repair_pass(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.preflight.fix'))
            ->assertRedirect(route('admin.preflight.index'))
            ->assertSessionHas('preflight_repairs');
    }
}
