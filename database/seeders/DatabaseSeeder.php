<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            TopicSeeder::class,    // 1. トピック (親) を先に入れる
            QuestionSeeder::class, // 2. 問題 (子) を次に入れる
            ChoiceSeeder::class,   // 3. 選択肢 (孫) を最後に入れる
        ]);
    }
}
