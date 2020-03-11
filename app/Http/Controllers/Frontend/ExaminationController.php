<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Model\Examination;
use App\Model\ExamNotification;
use App\Model\Question;
use App\Model\QuestionTemplate;
use Auth;
use Illuminate\Http\Request;
use Session;

class ExaminationController extends Controller
{
    public function prepareExam()
    {
        //check any exam already started
        $current_date = date('Y-m-d H:i:00');
        $student_info = Session::get('question_paper_info');
        $already_exam_participate = NULL;

        $already_started_exam = ExamNotification::where('start_date', '<=', $current_date)
            ->where('end_date', '>=', $current_date)->orderBy('start_date', 'ASC')->first();

        if($student_info){
            $already_exam_participate = Examination::where('user_id', $student_info['student_id'])
                ->where('exam_notification_id', $student_info['exam_notification_id'])->first();
        }

        if ($already_started_exam and $already_exam_participate == NULL){
            $exam_notification = $already_started_exam;
            $start_exam = true;
            return view('frontend.examination.prepare', compact('start_exam','exam_notification'));
        }

        $exam_notification = ExamNotification::where('start_date', '>', $current_date)->OrderBy('start_date', 'ASC')->first();
        //$exam_notification = ExamNotification::latest()->first();
        //no examination found in database
        if (!$exam_notification){
            Session::flash('limit_cross', 'Now you have no examination.');
            return view('frontend.examination.prepare', compact('exam_notification'));
        }

        $start_date = $exam_notification->start_date->format('Y-m-d H:i:s');
        $end_date = $exam_notification->end_date->format('Y-m-d H:i:s');
        $current_date = date('Y-m-d H:i:00');


        //exam already finished
        if (strtotime($current_date) > strtotime($end_date)){
            Session::flash('limit_cross', 'Now you have no examination.');
            return view('frontend.examination.prepare', compact('exam_notification'));
        }

        //exam wait for starting
        if (strtotime($current_date) >= strtotime($start_date) && strtotime($current_date) <= strtotime($end_date)){
            $start_exam = true;
            return view('frontend.examination.prepare', compact('start_exam', 'exam_notification'));
        }

        return view('frontend.examination.prepare', compact('exam_notification'));
    }

    public function startExam($exam_notification_id)
    {
        $current_date = date('Y-m-d H:i:00');
        if($exam_notification_id){
            $exam_notification = ExamNotification::where('id', $exam_notification_id)->first();
        }else{
            $exam_notification = ExamNotification::where('start_date', '<=', $current_date)
                ->where('end_date', '>=', $current_date)->OrderBy('start_date', 'ASC')->first();
            $exam_notification_id = $exam_notification->id;
        }
        $subject_id = $exam_notification->template->subject_id;

        $question_template = QuestionTemplate::withCount('questions')->where('subject_id', $subject_id)->first();

        $examination = Examination::create([
            'user_id' => Auth::id(),
            'subject_id' => $subject_id,
            'exam_notification_id' => $exam_notification_id,
        ]);

        $question_paper_info = [
            'question_paper_type' => 'examination',
            'examination_id' => $examination->id,
            'student_id' => Auth::id(),
            'subject_id' => $subject_id,
            'exam_notification_id' => $exam_notification_id,
            'generated_question_ids' => [],
            'question_quantity' => $question_template->questions_count > $question_template->total_questions ? $question_template->total_questions : $question_template->questions_count
        ];

        Session::put('question_paper_info', []);
        Session::put('question_paper_info', $question_paper_info);
        return redirect()->route('examination.question');
    }

    public function question()
    {
        $question_paper_info = Session::get('question_paper_info');

        //check has selected any subject for question
        if ($question_paper_info == []){  return redirect()->route('examination.start'); }

        //check limit cross
        if ($question_paper_info['question_quantity'] == 0){
            return redirect()->route('examination.summery');
        }

        $subject_id = $question_paper_info['subject_id'];
        $generated_question_ids = $question_paper_info['generated_question_ids'];

        //generate question
        $question = Question::WhereHas('template', function ($query) use ($subject_id) {
            $query->where('subject_id', $subject_id);
        })->whereNotIn('id', $generated_question_ids)->active()->inRandomOrder()->take(1)->first();

        //store question id to prevent generate same question
        array_push($question_paper_info['generated_question_ids'], $question->id);
        $question_paper_info['question_quantity']--;
        Session::put('question_paper_info', $question_paper_info);

        $question_options = $question->options;
        $correct_answers = $student_answer = [];

        return view('frontend.question.question', compact('question', 'question_options', 'correct_answers', 'student_answer'));
    }

    public function submitQuestion(Request $request)
    {
        $request->validate([
            'question_id' => 'required',
            'options' => 'required'
        ]);

        $question_paper_info = Session::get('question_paper_info');
        $examination = Examination::find($question_paper_info['examination_id']);
        $student_answers = array_map('intval', $request->options);

        $answers = [];
        foreach ($student_answers as $student_answer){
            $answers[] = [
                'question_id' => $request->question_id,
                'option_id' => $student_answer,
                'answer' => 1
            ];
        }

        $examination->answers()->createMany($answers);

        return back();
    }
}
