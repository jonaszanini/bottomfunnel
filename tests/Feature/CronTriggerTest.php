<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CronTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_hitting_cron_run_with_correct_token_executes_scheduler(): void
    {
        $token = 'hgytrsd543T4564FGHf7tgfs635GGf';
        config(['app.cron_token' => $token]);

        $response = $this->getJson("/cron/run?token={$token}");

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Scheduler executed successfully.',
            ])
            ->assertJsonStructure([
                'ok',
                'message',
                'output',
                'time',
            ]);
    }

    public function test_hitting_cron_run_with_wrong_token_returns_403(): void
    {
        config(['app.cron_token' => 'hgytrsd543T4564FGHf7tgfs635GGf']);

        $response = $this->getJson('/cron/run?token=invalid_token_12345');

        $response->assertStatus(403);
    }

    public function test_hitting_cron_run_without_token_returns_403(): void
    {
        config(['app.cron_token' => 'hgytrsd543T4564FGHf7tgfs635GGf']);

        $response = $this->getJson('/cron/run');

        $response->assertStatus(403);
    }

    public function test_cron_run_uses_configured_default_token_fallback(): void
    {
        // When config is unset, falls back to the hardcoded default token
        config(['app.cron_token' => null]);
        config(['flowhub.cron_token' => null]);

        $defaultToken = 'hgytrsd543T4564FGHf7tgfs635GGf';

        $response = $this->getJson("/cron/run?token={$defaultToken}");

        $response->assertOk()
            ->assertJson([
                'ok' => true,
            ]);
    }

    public function test_scheduler_registers_all_required_automated_tasks(): void
    {
        $schedule = app()->make(Schedule::class);
        $events = collect($schedule->events());

        // Extract command signatures from scheduled events
        $commands = $events->map(fn ($event) => $event->command)->filter()->values();

        // 1. watching:refresh
        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, 'watching:refresh')),
            'watching:refresh is not registered in the scheduler.'
        );

        // 2. fx:refresh
        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, 'fx:refresh')),
            'fx:refresh is not registered in the scheduler.'
        );

        // 3. Listing-down safety check
        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, 'campaigns:check-listing-down')),
            'campaigns:check-listing-down is not registered in the scheduler.'
        );

        // 4. Spend bleeder check
        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, 'campaigns:check-bleeders')),
            'campaigns:check-bleeders is not registered in the scheduler.'
        );

        // 5. Shared hosting queue worker
        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, 'queue:work')),
            'queue:work is not registered in the scheduler.'
        );
    }
}
