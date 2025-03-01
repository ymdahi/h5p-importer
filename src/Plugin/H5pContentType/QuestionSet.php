<?php

namespace Drupal\h5pimporter\Plugin\H5pContentType;

use Drupal\Component\Plugin\PluginBase;

/**
 * @H5pContentType(
 *   id = "questionset",
 *   label = @Translation("Question Set")
 * )
 */
class QuestionSet extends PluginBase implements H5pContentTypeInterface {

  /**
   * {@inheritdoc}
   */
  public function buildContent(array $rows) {
    // (Place your logic here to build the QuestionSet array from CSV rows.)
    $questionSet = [
      'introPage' => [
        'showIntroPage'   => false,
        'startButtonText' => 'Start Quiz',
        'introduction'    => '',
      ],
      'progressType' => 'dots',
      'passPercentage' => 50,
      'questions' => [],
      'disableBackwardsNavigation' => false,
      'randomQuestions' => false,
      'endGame' => [
        'showResultPage'     => true,
        'showSolutionButton' => true,
        'showRetryButton'    => true,
        'noResultMessage'    => 'Finished',
        'message'            => 'Your result:',
        'scoreBarLabel'      => 'You got @finals out of @totals points',
        'overallFeedback'    => [['from' => 0, 'to' => 100]],
        'solutionButtonText' => 'Show solution',
        'retryButtonText'    => 'Retry',
        'finishButtonText'   => 'Finish',
        'submitButtonText'   => 'Submit',
        'showAnimations'     => false,
        'skippable'          => false,
        'skipButtonText'     => 'Skip video',
      ],
      'override' => [
        'checkButton' => true,
      ],
      'texts' => [
        'prevButton'         => 'Previous question',
        'nextButton'         => 'Next question',
        'finishButton'       => 'Finish',
        'submitButton'       => 'Submit',
        'textualProgress'    => 'Question: @current of @total questions',
        'jumpToQuestion'     => 'Question %d of %total',
        'questionLabel'      => 'Question',
        'readSpeakerProgress'=> 'Question @current of @total',
        'unansweredText'     => 'Unanswered',
        'answeredText'       => 'Answered',
        'currentQuestionText'=> 'Current question',
        'navigationLabel'    => 'Questions',
      ],
    ];

    // Process rows to build each question.
    foreach ($rows as $record) {
      $answer_texts = array_map('trim', explode('|', $record['answers']));
      $answers = [];
      foreach ($answer_texts as $index => $text) {
        $answers[] = [
          'correct' => ((int) $record['correct_answer'] === ($index + 1)) ? true : false,
          'tipsAndFeedback' => [
            'tip' => '',
            'chosenFeedback' => $record['feedback'],
            'notChosenFeedback' => '',
          ],
          'text' => "<div>" . $text . "</div>\n",
        ];
      }
      $questionText = "<p>" . $record['question'] . "</p>\n";
      $question = [
        'params' => [
          'media' => [
            'disableImageZooming' => false,
            'type' => ['params' => (object) []],
          ],
          'answers' => $answers,
          'overallFeedback' => [['from' => 0, 'to' => 100]],
          'behaviour' => [
            'enableRetry' => true,
            'enableSolutionsButton' => true,
            'enableCheckButton' => true,
            'type' => 'auto',
            'singlePoint' => false,
            'randomAnswers' => true,
            'showSolutionsRequiresInput' => true,
            'confirmCheckDialog' => false,
            'confirmRetryDialog' => false,
            'autoCheck' => false,
            'passPercentage' => 100,
            'showScorePoints' => true,
          ],
          'UI' => [
            'checkAnswerButton' => 'Check',
            'submitAnswerButton' => 'Submit',
            'showSolutionButton' => 'Show solution',
            'tryAgainButton' => 'Retry',
            'tipsLabel' => 'Show tip',
            'scoreBarLabel' => 'You got :num out of :total points',
            'tipAvailable' => 'Tip available',
            'feedbackAvailable' => 'Feedback available',
            'readFeedback' => 'Read feedback',
            'wrongAnswer' => 'Wrong answer',
            'correctAnswer' => 'Correct answer',
            'shouldCheck' => 'Should have been checked',
            'shouldNotCheck' => 'Should not have been checked',
            'noInput' => 'Please answer before viewing the solution',
            'a11yCheck' => 'Check the answers. The responses will be marked as correct, incorrect, or unanswered.',
            'a11yShowSolution' => 'Show the solution. The task will be marked with its correct solution.',
            'a11yRetry' => 'Retry the task. Reset all responses and start the task over again.',
          ],
          'confirmCheck' => [
            'header' => 'Finish ?',
            'body' => 'Are you sure you wish to finish ?',
            'cancelLabel' => 'Cancel',
            'confirmLabel' => 'Finish',
          ],
          'confirmRetry' => [
            'header' => 'Retry ?',
            'body' => 'Are you sure you wish to retry ?',
            'cancelLabel' => 'Cancel',
            'confirmLabel' => 'Confirm',
          ],
          'question' => $questionText,
        ],
        'library' => 'H5P.MultiChoice 1.16',
        'metadata' => [
          'contentType' => 'Multiple Choice',
          'license' => 'U',
          'title' => $questionText,
          'authors' => [],
          'changes' => [],
          'extraTitle' => '',
        ],
        'subContentId' => \Drupal::service('uuid')->generate(),
      ];
      $questionSet['questions'][] = $question;
    }

    return $questionSet;
  }
}
