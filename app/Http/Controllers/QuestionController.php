<?php // ① PHPコードの始まり

namespace App\Http\Controllers; // ② このファイルの「住所」

use App\Http\Controllers\Controller; // ③ 使う道具：基本的なコントローラー機能
use Illuminate\Http\Request;       // ④ 使う道具：リクエスト情報 (今回は使わないけどお作法)
use App\Models\Question;           // ⑤ 使う道具：「Question担当者」(モデル)
use App\Models\Topic;              // ⑥ 使う道具：「Topic担当者」(モデル)

// ⑦ 「QuestionController」という名前の「注文受付係」を作りますよ
//    基本的な受付係(Controller)の能力を引き継ぎますよ
class QuestionController extends Controller
{
    // ⑧ 「getNextQuestion」という名前の「具体的な仕事（注文処理の手順）」を定義しますよ
    //    お客さんがURLで指定したトピック番号($topicId)を、この手順の中で使いますよ
    public function getNextQuestion(string $topicId)
    {
        // --- ここから仕事の手順 ---

        // ⑨ まず、お客さんが言った番号($topicId)の「トピック」が、
        //    本当に「商品リスト（データベース）」に存在するか確認しなさい。
        //    「トピック担当者(Topic::)」に頼んで、「findOrFail（探して、無かったら『品切れです(404)』って言ってね）」しなさい。
        //    見つかったトピックは、$topic という「伝票」にメモしておきなさい。
        $topic = Topic::findOrFail($topicId);

        // ⑩ 次に、さっきメモしたトピック伝票($topic)に書かれている情報を使って、
        //    そのトピックに属する「問題」を探しなさい。
        //    トピック担当者が知っている連携機能(->questions())を使うんだ。
        //    問題を探すとき、それについている「選択肢」も一緒に(->with('choices'))探すように厨房に指示してね（二度手間にならないように）。
        //    問題がもし複数見つかっても、とりあえず最初の1つだけ(->first())でいいよ。
        //    見つかった問題を $question という「次の伝票」にメモしなさい（見つからなかったら伝票は空っぽ(null)のまま）。
        $question = $topic->questions()->with('choices')->first();

        // ⑪ 問題の伝票($question)が空っぽじゃないか確認しなさい。
        //    もし空っぽだったら(! $question)...
        if (!$question) {
            // ⑫ お客さんに「すみません、そのトピックの問題はありませんでした(404)」と
            //    JSON形式で丁重にお断りしなさい(return)。これでこの仕事は終わり。
            return response()->json(['message' => 'No questions found for this topic.'], 404);
        } // 確認終わり

        // ⑬ (もし問題が見つかっていたら)
        //    問題の伝票($question)に書かれている情報（問題文や選択肢リスト）を、
        //    お客さんが読みやすいJSON形式にきれいに整えて、
        //    「お待たせしました(200 OK)」という状態でお渡ししなさい(return)。これでこの仕事は終わり。
        return response()->json($question);

    } // ⑧ getNextQuestion 仕事の終わりの合図
} // ⑦ QuestionController クラス定義の終わりの合図
