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

        // プロンプト構築
        $baseInstruction = "あなたは「AIソクラテス」です。古代ギリシャの哲学者のような知的さと、AIらしい親しみやすさを兼ね備えた口調で話してください。

                            【現在の問題】
                            {$questionText}

                            【選択肢】
                            {$choicesText}

                            【正解】
                            {$correctAnswerText}

                            【あなたの役割】
                            ユーザーの回答「{$userAnswerText}」を受け取り、対話を通じて学習者の理解を深めてください。

                            **重要: 以前の問題（CPUなど）の内容と混同しないこと。必ず上記の【現在の問題】に基づいて対話してください。**

                            (基本ルール)
                            1. 正解そのものをすぐに教えてはいけません。（最重要）
                            2. 「足場かけ（Scaffolding）」を用いて導いてください。答えに詰まっている場合は、穴埋め形式や、「人間の体でいうと？」のような比喩を使ってヒントを出してください。
                            3. ユーザーが「なぜその選択肢を選んだのか」、その根拠や定義を言語化させるように促してください。
                            4. 学習者が概念を正しく理解し、合格ラインに達したと判断したら、文末に [FINAL] を出力してください。";

        $isInitialCheck = ($pastInteractions->count() === 0);

        if ($isInitialCheck) {
            // 初回フェーズ
            $taskInstruction = "
            【状況】これは学習者の『最初の回答』です。

            【タスク】
            1. まず、ユーザーの回答が正解か不正解かを、ハッキリと伝えてください。
            2. その後、選んだ選択肢について「なぜそれを選んだのか？」や「その用語の役割は何か？」といった、思考を促す短い質問を投げかけてください。";
        } else {
            // 深掘りフェーズ
            $taskInstruction = "
            【状況】これは対話の続き（深掘りフェーズ）です。
            学習者はすでに選択肢を選び終え、その理由や定義について説明しようとしています。

            【禁止事項】
            ・「どの選択肢を選びましたか？」と再度確認してはいけません。
            ・「Aですか？Bですか？」と記号での回答を求めてはいけません。

            【タスク】
            ユーザーの説明（「{$userAnswerText}」）の内容が、技術的に正しいかを評価してください。
            ・説明が正しい場合 → 大いに褒めて、さらに補足知識を問うか、[FINAL]を出してください。
            ・説明が誤り・不足の場合 → 否定せず、「では、〇〇という観点ではどうだろう？」と別の角度からヒントを出してください。";
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
