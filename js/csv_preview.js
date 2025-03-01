(function ($, Drupal, once) {
  Drupal.behaviors.h5pCsvPreview = {
    attach: function (context, settings) {
      // Attach change event to CSV file input.
      $(once('csv-preview', '.csv-importer-input', context).forEach(function (element) {
        $(element).on('change', function() {
          var file = this.files[0];
          if (!file) {
            return;
          }
          
          // Remove any existing preview.
          $('#csv-data-preview').remove();

          // Create container for the preview table.
          var previewContainer = $(
            '<div id="csv-data-preview">' +
              '<h3>Data Preview</h3>' +
              '<table class="csv-preview-table" border="1" cellpadding="5" cellspacing="0"></table>' +
            '</div>'
          );
          // Append preview container right after the file input.
          $(element).after(previewContainer);

          // Parse CSV using PapaParse.
          Papa.parse(file, {
            header: true,
            skipEmptyLines: true,
            complete: function(results) {
              if (results && results.data && results.data.length > 0) {
                var table = previewContainer.find('table');
                var keys = Object.keys(results.data[0]);
                var headerRow = $('<tr></tr>');
                keys.forEach(function(key) {
                  headerRow.append('<th>' + key + '</th>');
                });
                table.append(headerRow);

                results.data.forEach(function(row) {
                  var tr = $('<tr></tr>');
                  keys.forEach(function(key) {
                    tr.append('<td contenteditable="true">' + (row[key] ? row[key] : '') + '</td>');
                  });
                  table.append(tr);
                });
                // Update hidden field initially.
                updatePreviewData();
                // Attach event listener to update hidden field on edits.
                table.find('td').on('input', function() {
                  updatePreviewData();
                });
              }
              else {
                previewContainer.append('<p>No data found in CSV file.</p>');
              }
            },
            error: function(error) {
              previewContainer.append('<p>Error parsing CSV: ' + error.message + '</p>');
            }
          });
        });
      }));

      // Function to update hidden field with current table data.
      function updatePreviewData() {
        var table = $('#csv-data-preview').find('table');
        if (table.length === 0) {
          return;
        }
        var headers = [];
        table.find('tr').first().find('th').each(function() {
          headers.push($(this).text());
        });
        var data = [];
        table.find('tr').not(':first').each(function() {
          var row = {};
          $(this).find('td').each(function(index) {
            row[headers[index]] = $(this).text();
          });
          data.push(row);
        });
        // Update the hidden field value.
        $('input[name="csv_preview_data"]').val(JSON.stringify(data));
      }
    }
  };
})(jQuery, Drupal, once);
