<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChoiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        DB::table('choices') -> insert([
                 [ // 1つ目の選択肢 (不正解)
                'question_id' => 1, // ★重要: 先にQuestionSeederで作った問題(id=1)に紐づける
                'choice_text' => 'A) キーボード',
                'is_correct' => false, // falseはDB上では 0 になります
                'explanation' => 'キーボードは情報を入力するための「手」にあたる部分です。',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [ // 2つ目の選択肢 (正解)
                'question_id' => 1,
                'choice_text' => 'B) CPU',
                'is_correct' => true, // trueはDB上では 1 になります
                'explanation' => null, // 正解なので解説は無し (null)
                'created_at' => now(),
                'updated_at' => now(),
            ]
            ]);
    }
}
