<?php

namespace App\Services;

use App\Models\Interaction;         // 会話履歴をDBに保存するために必要
use Illuminate\Support\Collection;  // 過去の履歴を扱うリスト（配列）の型
use Illuminate\Support\Facades\Http; // 外部のAIサーバーと通信するために必要
use Illuminate\Support\Facades\Log;  // サーバーのエラーを記録するために必要

class GeminiService
{
    private string $apiUrl;
    // ★修正: 安定稼働と高速応答のため、gemini-1.5-flash を使用 ★
    private string $model = 'gemini-flash-latest';

    // サービスの初期設定（このクラスが最初に使われる時に実行される）
    public function __construct()
    {
        $apiKey = env('GEMINI_API_KEY', ''); // 環境変数からAPIキー（鍵）を取得
        // AIサーバーへのアクセスURLを組み立てる
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . $apiKey;
    }

    /**
     * AIの応答を生成し、Interaction履歴をデータベースに保存する
     */
    public function generateAndSaveResponse(
        int $questionId,        // 現在の問題ID
        string $questionText,   // 問題文
        string $userAnswerText, // ユーザーの最新の回答
        string $correctAnswerText, // 正解の選択肢テキスト
        bool $isCorrect,        // 最初の正誤判定の結果（true/false）
        Collection $pastInteractions // 過去の会話履歴のリスト
    ): array
    {

        foreach ($pastInteractions as $interaction) {
            $chatHistory[] = ['role' => 'user', 'parts' => [['text' => '私の前の回答: ' . $interaction->user_answer]]];
            $chatHistory[] = ['role' => 'model', 'parts' => [['text' => $interaction->ai_response]]];
        }

        $chatHistory[] = ['role' => 'user', 'parts' => [['text' => '最新の私の回答: ' . $userAnswerText]]];

        // --- 2. AIの指示文（性格・ルール）の生成 ---
        $baseInstruction = "あなたは「AIソクラテス」という名前のAIチューターです。古代ギリシャの哲学者ソクラテスを模した言動、性格を持ってください。

                            あなたの**最優先の目的は、学習者が ITパスポート試験の知識を【体系的・構造的】に捉え、用語間の『関連性』を説明できる状態**に導くことです。

                            以下のルールを厳格に守ってください：
                            1. 正解をそのまま教えてはいけません。しかし、学習者が答えに詰まっている場合は、**「〇〇処理装置」のように一部を隠した「穴埋めクイズ」**のようなヒントを出し、閃きを誘発することは推奨されます。（最重要）
                            2. **【核心】問いは、学習者の回答に最も不足している単一の技術用語の『定義』または『役割』に限定してください。** 一度に複数の概念の繋がりを問うて話を発散させないでください。
                            3. 学習者は、必ず現在の問題（{$questionText}）が問うている核心的なテーマに引き戻すこと。 (例: 問題が「CPU」なら『処理』、問題が「メモリ」なら『記憶』)
                            4. 学習者を導くような、簡潔な（3文程度の）日本語で話してください。
                            5. 問いは、必ずこの問題（{$questionText}）とその正解（{$correctAnswerText}）の周辺知識に厳密に限定してください。
                            6. 【禁止事項】技術の『恩恵』や『社会への影響』といった抽象的な話題には一切触れないでください。
                            7. **【超重要警告】ユーザーが入力した回答テキスト（例: 'A' や 'B'）を、画面に表示されている選択肢以外と勝手に結びつけたり、置き換えたりしてはいけません。必ずユーザーが選んだ選択肢についてのみ問答を続けてください。**
                            8. **【最終ルール】合格の敷居を下げ、過剰に難しく考えないでください。基本的な概念間の繋がりが確認できたら、すぐに \[FINAL] を出力し、次の問題へ進ませることがあなたの最優先の役割です。**";


        $isInitialCheck = ($pastInteractions->count() === 0);

        if ($isInitialCheck) {
            $taskInstruction = "学習者の最初の回答を受け取りました。あなたのタスクは、まず学習者の回答の正誤判定（正解は「{$correctAnswerText}」）
            を**「正解です」「不正解です」という言葉のみで**伝え、その上で学習者の選んだ**選択肢の機能的な根拠（定義）**を問う**追加の質問**を生成することです。
            **警告：正解のテキスト（{$correctAnswerText}）を応答に含めてはいけません。**";

            $systemInstructionText = $baseInstruction . "\n\n" . $taskInstruction;

        } else {
            $taskInstruction = "これは対話の続きです。これまでの会話履歴を考慮し、学習者の最新の回答（「最新の私の回答」）に対する建設的なフィードバックを述べてください。その際、回答に不足している**単一の**技術用語の『定義』や『役割』を特定し、その概念に焦点を絞った次の質問を生成してください。ただし、学習者が十分な理解に達したと判断できる場合は、質問ではなく、最終ルールに従って対話を終了してください。";

            $systemInstructionText = $baseInstruction . "\n\n" . $taskInstruction;
        }

        $explanation = "AIとの対話でエラーが発生しました.";

        // --- 3. Gemini API 呼び出し ---
        try {
            // 安定性向上のためタイムアウトと温度を設定
            $response = Http::timeout(60)->post($this->apiUrl, [
                'contents' => $chatHistory,
                'systemInstruction' => [
                    'parts' => [['text' => $systemInstructionText]]
                ],
                'generationConfig' => [
                    'temperature' => 0.3
                ]
            ]);

            if ($response->successful()) {
                $generatedText = $response->json('candidates.0.content.parts.0.text');
                $explanation = $generatedText ?: "AI応答取得エラー(テキストなし)";
            } else {
                Log::error('Gemini API request failed.', ['status' => $response->status(), 'response' => $response->body()]);
                $explanation = "AI通信エラー (HTTP Status: " . $response->status() . ")";
            }
        } catch (\Exception $e) {
            Log::error('Gemini API connection error.', ['error' => $e->getMessage()]);
            $explanation = "AI接続エラー";
        }

        // --- 4. 会話履歴の保存とフラグ生成 ---

        $isSufficient = false;
        $explanationText = $explanation;

        // 終了判定ロジック：AIの合図をチェック
        if (str_contains($explanation, '[FINAL]')) {
            $isSufficient = true;
            $explanationText = str_replace('[FINAL]', '', $explanation);
        }

        try {
            Interaction::create([
                'question_id' => $questionId,
                'user_answer' => $userAnswerText,
                'ai_response' => $explanationText,
                'is_correct' => $isCorrect,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save interaction.', ['question_id' => $questionId, 'error' => $e->getMessage()]);
        }

        // ★修正: コントローラーに結果を返却（is_sufficientを含む）
        return [
            'explanation' => $explanationText,
            'is_correct' => $isCorrect,
            'is_sufficient' => $isSufficient
        ];
    }
}
