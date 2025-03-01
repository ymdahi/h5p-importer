<?php

namespace Drupal\h5pimporter\Plugin\H5pContentType;

/**
 * Interface for H5P content type builder plugins.
 */
interface H5pContentTypeInterface {

  /**
   * Builds a structured array for the H5P content parameters.
   *
   * @param array $rows
   *   The CSV data rows.
   *
   * @return array
   *   The structured array for the H5P content.
   */
  public function buildContent(array $rows);
}
