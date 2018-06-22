<?php

namespace Drupal\media_directory\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to configure Media Directory settings.
 */
class MediaDirectorySettingsForm extends ConfigFormBase {

  /**
   * The Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The router builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routerBuilder;

  /**
   * The Cache Render.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheRender;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, RouteBuilderInterface $router_builder, CacheBackendInterface $cache_render, ModuleHandlerInterface $module_handler, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->routerBuilder = $router_builder;
    $this->cacheRender = $cache_render;
    $this->moduleHandler = $module_handler;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('router.builder'),
      $container->get('cache.render'),
      $container->get('module_handler'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_directory_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['media_directory.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['vocabulary'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Directory configuration'),
      '#description' => $this->t('Choose the Vocabulary to be used on each Media Type. Note that in order to be eligible to be used here, the vocabulary <strong>must</strong> have a "root" term created at first-level. The directory tree will be built after this term.'),
      '#tree' => TRUE,
    ];

    $mapping = media_directory_get_vocabulary_mapping();
    $vocabs = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    foreach ($vocabs as $vocab) {
      $root_term = $this->entityTypeManager->getStorage('taxonomy_term')
        ->getQuery()
        ->condition('name', 'root')
        ->condition('vid', $vocab->id())
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute();
      if (empty($root_term)) {
        unset($vocabs[$vocab->id()]);
      }
    }
    if (empty($vocabs)) {
      return [
        '#markup' => $this->t('In order to use this feature you need at least one eligible vocabulary in your system (i.e. having a "root" term at first-level).'),
      ];
    }
    $options = array_map(function ($vocabulary) {
      /** @var \Drupal\taxonomy\VocabularyInterface $vocabulary */
      return $vocabulary->label();
    }, $vocabs);
    /** @var \Drupal\media\MediaTypeInterface[] $media_types */
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    foreach ($media_types as $type) {
      $form['vocabulary'][$type->id()] = [
        '#type' => 'select',
        '#title' => $type->label(),
        '#options' => $options,
        '#default_value' => !empty($mapping[$type->id()]) ? $mapping[$type->id()] : NULL,
        '#empty_option' => $this->t('- Select vocabulary -'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();
    $config = $this->config('media_directory.settings');
    $original_mapping = $config->get('media_directory_vocabulary_mapping') ?: [];

    $vocabs = $form_state->getValue(['vocabulary']) ?: [];
    $mapping = [];
    foreach ($vocabs as $type => $vocab) {
      $mapping[] = $type . ":" . $vocab;
      // If this is a non-empty vocab (i.e. this media type is being set to
      // use a vocabulary, configure also the field on the type to do so.
      if (!empty($vocab)) {
        if (!$this->configureMediaTypeField($type, $vocab)) {
          $this->messenger()->addError($this->t('Could not configure the field "media_directory" on type @type', [
            '@type' => $type,
          ]));
        }
      }
      elseif (!empty($original_mapping) && !in_array($type . ':', $original_mapping)) {
        // If a user is removing config from a Media Type, let them know they
        // are responsible for removing the field from the type as well.
        $this->messenger()->addWarning($this->t('When removing the directory functionality from a Media Type, make sure you also remove the field "media_directory". Go to <a href="@field_ui_url" target="_blank">Manage fields</a> (opens in new window).', [
          '@field_ui_url' => Url::fromRoute('entity.media.field_ui_fields', [
            'media_type' => $type,
          ])->toString(),
        ]));
      }
    }

    $config->set('media_directory_vocabulary_mapping', $mapping)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Configure the field to store the media directory on a given type.
   *
   * Will ensure the given media type has a field (creating it if necessary)
   * called "media_directory", configured to store taxonomy terms of the
   * vocabulary passed in. Will also update the media form display so that this
   * field uses the correct widget.
   *
   * @param string $type_name
   *   The media type machine name.
   * @param string $vocab_name
   *   The vocabulary machine name.
   *
   * @return bool
   *   TRUE if could configure the field properly, or FALSE otherwise.
   */
  protected function configureMediaTypeField($type_name, $vocab_name) {
    // The type must exist.
    $type = $this->entityTypeManager->getStorage('media_type')->load($type_name);
    if (!$type) {
      return FALSE;
    }

    // The vocabulary must exist.
    $vocab = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vocab_name);
    if (!$vocab) {
      return FALSE;
    }

    /** @var \Drupal\media\Entity\MediaType $type */
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', $type_name);
    if (!empty($field_definitions['media_directory'])) {
      // Just double-check this field is properly configured.
      /** @var \Drupal\field\Entity\FieldConfig $media_directory_field */
      $media_directory_field = $field_definitions['media_directory'];
      $handler_settings = $media_directory_field->getSetting('handler_settings');
      $target_bundles = $handler_settings['target_bundles'];
      if (count($target_bundles) != 1) {
        $this->messenger()->addError($this->t('A misconfigured "media_directory" field was detected on type @type.', [
          '@type' => $type_name,
        ]));
        return FALSE;
      }
      // The field could be configured to point to a different vocabulary.
      if (!in_array($vocab_name, $target_bundles)) {
        $handler_settings['target_bundles'] = [$vocab_name => $vocab_name];
        $media_directory_field->setSetting('handler_settings', $handler_settings);
        $media_directory_field->save();
        $this->messenger()->addStatus($this->t('The field "media_directory" on type @type was reconfigured to reference terms on the new vocabulary.', [
          '@type' => $type->label(),
        ]));
      }
      return TRUE;
    }
    else {
      // Create a new field.
      $field_storage = FieldStorageConfig::load('media.media_directory');
      if (!$field_storage) {
        $field_storage = FieldStorageConfig::create([
          'field_name' => 'media_directory',
          'entity_type' => 'media',
          'type' => 'entity_reference',
          'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
          'settings' => [
            'target_type' => 'taxonomy_term',
          ],
        ]);
        $field_storage->save();
      }
      $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'label' => $this->t('Directory tree'),
        'bundle' => $type_name,
        'required' => TRUE,
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => [
              $vocab_name => $vocab_name,
            ],
          ],
        ],
      ]);
      $field->save();
      // Set the new field to use our widget as well.
      entity_get_form_display('media', $type_name, 'default')
        ->setComponent('media_directory', [
          'type' => 'media_directory',
          'weight' => '25',
        ])
        ->save();
      return TRUE;
    }
  }

}
