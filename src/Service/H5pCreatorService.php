<?php

namespace Drupal\h5pimporter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Service for creating H5P content from CSV data.
 */
class H5pCreatorService {

  /**
   * The CSV parser service.
   * @param array $extra_node_fields
   *   (optional) Associative array of additional node field values to apply to
   *   the created H5P node, keyed by field machine name.
   *
   * @var \Drupal\h5pimporter\Service\CsvParser
   */
  protected $csvParser;

  /**
   * The H5P content type plugin manager.
   *
   * @var \Drupal\h5pimporter\Plugin\H5pContentType\H5pContentTypeManager
   */
  protected $pluginManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new H5pCreatorService.
   *
   * @param \Drupal\h5pimporter\Service\CsvParser $csv_parser
   *   The CSV parser service.
   * @param \Drupal\h5pimporter\Plugin\H5pContentType\H5pContentTypeManager $plugin_manager
   *   The H5P content type plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(
    CsvParser $csv_parser,
    $plugin_manager,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager
  ) {
    $this->csvParser = $csv_parser;
    $this->pluginManager = $plugin_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
  }

  /**
   * Creates H5P content from CSV file content.
   *
   * @param string $csv_content
   *   The CSV content as a string.
   * @param string $title
   *   The title for the H5P content and node.
   * @param string $h5p_type
   *   The H5P content type plugin ID (default: 'questionset').
   * @param int $user_id
   *   The user ID to attribute the content to (default: current user).
   * @param string $source_url
   *   The URL of the node from which the CSV data was sourced (optional).
   * @param string $author_name
   *   The name of the original author (optional).
   * @param array $extra_node_fields
   *   (optional) Associative array of additional node field values to apply to
   *   the created H5P node, keyed by field machine name.
   *
   * @return array
   *   An array containing the created node and H5P content entity IDs.
   *   ['node_id' => $node->id(), 'h5p_id' => $h5p_content->id()]
   *
   * @throws \Exception
   *   Throws exception if content creation fails.
   */
  public function createFromCsvContent($csv_content, $title = 'Imported Quiz', $h5p_type = 'questionset', $user_id = NULL, $source_url = NULL, $author_name = NULL, array $extra_node_fields = []) {
    // Parse CSV content
    $rows = $this->csvParser->parseFileData($csv_content);
    if (empty($rows)) {
      throw new \Exception('No valid data found in CSV content');
    }

    return $this->createFromRows($rows, $title, $h5p_type, $user_id, $source_url, $author_name, $extra_node_fields);
  }

  /**
   * Creates H5P content from parsed CSV data rows.
   *
   * @param array $rows
   *   Array of parsed CSV data rows.
   * @param string $title
   *   The title for the H5P content and node.
   * @param string $h5p_type
   *   The H5P content type plugin ID (default: 'questionset').
   * @param int $user_id
   *   The user ID to attribute the content to (default: current user).
   * @param string $source_url
   *   The URL of the node from which the CSV data was sourced (optional).
   * @param string $author_name
   *   The name of the original author (optional).
   *
   * @return array
   *   An array containing the created node and H5P content entity IDs.
   *   ['node_id' => $node->id(), 'h5p_id' => $h5p_content->id()]
   *
   * @throws \Exception
   *   Throws exception if content creation fails.
   */
  public function createFromRows(array $rows, $title = 'Imported Quiz', $h5p_type = 'questionset', $user_id = NULL, $source_url = NULL, $author_name = NULL, array $extra_node_fields = []) {
    // Get user
    if ($user_id === NULL) {
      $user_id = $this->currentUser->id();
    }
    $user = User::load($user_id);
    if (!$user) {
      throw new \Exception('Invalid user ID provided');
    }

    // Use the H5P content type plugin
    $builder = $this->pluginManager->createInstance($h5p_type);
    $h5p_content_structure = $builder->buildContent($rows);
    $json_content = json_encode($h5p_content_structure, JSON_PRETTY_PRINT);

    $this->loggerFactory->get('h5pimporter')->debug('Creating H5P content from JSON: @json', ['@json' => $json_content]);

    // Set up library name based on H5P type
    $library_name = 'H5P.QuestionSet';
    
    // Prepare the values for H5P content entity creation
    $values = [
      'title'           => $title,
      'a11y_title'      => $title,
      'library'         => $library_name,
      'parameters'      => $json_content,
      'language'        => $this->languageManager->getCurrentLanguage()->getId(),
      'authors'         => json_encode([['name' => $author_name ?: $user->getDisplayName(), 'role' => 'Author']]),
      'license'         => 'CC BY',
      'license_version' => '4.0',
      'license_extras'  => 'Initial content generated via AI prompt by ' . ($author_name ? $author_name : '') . ($source_url ? ' - ' . $source_url : '.'),
    ];

    // Get the library ID
    $library_id = $this->database->select('h5p_libraries', 'hl')
      ->fields('hl', ['library_id'])
      ->condition('machine_name', $library_name)
      ->execute()
      ->fetchField();
    
    if ($library_id) {
      $values['library_id'] = $library_id;
    }
    else {
      $this->loggerFactory->get('h5pimporter')->warning('Library ID not found for library %library', ['%library' => $library_name]);
    }

    // Create H5P content entity
    try {
      // Try using the h5p.manager service if available
      if (\Drupal::hasService('h5p.manager')) {
        $h5p_manager = \Drupal::service('h5p.manager');
        $h5p_content = $h5p_manager->createContent($values);
      }
      else {
        // Fallback to entity creation
        $h5p_content = $this->entityTypeManager->getStorage('h5p_content')->create($values);
        $h5p_content->save();
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('h5pimporter')->error('Error creating H5P content: @error', ['@error' => $e->getMessage()]);
      throw new \Exception('Failed to create H5P content: ' . $e->getMessage());
    }

    $this->loggerFactory->get('h5pimporter')->notice('H5P content created: @id', ['@id' => $h5p_content->id()]);

    // Create node that references the H5P content
    try {
      $node = Node::create([
        'type'  => 'h5p',
        'title' => $title,
        'uid'   => $user_id,
      ]);

      // Apply any extra field values supplied for the node, matching fields
      // that exist on the H5P node bundle.
      foreach ($extra_node_fields as $field_name => $field_value) {
        if ($node->hasField($field_name)) {
          $node->set($field_name, $field_value);
        }
      }
      $node->set('field_h5p', $h5p_content->id());
      $node->save();

      $this->loggerFactory->get('h5pimporter')->notice('Node created with H5P content: Node ID @node_id, H5P ID @h5p_id', [
        '@node_id' => $node->id(),
        '@h5p_id' => $h5p_content->id(),
      ]);

      return [
        'node_id' => $node->id(),
        'h5p_id' => $h5p_content->id(),
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('h5pimporter')->error('Error creating node: @error', ['@error' => $e->getMessage()]);
      throw new \Exception('Failed to create node: ' . $e->getMessage());
    }
  }

  /**
   * Creates H5P content from a CSV file.
   *
   * @param string $file_path
   *   The file path to the CSV file.
   * @param string $title
   *   The title for the H5P content and node.
   * @param string $h5p_type
   *   The H5P content type plugin ID (default: 'questionset').
   * @param int $user_id
   *   The user ID to attribute the content to (default: current user).
   * @param string $source_url
   *   The URL of the node from which the CSV data was sourced (optional).
   * @param string $author_name
   *   The name of the original author (optional).
   *
   * @return array
   *   An array containing the created node and H5P content entity IDs.
   *   ['node_id' => $node->id(), 'h5p_id' => $h5p_content->id()]
   *
   * @throws \Exception
   *   Throws exception if file doesn't exist or content creation fails.
   */
  public function createFromCsvFile($file_path, $title = 'Imported Quiz', $h5p_type = 'questionset', $user_id = NULL, $source_url = NULL, $author_name = NULL, array $extra_node_fields = []) {
    if (!file_exists($file_path)) {
      throw new \Exception("CSV file not found: $file_path");
    }

    $csv_content = file_get_contents($file_path);
    if ($csv_content === FALSE) {
      throw new \Exception("Could not read CSV file: $file_path");
    }

    return $this->createFromCsvContent($csv_content, $title, $h5p_type, $user_id, $source_url, $author_name, $extra_node_fields);
  }
}
