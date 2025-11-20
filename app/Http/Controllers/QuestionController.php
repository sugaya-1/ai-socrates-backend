<?php namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question; // Questionモデルを使用
// ★★★ 追加: Interaction モデルを使用 ★★★
use App\Models\Interaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use pp\Services\GeminiService;

class QuestionController extends Controller
{
    /**
     * 次の問題を取得するAPIエンドポイント (トピックIDに基づいてランダムに問題を選択する)
     * @param int $topicId - 取得する問題が属するトピックのID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNextQuestion($topicId)
    {
        $question = Question::where('topic_id', $topicId)
                            ->inRandomOrder() // ランダムに1つ選択
                            ->first();

        if (!$question) {
            return response()->json(['message' => "Topic ID {$topicId} に関連する問題が見つかりません。"], 404);
        }

        // 選択肢データをロード
        $question->load('choices');

        return response()->json([
            'id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type,
            'choices' => $question->choices
        ]);
    }

    /**
     * 特定の質問IDに関連する会話履歴を取得する (★ 新規追加)
     */
    public function getHistory(int $questionId)
    {
        try {
            // question_idに紐づく全ての履歴を、古い順に取得
            $history = Interaction::where('question_id', $questionId)
                ->orderBy('created_at', 'asc')
                ->get([
                    'id',
                    'question_id',
                    'user_answer',
                    'ai_response',
                    'is_correct',
                    'created_at'
                ]);

            return response()->json([
                'history' => $history
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to retrieve conversation history: " . $e->getMessage());
            return response()->json(['error' => '履歴の取得に失敗しました。'], 500);
        }
    }


    /**
     * ユーザーの回答を受け取り、Gemini APIでAI解説を生成し、会話履歴を保存して返す
     * (★ 修正: 会話履歴をGeminiに渡すロジックを強化)
     * @param Request $request
     * @param int $questionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAnswer(Request $request, $questionId)
    {
        $userAnswerText = $request->input('answer_text');
        $question = Question::with('choices')->find($questionId);

        if (!$question || !$userAnswerText) {
            return response()->json(['message' => 'Invalid request or Question not found.'], $question ? 400 : 404);
        }

        // --- 1. 過去の会話履歴の取得と整形 (★ 修正ここから ★) ---
        // 同じ質問IDに紐づく過去のInteractionを全て取得
        $pastInteractions = Interaction::where('question_id', $questionId)
            ->orderBy('created_at', 'asc')
            ->get();

        // Geminiのcontents形式に合わせるための会話履歴の構築
        // Geminiは直近の会話履歴を元に応答を生成するため、これを適切に構成する
        $chatHistory = [
            // 質問文を最初のパートとして追加
            // role: user にすることで、AIに質問内容を認識させる
            ['role' => 'user', 'parts' => [['text' => '最初の質問: ' . $question->question_text]]],
        ];

        // 過去の対話履歴をチャット形式で追加 (User/Modelのロールを交互に設定)
        foreach ($pastInteractions as $interaction) {
            // 過去のユーザー回答
            $chatHistory[] = [
                'role' => 'user',
                'parts' => [['text' => '私の前の回答: ' . $interaction->user_answer]]
            ];
            // 過去のAI応答
            $chatHistory[] = [
                'role' => 'model',
                'parts' => [['text' => $interaction->ai_response]] // AIの応答はそのまま渡す
            ];
        }

        // ユーザーの最新の回答を追加
        $chatHistory[] = ['role' => 'user', 'parts' => [['text' => '最新の私の回答: ' . $userAnswerText]]];
        // --- 1. 過去の会話履歴の取得と整形 (★ 修正ここまで ★) ---


        // --- 2. 最初の正誤判定 (初回回答時のみの処理として継続) ---
        // このロジックは会話の履歴の有無に関わらず実行し、結果を保存する
        $correctChoice = $question->choices->firstWhere('is_correct', true);
        $correctAnswerText = $correctChoice ? $correctChoice->choice_text : '（正解情報なし）';
        $lowerText = strtolower($userAnswerText);
        $isCorrect = false;

        // ※ 判定ロジックは現状維持
        if ($correctChoice) {
            $correctKeywords = [
                strtolower(trim(str_replace(')', '', $correctAnswerText))),
                strtolower(trim(substr($correctAnswerText, 0, 1)))
            ];
            if (stripos($correctAnswerText, 'CPU') !== false) {
                $correctKeywords[] = 'cpu';
            }

            foreach ($correctKeywords as $keyword) {
                if ($keyword && str_contains($lowerText, $keyword)) {
                    $isCorrect = true;
                    break;
                }
            }
        }

        // --- 3. Gemini API 呼び出し用のプロンプト生成 ---
        $apiKey = env('GEMINI_API_KEY', '');
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . $apiKey;

        // プロンプトは、会話の状況（初回判定後か、対話中か）によってAIの役割を変える
        $latestInteractionCount = $pastInteractions->count();
        $isInitialCheck = ($latestInteractionCount === 0);

        // システムインストラクションの内容を、会話のステージによって調整する
        if ($isInitialCheck) {
            // 初回回答の時: 正誤判定結果を明確に伝え、深掘り質問を返す
            $systemInstructionText = "あなたは「AIソクラテス」という名前のAIチューターです。学習者を鼓舞し、対話を通して理解を深めることを目的としています。学習者の回答の正誤判定（正解は「{$correctAnswerText}」）を伝え、その上で学習者の理解をさらに深める**追加の質問**を、短く（3文程度まで）親しみやすい日本語で生成してください。";
        } else {
            // 2回目以降の対話中: 会話履歴を考慮して、最新のユーザー回答に対するフィードバックと次の質問を返す
            $systemInstructionText = "あなたは「AIソクラテス」という名前のAIチューターです。これまでの会話履歴を考慮し、学習者の最新の回答（「最新の私の回答」）に対する建設的なフィードバックと、**対話を継続するための次の質問**を、短く（3文程度まで）親しみやすい日本語で生成してください。";
        }

        $explanation = "AIとの対話でエラーが発生しました。";

        try {
            // Gemini API呼び出し
            $response = Http::timeout(30)->post($apiUrl, [
                'contents' => $chatHistory, // ★★★ 会話履歴をGeminiに送信 ★★★
                'systemInstruction' => [
                    'parts' => [
                        [
                            'text' => $systemInstructionText
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7 // 対話的な応答のため
                ]
            ]);

            if ($response->successful()) {
                $generatedText = $response->json('candidates.0.content.parts.0.text');
                $explanation = $generatedText ?: "AI応答取得エラー(テキストなし)";
            } else {
                Log::error('Gemini API request failed.', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                $explanation = "AI通信エラー (HTTP Status: " . $response->status() . ")";
            }
        } catch (\Exception $e) {
            Log::error('Gemini API connection error.', [
                'error' => $e->getMessage()
            ]);
            $explanation = "AI接続エラー";
        }

        // --- 会話履歴の保存処理 ---
        try {
            Interaction::create([
                'question_id' => $questionId,
                'user_answer' => $userAnswerText,
                'ai_response' => $explanation, // Gemini APIの応答を保存
                'is_correct' => $isCorrect, // 判定結果を保存
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save interaction.', [
                'question_id' => $questionId,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'question_id' => $questionId,
            'user_answer' => $userAnswerText,
            'is_correct' => $isCorrect,
            'explanation' => $explanation,
        ]);
    }
}
