(function ($, Drupal, drupalSettings) {

    'use strict';

    /**
     * Registers behaviours related to the directory widget.
     */
    Drupal.behaviors.MediaDirectoryWidget = {
      attach: function (context) {

        // @todo Improve this entire JS logic, it's very fragile and has bad UX.

        $(context).find('#media-directory-container').once('media-directory-build-tree').each(function () {
          var tree = drupalSettings.media_directory.tree;
          var $this = $(this);
          $this.jstree(JSON.parse(tree));
          $this.on('select_node.jstree', function (e, data) {
            // Save this term ID into the form field.
            var id = data.node.id;
            var $term_input = $('#edit-media-directory-value');
            var saved_value;
            // If the id is not an integer, it is a new term created clientside.
            if (isNaN(id)) {
              saved_value = $term_input.val() + '|' + data.node.text;
            }
            else {
              saved_value = id;
            }
            $term_input.val(saved_value);
          });
        });

      }
    };


}(jQuery, Drupal, drupalSettings));
