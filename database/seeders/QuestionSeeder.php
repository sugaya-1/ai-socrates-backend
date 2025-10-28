<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        DB::table('questions') ->insert([
            'topic_id' => 1,
           'question_text' => 'コンピュータが情報を処理するために必要な「頭」にあたる部分は、次のうちどちらかね?',
            'question_type' => 'multiple_choice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
