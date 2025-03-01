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
    // Get preview data.
    $preview_data = $form_state->getValue('csv_preview_data');
    \Drupal::logger('h5pimporter')->notice('Preview data on submit: @data', [
      '@data' => print_r($preview_data, TRUE),
    ]);
  
    /** @var \Drupal\h5pimporter\Service\CsvParser $csvParser */
    $csvParser = \Drupal::service('h5pimporter.csv_parser');
  
    if (!empty($preview_data)) {
      $rows = $csvParser->parsePreviewData($preview_data);
    }
    else {
      $file = $form_state->getValue('csv_file');
      if (is_array($file)) {
        $file = reset($file);
      }
      if ($file) {
        $uri = $file->getFileUri();
        $data = file_get_contents($uri);
        $rows = $csvParser->parseFileData($data);
      }
      else {
        $this->messenger()->addError($this->t('No CSV file found.'));
        return;
      }
    }
  
    // Use the H5P content type plugin.
    $h5p_type = 'questionset';  // Change this to select a different type.
    $plugin_manager = \Drupal::service('plugin.manager.h5p_content_type');
    $builder = $plugin_manager->createInstance($h5p_type);
    $questionSet = $builder->buildContent($rows);
    $json_questionSet = json_encode($questionSet, JSON_PRETTY_PRINT);
  
    \Drupal::logger('h5pimporter')->notice('Imported quiz JSON: @json', ['@json' => $json_questionSet]);
  
    // (Proceed with H5P content entity creation and node creation as before.)
    $current_user = \Drupal::currentUser();
    $user = User::load($current_user->id());
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
  
    try {
      $h5p_manager = \Drupal::service('h5p.manager');
      $h5p_content = $h5p_manager->createContent($values);
    }
    catch (\Exception $e) {
      $h5p_content = \Drupal::entityTypeManager()->getStorage('h5p_content')->create($values);
      $h5p_content->save();
    }
  
    \Drupal::logger('h5pimporter')->notice('H5P content created: @id', ['@id' => $h5p_content->id()]);
  
    $node = Node::create([
      'type'  => 'h5p',
      'title' => 'Imported Quiz',
    ]);
    $node->set('field_h5p', $h5p_content->id());
    $node->save();
  
    $field_value = $node->get('field_h5p')->getValue();
    \Drupal::logger('h5pimporter')->notice('Node field_h5p: <pre>@value</pre>', [
      '@value' => print_r($field_value, TRUE),
    ]);
  
    if ($node->hasLinkTemplate('canonical')) {
      $node_url = $node->toUrl('canonical')->toString();
      $this->messenger()->addMessage($this->t('H5P quiz and node created successfully. <a href=":url">View content</a>', [':url' => $node_url]));
    }
    else {
      $this->messenger()->addMessage($this->t('H5P quiz and node created successfully. (Node ID: @id)', ['@id' => $node->id()]));
    }
  }
  
  

}
