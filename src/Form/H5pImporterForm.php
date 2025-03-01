<?php

namespace Drupal\h5pimporter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Provides a form for uploading a CSV to create H5P quiz content.
 */
class H5pImporterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'h5p_importer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload CSV File'),
      '#description' => $this->t('The CSV should include the following columns: question, answers, correct_answer, feedback. For answers, use a pipe (|) to separate multiple choices.'),
      '#attributes' => [
        'class' => ['csv-importer-input'],
      ],
    ];

    // Hidden field that will be populated with the edited CSV preview data.
    $form['csv_preview_data'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Quiz'),
    ];

    // Attach our custom CSV preview library.
    $form['#attached']['library'][] = 'h5pimporter/csv_preview';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $file = file_save_upload('csv_file', [
      'file_validate_extensions' => ['csv'],
    ]);

    if (!$file) {
      $form_state->setErrorByName('csv_file', $this->t('Please upload a valid CSV file.'));
    }
    else {
      $form_state->setValue('csv_file', $file);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the preview data from the hidden field.
    $preview_data = $form_state->getValue('csv_preview_data');

    // Log the preview data.
    \Drupal::logger('h5pimporter')->notice('Preview data on submit: @data', [
      '@data' => print_r($preview_data, TRUE),
    ]);

    if (!empty($preview_data)) {
      $rows = json_decode($preview_data, TRUE);
      if (!is_array($rows)) {
        $this->messenger()->addError($this->t('Error decoding preview data.'));
        return;
      }
    }
    else {
      // Fallback: if no preview data is available, read the uploaded file.
      $file = $form_state->getValue('csv_file');
      if (is_array($file)) {
        $file = reset($file);
      }
      if ($file) {
        $uri = $file->getFileUri();
        $data = file_get_contents($uri);
        $lines = array_filter(array_map('trim', explode("\n", $data)));
        if (empty($lines)) {
          $this->messenger()->addError($this->t('The CSV file appears to be empty.'));
          return;
        }
        // First line assumed to be header.
        $header = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
          $row = str_getcsv($line);
          if (empty($row) || count($row) < count($header)) {
            continue;
          }
          $rows[] = array_combine($header, $row);
        }
      }
      else {
        $this->messenger()->addError($this->t('No CSV file found.'));
        return;
      }
    }

    // Build a H5P.QuestionSet structure using the (edited) preview data.
    $questionSet = [
      'introPage' => [
        'showIntroPage'   => false,
        'startButtonText' => 'Start Quiz',
        'introduction'    => ''
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

    // Process each row.
    foreach ($rows as $record) {
      // Split answers by pipe.
      $answer_texts = array_map('trim', explode('|', $record['answers']));
      $answers = [];
      foreach ($answer_texts as $index => $text) {
        $answers[] = [
          'correct' => ((int) $record['correct_answer'] === ($index + 1)) ? true : false,
          'tipsAndFeedback' => [
            'tip'              => '',
            'chosenFeedback'   => $record['feedback'],
            'notChosenFeedback'=> '',
          ],
          'text' => "<div>" . $text . "</div>\n",
        ];
      }

      // Build the question text.
      $questionText = "<p>" . $record['question'] . "</p>\n";

      // Build the question structure.
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

    // Convert the full question set to JSON.
    $json_questionSet = json_encode($questionSet, JSON_PRETTY_PRINT);

    // Log the constructed quiz JSON.
    \Drupal::logger('h5pimporter')->notice('Imported quiz JSON: @json', ['@json' => $json_questionSet]);

    // Get current user.
    $current_user = \Drupal::currentUser();
    $user = User::load($current_user->id());

    // Prepare values for H5P content.
    $library_name = 'H5P.QuestionSet';
    $values = [
      'title'           => 'Imported Quiz',
      'a11y_title'      => 'Imported Quiz',
      'library'         => $library_name,
      'parameters'      => $json_questionSet,
      'language'        => \Drupal::languageManager()->getCurrentLanguage()->getId(),
      'authors'         => json_encode([['name' => $user->getDisplayName(), 'role' => 'Author']]),
      'license'         => 'CC BY',
      'license_version' => '4.0',
      'license_extras'  => 'Imported from an LMS quiz export.',
    ];

    // Look up library_id from h5p_libraries table.
    $library_id = \Drupal::database()->select('h5p_libraries', 'hl')
      ->fields('hl', ['library_id'])
      ->condition('machine_name', $library_name)
      ->execute()
      ->fetchField();
    if ($library_id) {
      $values['library_id'] = $library_id;
    }
    else {
      \Drupal::logger('h5pimporter')->warning('Library ID not found for library %library', ['%library' => $library_name]);
    }

    // Create the H5P content entity.
    try {
      $h5p_manager = \Drupal::service('h5p.manager');
      $h5p_content = $h5p_manager->createContent($values);
    }
    catch (\Exception $e) {
      $h5p_content = \Drupal::entityTypeManager()->getStorage('h5p_content')->create($values);
      $h5p_content->save();
    }

    // Log a summary of the H5P content.
    $summary = [
      'id' => $h5p_content->id(),
      'title' => $h5p_content->label(),
      'library_name' => $values['library'],
      'library_id' => $h5p_content->get('library_id')->value,
      'parameters' => $h5p_content->get('parameters')->value,
    ];
    \Drupal::logger('h5pimporter')->notice('H5P content summary: <pre>@summary</pre>', [
      '@summary' => print_r($summary, TRUE),
    ]);

    // Create a new node of content type "h5p" with an entity reference field "field_h5p".
    $node = Node::create([
      'type'  => 'h5p',
      'title' => 'Imported Quiz',
    ]);
    // Set the field using the standard API.
    $node->set('field_h5p', $h5p_content->id());
    $node->save();

    // Check the value of field_h5p after saving.
    $field_value = $node->get('field_h5p')->getValue();
    \Drupal::logger('h5pimporter')->notice('Node field_h5p after initial save: <pre>@value</pre>', [
      '@value' => print_r($field_value, TRUE),
    ]);

    // If the field is empty, try an alternative method.
    if (empty($field_value)) {
      // Option 1: Reload the node and reset the field.
      $node = Node::load($node->id());
      $node->set('field_h5p', [['target_id' => $h5p_content->id()]]);
      $node->save();
      \Drupal::logger('h5pimporter')->notice('Field was empty; reloaded node and re-set field_h5p: <pre>@value</pre>', [
        '@value' => print_r($node->get('field_h5p')->getValue(), TRUE),
      ]);
    }

    // Generate a link to the new node.
    if ($node->hasLinkTemplate('canonical')) {
      $node_url = $node->toUrl('canonical')->toString();
      $this->messenger()->addMessage($this->t('H5P quiz and node created successfully. <a href=":url">View content</a>', [':url' => $node_url]));
    }
    else {
      $this->messenger()->addMessage($this->t('H5P quiz and node created successfully. (Node ID: @id)', ['@id' => $node->id()]));
    }
  }

}
