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
        Schema::create('questions', function (Blueprint $table) {
           $table->id(); // 問題番号 (id)
            $table->unsignedBigInteger('topic_id'); // どのトピックに紐づくか (topicsテーブルのidが入る)
            $table->text('question_text'); // 問題文 (長い文章が入るため text)
            $table->string('question_type')->default('multiple_choice'); // 問題タイプ (今回は 'multiple_choice' で固定)
            $table->timestamps(); // 作成日と更新日
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
