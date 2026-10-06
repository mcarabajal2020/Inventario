<?php

namespace Tests\Feature;

use App\Filament\Widgets\LogoMutual;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LogoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        $user = new User(['sisusrcod' => 'test-user']);
        $user->sisusrnom = 'Usuario Test';

        return $user;
    }

    public function test_el_dashboard_muestra_el_logo_de_la_mutual(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertSee('images/fondo.jpg')
            ->assertSee('Mutual La Emancipaci', false);
    }

    public function test_el_logo_ocupa_todo_el_ancho_del_dashboard(): void
    {
        $widget = Livewire::test(LogoMutual::class)->html();

        $this->assertStringContainsString('fi-logo-mutual', $widget);
        $this->assertStringContainsString('col-span', $widget);
    }
}
