<?php

namespace Drupal\h5pimporter\Service;

/**
 * Provides a service to parse CSV files or preview data.
 */
class CsvParser {

  /**
   * Parses CSV preview data (JSON string) into an array.
   *
   * @param string $preview_data
   *   The JSON string from the hidden field.
   *
   * @return array
   *   The decoded rows.
   */
  public function parsePreviewData($preview_data) {
    $rows = json_decode($preview_data, TRUE);
    return is_array($rows) ? $rows : [];
  }

  /**
   * Fallback: Parse a CSV file contents into an array.
   *
   * @param string $data
   *   The CSV file contents.
   *
   * @return array
   *   An array of rows.
   */
  public function parseFileData($data) {
    $lines = array_filter(array_map('trim', explode("\n", $data)));
    if (empty($lines)) {
      return [];
    }
    $header = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
      $row = str_getcsv($line);
      if (!empty($row) && count($row) >= count($header)) {
        $rows[] = array_combine($header, $row);
      }
    }
    return $rows;
  }
}
