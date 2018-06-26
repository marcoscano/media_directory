<?php

namespace Drupal\media_directory\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'media_directory' widget.
 *
 * @FieldWidget(
 *   id = "media_directory",
 *   label = @Translation("Media Directory"),
 *   field_types = {
 *     "entity_reference",
 *   },
 *   multiple_values = TRUE
 * )
 */
class MediaDirectoryWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    // This widget is only expected to be used in taxonomy fields attached to
    // Media entities, when configured to point to an eligible vocabulary.
    if ($field_definition->getTargetEntityTypeId() !== 'media'
      || $field_definition->getFieldStorageDefinition()->getSetting('target_type') !== 'taxonomy_term') {
      return FALSE;
    }
    $target_bundles = $field_definition->getSetting('handler_settings')['target_bundles'];
    $mapping = media_directory_get_vocabulary_mapping();
    $vocab_mapped = !empty($mapping[$field_definition->getTargetBundle()]) ? $mapping[$field_definition->getTargetBundle()] : NULL;
    if (count($target_bundles) != 1 || !$vocab_mapped || !in_array($vocab_mapped, $target_bundles)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Build the tree of selectable terms. This will generate an array where
    // keys are TIDs and values are the term names, prefixed with as many "-"
    // symbols as their nesting levels, in a hierarchical structure.
    $tree = $this->fieldDefinition
      ->getFieldStorageDefinition()
      ->getOptionsProvider('target_id', $items->getEntity())
      ->getSettableOptions(\Drupal::currentUser());
    // @todo Temporarily show also the TID alongside term names, to make
    // testing easier:
    foreach ($tree as $key => $value) {
      $tree_with_tids[$key] = $value . ' (' . $key . ')';
    }

    $element['value'] = $element + [
      '#type' => 'textfield',
      '#default_value' => $this->prepareDefaultValue($items),
      '#attributes' => ['class' => ['js-text-full', 'text-full']],
    ];

    $default_tids = explode("|", $element['value']['#default_value']);
    $json_tree = [
      'core' => [
        'multiple' => FALSE,
        'themes' => [
          'variant' => 'large',
        ],
        'check_callback' => TRUE,
        'data' => $this->prepareJsTreeData($tree, array_pop($default_tids)),
      ],
      'plugins' => [
        'contextmenu',
      ],
    ];
    $element['directory_container'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        // @todo Find a more appropriate ID selector here.
        'id' => 'media-directory-container',
      ],
      '#attached' => [
        'drupalSettings' => ['media_directory' => ['tree' => json_encode($json_tree)]],
        'library' => ['media_directory/directory_widget'],
      ],
    ];
    // @todo Temporarily expose the tree on the UI.
    $element['value']['#description'] = implode('<br />', $tree_with_tids);

    return $element;
  }

  /**
   * Generate a string of TIDs to be used as default value for the textfield.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The $items being processed.
   *
   * @return string
   *   A string of TIDs representing the full branch of terms selected, where
   *   each term will be separated by a "|" character. This will also sort the
   *   terms in a way that ancestors will be shown on the left-hand side of
   *   their descendants.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function prepareDefaultValue(FieldItemListInterface $items) {
    if ($items->isEmpty()) {
      return (string) $this->getRootTermTid();
    }

    $unsorted_tids = array_column($items->getValue(), 'target_id');
    $sorted_tids = $this->sortTidsByAncestorChain($unsorted_tids);
    return implode("|", $sorted_tids);
  }

  /**
   * Retrieve the "root" term TID for the vocabulary configured in this field.
   *
   * @return int
   *   The term ID of the "root" term for the vocabulary configured in this
   *   field / media type.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getRootTermTid() {
    $media_type_name = $this->fieldDefinition->getTargetBundle();
    $mapping = media_directory_get_vocabulary_mapping();
    $vocab_mapped = !empty($mapping[$media_type_name]) ? $mapping[$media_type_name] : NULL;
    if (!$vocab_mapped) {
      throw new \Exception('Using Media Directory widget for type ' . $media_type_name . ' with a misconfigured vocabulary.');
    }
    // @todo DI.
    $root_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('name', 'root')
      ->condition('vid', $vocab_mapped)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (empty($root_term)) {
      throw new \Exception('Using Media Directory widget for type ' . $media_type_name . ' with a misconfigured vocabulary.');
    }
    return reset($root_term);
  }

  /**
   * Convert a random lits of TIDs from a branch into a sorted list.
   *
   * This function expects to receive a potentially unordered list of TIDs from
   * a given branch in the tree hierarchy, and will return the same list of TIDs
   * but starting with the root term, and where each term appears immediately
   * before its direct descendant.
   *
   * @param array $tids
   *   The input array of term IDs.
   *
   * @return array
   *   The hierarchy-sorted list of term IDs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function sortTidsByAncestorChain(array $tids) {
    // While loading entities from DB is usually expensive, in this case it
    // won't be too much, once even if the tree itself is very big, it is
    // unlikely that a single branch is big enough to cause performance issues.
    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    // @todo DI.
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($tids);

    // First, identify which is the root term (it won't have a parent). We
    // assume the tree is not (too) broken and has only one root term.
    $root_term = NULL;
    foreach ($terms as $key => $term) {
      // Unfortunately ::isEmpty() is inconsistent here, roots have a parent
      // value of '0'.
      if ($term->get('parent')->target_id == 0) {
        $root_term = $term;
        unset($terms[$key]);
        break;
      }
    }
    if (!$root_term) {
      throw new \Exception('Detected media entity with broken directory chain.');
    }

    // Now recursively find all descendants of this root term.
    $sorted = $this->recursiveSortDescendants($terms, $root_term->id());

    // Add the root as first element, and we're done.
    array_unshift($sorted, $root_term->id());
    return $sorted;
  }

  /**
   * Sort an array of terms, given an initial parent TID value.
   *
   * @param \Drupal\taxonomy\TermInterface[] $terms
   *   The array of terms to sort. It is expected that all terms here have a
   *   parent term.
   * @param int $parent_tid
   *   The parent TID.
   * @param array $sorted
   *   (optional) For internal use only. Defaults to an empty array.
   */
  protected function recursiveSortDescendants(array $terms, $parent_tid, array $sorted = []) {
    if (empty($terms)) {
      return $sorted;
    }
    foreach ($terms as $key => $term) {
      if ($term->get('parent')->target_id == $parent_tid) {
        unset($terms[$key]);
        $sorted[] = $term->id();
        return $this->recursiveSortDescendants($terms, $term->id(), $sorted);
      }
    }
  }

  /**
   * Convert an options-like tree into a format JsTree can process.
   *
   * @param array $tree
   *   The tree as generated by ::getSettableOptions().
   * @param int $leaf_tid
   *   The term ID of the selected leaf.
   *
   * @return array
   *   An array of term IDs and configuration options as required by JsTree to
   *   build the tree.
   */
  protected function prepareJsTreeData(array $tree, $leaf_tid) {
    $data_tree = [];

    $previous_tid = NULL;
    $parents_current_branch = [];
    foreach ($tree as $tid => $value) {
      if (strpos($value, '-') === FALSE) {
        // First pass, store the root element.
        $data_tree[] = [
          'id' => $tid,
          'parent' => '#',
          'text' => '/',
          // The first level is always open.
          'state' => ['opened' => TRUE],
        ];
        $previous_tid = $tid;
        $parents_current_branch[0] = '#';
      }
      // In all subsequent terms we have previous and parent.
      elseif (strspn($value, "-", 0) == strspn($tree[$previous_tid], "-", 0)) {
        // We are siblings.
        $data_tree[] = [
          'id' => $tid,
          'parent' => $parents_current_branch[strspn($value, "-", 0)],
          'text' => ltrim($value, "-"),
          'state' => ['selected' => $leaf_tid == $tid],
        ];
        $previous_tid = $tid;
      }
      elseif (strspn($value, "-", 0) < strspn($tree[$previous_tid], "-", 0)) {
        // This term starts a new branch, we need to figure out its parent.
        $data_tree[] = [
          'id' => $tid,
          'parent' => $parents_current_branch[strspn($value, "-", 0)],
          'text' => ltrim($value, "-"),
          'state' => ['selected' => $leaf_tid == $tid],
        ];
        $previous_tid = $tid;
      }
      else {
        // I am a new child.
        $data_tree[] = [
          'id' => $tid,
          'parent' => $previous_tid,
          'text' => ltrim($value, "-"),
          'state' => ['selected' => $leaf_tid == $tid],
        ];
        $parents_current_branch[strspn($value, "-", 0)] = $previous_tid;
        $previous_tid = $tid;
      }
    }

    return $data_tree;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $selected_values = explode("|", $values['value']);
    if (empty($selected_values)) {
      return $selected_values;
    }
    // If there are names at the end of the chain that are not TIDs, it means
    // we need to create them as well.
    if (!$this->isInt($selected_values[count($selected_values) - 1])) {
      // This method is called twice as part of a normal submit flow: first in
      // the validate process and then again during the submit process. We
      // obviously only want to create the term once, and we also want to do it
      // during validation, so the standard entity_reference validation can take
      // place with real values. Because of that, after creating the term(s) we
      // save the sequence containing only TIDs in the form state, and just
      // retrieve it during the submit process.
      if (!empty($form_state->get('media_directory_tid_chain_selected'))) {
        return $form_state->get('media_directory_tid_chain_selected');
      }
      $mapping = media_directory_get_vocabulary_mapping();
      $media_type_name = $this->fieldDefinition->getTargetBundle();
      $vocab_mapped = !empty($mapping[$media_type_name]) ? $mapping[$media_type_name] : NULL;
      if (!$vocab_mapped) {
        throw new \Exception('Using Media Directory widget for type ' . $media_type_name . ' with a misconfigured vocabulary.');
      }
      foreach ($selected_values as $key => $value) {
        if (!$this->isInt($value)) {
          // @todo DI.
          $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->create([
            'name' => Xss::filter($value),
            'parent' => $selected_values[$key - 1],
            'vid' => $vocab_mapped,
          ]);
          $term->save();
          $selected_values[$key] = $term->id();
        }
      }
      $form_state->set('media_directory_tid_chain_selected', $selected_values);
    }

    return $selected_values;
  }

  /**
   * Check if a value is an integer, or an integer string.
   *
   * @param int|string $value
   *   The value to check.
   *
   * @return bool
   *   TRUE if the value is a numeric integer or a string containing an integer,
   *   FALSE otherwise.
   */
  protected function isInt($value) {
    return ((string) (int) $value === (string) $value);
  }

}
