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
        // データを配列の配列として定義
        $choices = [
            // --- 1問目の選択肢 (既存) ---
            [
                'question_id' => 1,
                'choice_text' => '(A) キーボード',
                'is_correct' => false,
                'explanation' => 'キーボードは情報を入力するための「手」にあたる部分です。',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => 1,
                'choice_text' => '(B) CPU',
                'is_correct' => true,
                'explanation' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // ★★★ 2問目の選択肢 (新規追加: 記憶の問題) ★★★
            [
                'question_id' => 2, // ★問題IDを2に設定★
                'choice_text' => '(A) RAM (メモリ)',
                'is_correct' => true,
                'explanation' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => 2, // ★問題IDを2に設定★
                'choice_text' => '(B) プリンタ',
                'is_correct' => false,
                'explanation' => 'プリンタは情報を出力するための装置です。',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // 複数行をまとめて挿入
        DB::table('choices') -> insert($choices);
    }
}
