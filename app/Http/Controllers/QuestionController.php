<?php namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question;
use App\Models\Interaction;
use Illuminate\Support\Facades\Log;
use App\Services\GeminiService;

class QuestionController extends Controller
{
    /**
     * 指定されたIDの問題を取得する
     * * URLパラメータの $id を topic_id ではなく、
     * questionsテーブルの主キー(id)として扱って検索します。
     */
    public function getNextQuestion($id)
    {
        // topic_id ではなく id で直接問題を検索
        $question = Question::find($id);

        if (!$question) {
            return response()->json(['message' => "ID {$id} の問題が見つかりません。"], 404);
        }

        // 新しい挑戦のために、この問題に関する過去の会話履歴をリセット（削除）する
        Interaction::where('question_id', $question->id)->delete();

        $question->load('choices');

        // 次のID（現在のIDより大きい最小のID）を取得して、フロントエンドに教える
        // これにより、IDが連番でなくても（例: 1, 3, 5...）次の問題に進めるようになります
        $nextId = Question::where('id', '>', $question->id)->min('id');

        return response()->json([
            'id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type,
            'choices' => $question->choices,
            // フロントエンド側で「次の問題ID」として使用するために返却
            // (フロントエンド側の変数名 next_topic_id に合わせていますが実態は next_question_id です)
            'next_topic_id' => $nextId
        ]);
    }

    /**
     * 会話履歴を取得する
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
        $result = $geminiService->generateAndSaveResponse(
            $question->id,
            $question->question_text,
            $userAnswerText,
            $correctAnswerText,
            $isCorrect,
            $pastInteractions
        );

        // 4. 結果をJSONで返す
        return response()->json([
            'question_id' => $questionId,
            'user_answer' => $userAnswerText,
            'is_correct' => $result['is_correct'],
            'explanation' => $result['explanation'],
            'is_sufficient' => $result['is_sufficient'],
        ]);
    }
}
