<?php

namespace Drupal\h5pimporter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\h5pimporter\Service\H5pCreatorService;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Controller for creating H5P quiz from Quiz node.
 */
class QuizToH5pController extends ControllerBase {

  /**
   * The H5P creator service.
   *
   * @var \Drupal\h5pimporter\Service\H5pCreatorService
   */
  protected $h5pCreator;

  /**
   * Constructs a QuizToH5pController object.
   *
   * @param \Drupal\h5pimporter\Service\H5pCreatorService $h5p_creator
   *   The H5P creator service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    H5pCreatorService $h5p_creator,
    TranslationInterface $string_translation
  ) {
    $this->h5pCreator = $h5p_creator;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('h5pimporter.creator'),
      $container->get('string_translation')
    );
  }

  /**
   * Converts Quiz JSON data to CSV format.
   *
   * @param array $quiz_data
   *   The quiz data in JSON format.
   *
   * @return string
   *   The CSV string representation of the quiz data.
   */
  protected function convertJsonToCsv(array $quiz_data) {
    // Create CSV headers
    $csv = "question,answers,correct_answer,feedback\n";
    
    // Process each question
    foreach ($quiz_data as $item) {
      // Support both the original schema and the new schema with choices as
      // objects and feedback as a nested object.

      // Detect new schema: choices is an array of associative arrays
      // containing a 'text' key.
      $is_new_schema = isset($item['choices'][0]) && is_array($item['choices'][0]) && array_key_exists('text', $item['choices'][0]);

      if ($is_new_schema) {
        // New schema: item has structure like
        // {
        //   "question": "...",
        //   "choices": [
        //     {"id": "c1", "text": "Choice", "is_correct": false},
        //     ...
        //   ],
        //   "feedback": {
        //     "correct_feedback": "...",
        //     "incorrect_feedback": "..."
        //   }
        // }

        $question_raw = isset($item['question']) ? $item['question'] : '';
        $correct_feedback = isset($item['feedback']['correct_feedback']) ? $item['feedback']['correct_feedback'] : '';
        $incorrect_feedback = isset($item['feedback']['incorrect_feedback']) ? $item['feedback']['incorrect_feedback'] : '';

        // Combine feedback into a single field for the CSV-based pipeline.
        $feedback_combined = trim($correct_feedback . "\n\n" . $incorrect_feedback);

        $question = str_replace('"', '""', $question_raw);
        $feedback = str_replace('"', '""', $feedback_combined);

        $choices = isset($item['choices']) && is_array($item['choices']) ? $item['choices'] : [];
        $choice_texts = [];
        $correct_answer_index = 0;

        foreach ($choices as $index => $choice) {
          $text = isset($choice['text']) ? $choice['text'] : '';
          $choice_texts[] = $text;

          // Use the first choice marked as correct. QuestionSet currently
          // expects a single correct_answer index.
          if ($correct_answer_index === 0 && !empty($choice['is_correct'])) {
            $correct_answer_index = $index + 1; // 1-based index
          }
        }
      }
      else {
        // Original schema: item has structure like
        // {
        //   "question": "...",
        //   "choices": ["Choice 1", "Choice 2", ...],
        //   "answer": "Choice 2",
        //   "feedback": "..."
        // }

        // Sanitize fields for CSV
        $question = str_replace('"', '""', $item['question']);
        $feedback = str_replace('"', '""', $item['feedback']);

        // Get choices and find the index of the correct answer
        $choices = isset($item['choices']) && is_array($item['choices']) ? $item['choices'] : [];
        $correct_answer_text = isset($item['answer']) ? $item['answer'] : '';
        $correct_answer_index = array_search($correct_answer_text, $choices);
        $correct_answer_index = ($correct_answer_index === FALSE) ? 0 : $correct_answer_index + 1; // 1-based index

        $choice_texts = $choices;
      }

      // Sanitize and join choices with pipe character
      $choices_csv = implode('|', array_map(function ($choice_text) {
        return str_replace('"', '""', str_replace('|', '/', $choice_text));
      }, $choice_texts));

      // Fallback if we couldn't determine a correct answer index.
      if ($correct_answer_index <= 0) {
        $correct_answer_index = 1;
      }

      // Add the row to CSV
      $csv .= '"' . $question . '","' . $choices_csv . '",' . $correct_answer_index . ',"' . $feedback . '"' . "\n";
    }
    
    return $csv;
  }

  /**
   * Creates an H5P quiz from a Quiz node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Quiz node.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function createFromQuiz(NodeInterface $node) {
    // Verify that this is a Quiz node
    if ($node->bundle() !== 'quiz') {
      $this->messenger()->addError($this->t('This action can only be performed on Quiz nodes.'));
      return new RedirectResponse($node->toUrl()->toString());
    }
    
    // Get the quiz data from the node. Prefer the new field_quiz_data field,
    // but fall back to the original field_response_data to preserve existing
    // behavior.
    $field_name = NULL;
    if ($node->hasField('field_quiz_data')) {
      $field_name = 'field_quiz_data';
    }
    elseif ($node->hasField('field_response_data')) {
      $field_name = 'field_response_data';
    }
    else {
      $this->messenger()->addError($this->t('This node does not have the required quiz data field.'));
      return new RedirectResponse($node->toUrl()->toString());
    }

    $field_value = $node->get($field_name)->getValue();
    
    // Check if the field has a value
    if (empty($field_value) || empty($field_value[0]['value'])) {
      $this->messenger()->addError($this->t('No quiz data found in this node.'));
      return new RedirectResponse($node->toUrl()->toString());
    }
    
    try {
      // Decode the JSON data
      $json_string = $field_value[0]['value'];
      $quiz_json = json_decode($json_string, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE || !is_array($quiz_json)) {
        throw new \Exception($this->t('Invalid JSON data in the quiz: @error', ['@error' => json_last_error_msg()]));
      }
      
      // Log the decoded JSON for debugging
      $this->getLogger('h5pimporter')->debug('Decoded quiz JSON: @json', ['@json' => print_r($quiz_json, TRUE)]);
      
      // Convert JSON to CSV format
      $csv_content = $this->convertJsonToCsv($quiz_json);
      
      // Log the CSV content for debugging
      $this->getLogger('h5pimporter')->debug('Generated CSV content: @csv', ['@csv' => $csv_content]);
      
      // Collect additional metadata fields from the quiz node to copy to the
      // created H5P node, if they exist.
      $extra_node_fields = [];
      $field_names_to_copy = [
        'field_cognitive_goal',
        'field_difficulty',
        'field_education_level',
        'field_subject',
        'field_tags',
      ];

      foreach ($field_names_to_copy as $field_to_copy) {
        if ($node->hasField($field_to_copy) && !$node->get($field_to_copy)->isEmpty()) {
          $extra_node_fields[$field_to_copy] = $node->get($field_to_copy)->getValue();
        }
      }

      // Also reference the source quiz node from the H5P node, if the H5P
      // content type has a field_reference entity reference field.
      $extra_node_fields['field_reference'] = [
        ['target_id' => $node->id()],
      ];

      // Use the H5P creator service to create the H5P content
      $result = $this->h5pCreator->createFromCsvContent(
        $csv_content,
        $node->getTitle(),
        'questionset',
        NULL,
        $node->toUrl()->setAbsolute()->toString(),
        $node->getOwner()->getDisplayName(),
        $extra_node_fields
      );
      
      // If successful, redirect to the new H5P node
      $this->messenger()->addStatus($this->t('H5P quiz created successfully.'));
      $url = Url::fromRoute('entity.node.canonical', ['node' => $result['node_id']])->toString();
      return new RedirectResponse($url);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to create H5P quiz: @error', ['@error' => $e->getMessage()]));
      $this->getLogger('h5pimporter')->error('Error creating H5P quiz: @error', ['@error' => $e->getMessage()]);
      return new RedirectResponse($node->toUrl()->toString());
    }
  }
}
