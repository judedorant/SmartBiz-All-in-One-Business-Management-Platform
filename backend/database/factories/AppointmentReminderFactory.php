<?php

namespace Database\Factories;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentReminderFactory extends Factory
{
    public function definition(): array
    {
        return ['appointment_id' => Appointment::factory(), 'appointment_version' => 1, 'status' => 'pending'];
    }
}
