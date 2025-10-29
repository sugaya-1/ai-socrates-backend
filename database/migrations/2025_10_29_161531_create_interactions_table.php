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
        Schema::create('interactions', function (Blueprint $table) {
            $table->id(); // 自動採番のID
            $table->foreignId('question_id')->constrained()->onDelete('cascade'); // どの質問に対するやり取りか (外部キー)
            $table->text('user_answer');        // ユーザーが入力したテキスト回答
            $table->text('ai_response');        // AI(Gemini)が生成した応答テキスト
            $table->boolean('is_correct')->nullable(); // 正誤判定の結果 (nullable = null許容)
            // $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // 将来的にユーザー認証を導入する場合
            $table->timestamps(); // created_at と updated_at カラム (作成日時・更新日時)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
