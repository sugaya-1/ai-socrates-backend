<?php

namespace App\Services;

use App\Models\Interaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiUrl;
    private string $model = 'gemini-flash-latest';

    public function __construct()
    {
        $apiKey = env('GEMINI_API_KEY', '');
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . $apiKey;
    }

    /**
     * AIの応答を生成し、Interaction履歴をデータベースに保存する
     */
    public function generateAndSaveResponse(
        ?int $userId, // ★ここに「?」を追加してください
        int $questionId,
        string $questionText,
        string $userAnswerText,
        string $correctAnswerText,
        bool $isCorrect,
        Collection $pastInteractions,
        $choices = null
    ): array
    {
        // 選択肢リストをテキスト化
        $choicesText = "";
        if ($choices) {
            foreach ($choices as $choice) {
                $text = $choice->choice_text ?? $choice->text ?? '';
                $choicesText .= "- {$text}\n";
            }
        }

        // 会話履歴の構築
        $chatHistory = [];
        foreach ($pastInteractions as $interaction) {
            $chatHistory[] = ['role' => 'user', 'parts' => [['text' => '私の前の回答: ' . $interaction->user_answer]]];

            // 履歴に含まれるバックスラッシュを除去してAPIエラーを防ぐ
            $cleanAiResponse = str_replace(['\\'], '', $interaction->ai_response);
            $chatHistory[] = ['role' => 'model', 'parts' => [['text' => $cleanAiResponse]]];
        }

        $chatHistory[] = ['role' => 'user', 'parts' => [['text' => '最新の私の回答: ' . $userAnswerText]]];

        // --- プロンプトの構築 ---
        $baseInstruction = "あなたは「AIソクラテス」です。

                            【現在の問題】
                            {$questionText}

                            【選択肢】
                            {$choicesText}

                            【正解】
                            {$correctAnswerText}

                            【あなたの役割】
                            ユーザーの回答「{$userAnswerText}」が、上記の「選択肢」のどれに当たるかを判断し、その用語（たとえば「プリンタ」など）について対話を行ってください。

                            **重要: 以前の問題（CPUなど）の内容と混同しないこと。必ず上記の【現在の問題】と【選択肢】に基づいて対話してください。**

                            (基本ルール)
                            1. 正解をそのまま教えない。（最重要）
                            2.足場かけ法のようなヒントや問いかけで導くことは積極的に行ってください。例えば穴埋めでヒントを出すこと、またヒントの粒度を揃える。
                            3. ユーザーがなぜその選択肢の。
                            4. 合格ラインに達したら [FINAL] を出力する。
                            ";

        $isInitialCheck = ($pastInteractions->count() === 0);

        if ($isInitialCheck) {
            $taskInstruction = "これは最初の回答です。まずは必ず正誤判定（正解は「{$correctAnswerText}」）を簡潔に伝え、
            そのあとで、学習者が選んだ用語（{$userAnswerText}に対応するもの）を何故選択肢たか、機能や定義について考えさせる短い質問をしてください。";
        } else {
            $taskInstruction = "対話の続きです。これまでの流れを踏まえ、ソクラテスとして学習者を導いてください。";
        }

        $systemInstructionText = $baseInstruction . "\n\n" . $taskInstruction;

        $explanation = "AIとの対話でエラーが発生しました.";

        // Gemini API 呼び出し
        try {
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
                $explanation = $generatedText ?: "AI応答取得エラー";
                $explanation = str_replace(['\\'], '', $explanation);

            } else {
                Log::error('Gemini API request failed.', ['status' => $response->status()]);
                $explanation = "AI通信エラー";
            }
        } catch (\Exception $e) {
            Log::error('Gemini API connection error.', ['error' => $e->getMessage()]);
            $explanation = "AI接続エラー";
        }

        // 終了判定と保存
        $isSufficient = false;
        if (str_contains($explanation, '[FINAL]')) {
            $isSufficient = true;
            $explanation = str_replace('[FINAL]', '', $explanation);
        }

        try {
            Interaction::create([
                'user_id' => $userId, // ここでユーザーIDを保存
                'question_id' => $questionId,
                'user_answer' => $userAnswerText,
                'ai_response' => $explanation,
                'is_correct' => $isCorrect,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save interaction.', ['question_id' => $questionId, 'error' => $e->getMessage()]);
        }

        return [
            'explanation' => $explanation,
            'is_correct' => $isCorrect,
            'is_sufficient' => $isSufficient
        ];
    }
}
