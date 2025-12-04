<?php namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question;
use App\Models\Interaction;
use Illuminate\Support\Facades\Log;
// ★修正: サービスの正しい読み込み（正しいパスを使用）
use App\Services\GeminiService;

class QuestionController extends Controller
{
    /**
     * 次の問題を取得するAPIエンドポイント
     * 問題IDが変わるタイミングで、その問題の古い履歴をリセットします。
     */
    public function getNextQuestion($topicId)
    {
        $question = Question::where('topic_id', $topicId)
                            ->inRandomOrder()
                            ->first();

        if (!$question) {
            return response()->json(['message' => "Topic ID {$topicId} に関連する問題が見つかりません。"], 404);
        }

        // ★重要: 新しい挑戦のために、この問題に関する過去の会話履歴をリセット（削除）する
        Interaction::where('question_id', $question->id)->delete();

        $question->load('choices');

        return response()->json([
            'id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type,
            'choices' => $question->choices
        ]);
    }

    /**
     * 会話履歴を取得する (変更なし)
     */
    public function getHistory(int $questionId)
    {
        try {
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
     * ユーザーの回答を受け取り、GeminiServiceに処理を委譲する
     */
    public function checkAnswer(Request $request, $questionId, GeminiService $geminiService)
    {
        $userAnswerText = $request->input('answer_text');
        $question = Question::with('choices')->find($questionId);

        if (!$question || !$userAnswerText) {
            return response()->json(['message' => 'Invalid request or Question not found.'], $question ? 400 : 404);
        }

        // 1. 過去の会話履歴の取得
        $pastInteractions = Interaction::where('question_id', $questionId)
            ->orderBy('created_at', 'asc')
            ->get();

        // 2. 最初の正誤判定
        $correctChoice = $question->choices->firstWhere('is_correct', true);
        $correctAnswerText = $correctChoice ? $correctChoice->choice_text : '（正解情報なし）';
        $lowerText = strtolower($userAnswerText);
        $isCorrect = false;

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

        // 3. Gemini API 呼び出しと履歴保存のロジックをサービスに委譲
        // ★修正: サービス呼び出しに置き換え、ロジックをサービスに移譲
        $result = $geminiService->generateAndSaveResponse(
            $question->id,
            $question->question_text,
            $userAnswerText,
            $correctAnswerText,
            $isCorrect,
            $pastInteractions
        );

        // 4. 結果をJSONで返す
        // ★修正: is_sufficient フラグを含めて返す
        return response()->json([
            'question_id' => $questionId,
            'user_answer' => $userAnswerText,
            'is_correct' => $result['is_correct'],
            'explanation' => $result['explanation'],
            'is_sufficient' => $result['is_sufficient'],
        ]);
    }
}
