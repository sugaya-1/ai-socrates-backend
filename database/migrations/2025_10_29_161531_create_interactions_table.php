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
            $table->id();

            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->text('user_answer');
            $table->text('ai_response');
            $table->boolean('is_correct')->nullable();

            // ▼ ここに追加（コメントアウトを外して修正）
            // constrained() で usersテーブルと紐付け、onDelete('cascade') でユーザー削除時に履歴も消す設定にします
            $table->foreignId('user_id')
                  ->nullable()           // ログインなしでも動くようにする場合
                  ->constrained()        // usersテーブルのidと紐付け
                  ->onDelete('cascade'); // ユーザーが消えたらこの履歴も消す

            $table->timestamps();
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
