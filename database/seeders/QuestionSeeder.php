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
        $questions = [
            // --- 1問目 (既存の問題を修正) ---
            [
                'id' => 1, // ★★★ IDを明示 ★★★
                'topic_id' => 1,
                'question_text' => 'コンピュータが情報を処理するために必要な「頭」にあたる部分は、次のうちどちらかね?',
                'question_type' => 'multiple_choice',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // --- 2問目 (★新規追加) ---
            [
                'id' => 2, // ★★★ IDを明示 ★★★
                'topic_id' => 1, // 1問目と同じトピックに属する
                'question_text' => 'コンピュータがデータを一時的に保管する「記憶」にあたる部分は、次のうちどちらかね？',
                'question_type' => 'multiple_choice',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // 複数行をまとめて挿入
        DB::table('questions')->insert($questions);
    }
}
