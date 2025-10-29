<?php namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question; // Questionモデルを使用
// ★★★ 追加: Interaction モデルを使用 ★★★
use App\Models\Interaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QuestionController extends Controller
{
    /**
     * 次の問題を取得するAPIエンドポイント (現在はID=1の問題を固定で返す)
     * @param int $topicId - (将来的に利用)
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNextQuestion($topicId)
    {
        $question = Question::find(1);

        if ($question) {
            $question->load('choices');
        } else {
            return response()->json(['message' => 'Question with ID 1 not found.'], 404);
        }

        return response()->json([
            'id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type,
            'choices' => $question->choices
        ]);
    }

    /**
     * ユーザーの回答を受け取り、Gemini APIでAI解説を生成し、会話履歴を保存して返す
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

        // --- データベースの正解情報と連携 ---
        $correctChoice = $question->choices->firstWhere('is_correct', true);
        $correctAnswerText = $correctChoice ? $correctChoice->choice_text : '（正解情報なし）';
        $lowerText = strtolower($userAnswerText);
        $isCorrect = false;

        if ($correctChoice) {
            $correctKeywords = [
                strtolower(trim(str_replace(')', '', $correctAnswerText))),
                strtolower(trim(substr($correctAnswerText, 0, 1)))
            ];
            // 例: 正解に'CPU'が含まれる場合の追加キーワード
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

        // --- Gemini API 呼び出し用のプロンプト生成 ---
        $apiKey = env('GEMINI_API_KEY', ''); // ★★★ APIキーを設定 ★★★
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . $apiKey;

        $prompt = "あなたは「AIソクラテス」という名前の親切なAIチューターです。\n";

        if ($isCorrect) {
            $prompt .= "学習者の回答は「正解」と判定されました。\n";
            $prompt .= "素晴らしいと褒めた上で、なぜそれが正解なのか、学習者の理解をさらに深めるような追加の質問（例：「CPUが『脳』と呼ばれる理由を説明できますか？」）を、短く（2〜3文程度）親しみやすい日本語で生成してください。\n";
        } else {
            $prompt .= "学習者の回答は「不正解」と判定されました。\n";
            $prompt .= "頭ごなしに否定せず、まずは学習者の回答を受け止めてください。\n";
            $prompt .= "その上で、「正解」にたどり着けるように、ソクラテスのように対話を促すヒントや質問（例：「惜しいですね！『A』と考えた理由をもう少し詳しく教えていただけますか？」）を、短く（2〜3文程度）親しみやすい日本語で生成してください。\n";
        }

        $explanation = "AIとの対話でエラーが発生しました。";

        try {
            // Gemini API呼び出しとペイロードの補完
            $response = Http::timeout(30)->post($apiUrl, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt . "現在の質問: " . $question->question_text . "\n正解: " . $correctAnswerText . "\n学習者の回答: " . $userAnswerText
                            ]
                        ]
                    ]
                ],
                // システムインストラクションでAIのペルソナと応答形式を固定
                'systemInstruction' => [
                    'parts' => [
                        [
                            'text' => "あなたは「AIソクラテス」という名前の親切なAIチューターです。学習者を鼓舞し、対話を通して理解を深めることを目的としています。出力は常に短く、フレンドリーな日本語で、指示された追加の質問やヒントのみを返すようにしてください。会話の他の部分は含めないでください。"
                        ]
                    ]
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

        // --- ★★★ ステップ10: 会話履歴の保存処理 ★★★ ---
        try {
            Interaction::create([
                'question_id' => $questionId,
                'user_answer' => $userAnswerText,
                'ai_response' => $explanation, // Gemini APIの応答を保存
                'is_correct' => $isCorrect, // 判定結果を保存
                // 'user_id' => auth()->id(), // ログイン機能実装後
            ]);
        } catch (\Exception $e) {
            // 保存失敗時のエラーログ（処理は続行）
            Log::error('Failed to save interaction.', [
                'question_id' => $questionId,
                'error' => $e->getMessage()
            ]);
        }
        // ★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★★

        return response()->json([
            'question_id' => $questionId,
            'user_answer' => $userAnswerText,
            'is_correct' => $isCorrect,
            'explanation' => $explanation,
        ]);
    }
}
