<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('choices', function (Blueprint $table) {
            $table->id(); // 選択肢番号 (id)
            $table->unsignedBigInteger('question_id'); // どの問題に紐づくか (questionsテーブルのidが入る)
            $table->text('choice_text'); // 選択肢のテキスト (例: "A) キーボード")
            $table->boolean('is_correct')->default(false); // 正解かどうか (true/false のブール(boolean)値)
            $table->text('explanation')->nullable(); // 不正解の場合の解説文 (空(null)でも良い(nullable))
            $table->timestamps(); // 作成日と更新日
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('choices');
    }
};
