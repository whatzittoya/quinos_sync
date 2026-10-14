<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'quinos_email' => fake()->unique()->safeEmail(),
            'quinos_password' => 'secret-quinos-password',
            'timezone' => 'Asia/Makassar',
            'shift_assign_by' => 'open_time',
            'active' => true,
        ];
    }
}
