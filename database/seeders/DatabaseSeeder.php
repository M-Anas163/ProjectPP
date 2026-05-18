<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        Product::create([
            'name'           => 'منتج تنافسي (آخر حبة)',
            'description'    => 'نختبر به السباق التجاري',
            'price'          => 1500.00,
            'stock_quantity' => 1,
            'is_active'      => true,
        ]);

        Product::create([
            'name'           => 'منتج للضغط العالي',
            'description'    => 'نختبر به طوابير الـ NFR2',
            'price'          => 50.00,
            'stock_quantity' => 5000,
            'is_active'      => true,
        ]);
    }
}
