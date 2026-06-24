<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_profile_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Meu perfil');
    }

    public function test_user_can_update_name_email_and_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($user)
            ->put('/profile', [
                'name' => 'Parent User',
                'email' => 'parent@example.com',
                'current_password' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Parent User', $user->name);
        $this->assertSame('parent@example.com', $user->email);
        $this->assertTrue(Hash::check('new-password', $user->password));
    }
}
