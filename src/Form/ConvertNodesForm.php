<?php

namespace Drupal\convert_nodes\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\convert_nodes\ConvertNodes;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Routing\RouteBuilderInterface;

/**
 * ConvertNodesForm.
 */
class ConvertNodesForm extends FormBase implements FormInterface {

  /**
   * Set a var to make this easier to keep track of.
   *
   * @var step
   */
  protected $step = 1;
  /**
   * Set some content type vars.
   *
   * @var fromType
   */
  protected $fromType = NULL;
  /**
   * Set some content type vars.
   *
   * @var toType
   */
  protected $toType = NULL;
  /**
   * Set field vars.
   *
   * @var fieldsFrom
   */
  protected $fieldsFrom = NULL;
  /**
   * Set field vars.
   *
   * @var fieldsTo
   */
  protected $fieldsTo = NULL;
  /**
   * Create new based on to content type.
   *
   * @var createNew
   */
  protected $createNew = NULL;
  /**
   * Create new based on to content type.
   *
   * @var fields_new_to
   */
  protected $fieldsNewTo = NULL;
  /**
   * Keep track of user input.
   *
   * @var userInput
   */
  protected $userInput = [];

  /**
   * The entity query factory service.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityManager;

  /**
   * The route builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routeBuilder;

  /**
   * {@inheritdoc}
   */
  public function __construct(QueryFactory $entity_query, EntityFieldManager $entity_manager, RouteBuilderInterface $route_builder) {
    $this->entityQuery = $entity_query;
    $this->entityManager = $entity_manager;
    $this->routeBuilder = $route_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.query'),
      $container->get('entity_field.manager'),
      $container->get('router.builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'convert_nodes_admin';
  }

  /**
   * {@inheritdoc}
   */
  public function convertNodes() {
    $base_table_names = ConvertNodes::getBaseTableNames();
    $userInput = ConvertNodes::sortUserInput($this->userInput, $this->fieldsNewTo, $this->fieldsFrom);
    $map_fields = $userInput['map_fields'];
    $update_fields = $userInput['update_fields'];
    $field_table_names = ConvertNodes::getFieldTableNames($this->fieldsFrom);
    $nids = ConvertNodes::getNids($this->fromType);
    $map_fields = ConvertNodes::getOldFieldValues($nids, $map_fields, $this->fieldsTo);
    $batch = [
      'title' => $this->t('Converting Base Tables...'),
      'operations' => [
        [
          '\Drupal\convert_nodes\ConvertNodes::convertBaseTables',
          [$base_table_names, $nids, $this->toType],
        ],
        [
          '\Drupal\convert_nodes\ConvertNodes::convertFieldTables',
          [$field_table_names, $nids, $this->toType, $update_fields],
        ],
        [
          '\Drupal\convert_nodes\ConvertNodes::addNewFields',
          [$nids, $map_fields],
        ],
      ],
      'finished' => '\Drupal\convert_nodes\ConvertNodes::convertNodesFinishedCallback',
    ];
    batch_set($batch);
    return 'All nodes of type ' . $this->fromType . ' were converted to ' . $this->toType;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    switch ($this->step) {
      case 1:
        $form_state->setRebuild();
        $this->fromType = $form['convert_nodes_content_type_from']['#value'];
        $this->toType = $form['convert_nodes_content_type_to']['#value'];
        break;

      case 2:
        $form_state->setRebuild();
        $data_to_process = array_diff_key(
                            $form_state->getValues(),
                            array_flip(
                              [
                                'op',
                                'submit',
                                'form_id',
                                'form_build_id',
                                'form_token',
                              ]
                            )
                          );
        $this->userInput = $data_to_process;
        break;

      case 3:
        $this->createNew = $form['create_new']['#value'];
        if (!$this->createNew) {
          $this->step++;
          goto five;
        }
        $form_state->setRebuild();
        break;

      case 4:
        $values = $form_state->getValues()['default_value_input'];
        foreach ($values as $key => $value) {
          unset($values[$key]['add_more']);
        }
        $data_to_process = array_diff_key(
                            $values,
                            array_flip(
                              [
                                'op',
                                'submit',
                                'form_id',
                                'form_build_id',
                                'form_token',
                              ]
                            )
                          );
        $this->userInput = array_merge($this->userInput, $data_to_process);
        // Used also for goto.
        five:
        $form_state->setRebuild();
        break;

      case 5:
        if (method_exists($this, 'convertNodes')) {
          $return_verify = $this->convertNodes();
        }
        drupal_set_message($return_verify);
        $this->routeBuilder->rebuild();
        break;
    }
    $this->step++;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if (isset($this->form)) {
      $form = $this->form;
    }
    drupal_set_message($this->t('This module is experiemental. PLEASE do not use on production databases without prior testing and a complete database dump.'), 'warning');
    switch ($this->step) {
      case 1:
        // Get content types and put them in the form.
        $contentTypesList = ConvertNodes::getContentTypes();
        $form['convert_nodes_content_type_from'] = [
          '#type' => 'select',
          '#title' => $this->t('From Content Type'),
          '#options' => $contentTypesList,
        ];
        $form['convert_nodes_content_type_to'] = [
          '#type' => 'select',
          '#title' => $this->t('To Content Type'),
          '#options' => $contentTypesList,
        ];
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 2:
        // Get the fields.
        $entityManager = $this->entityManager;
        $this->fieldsFrom = $entityManager->getFieldDefinitions('node', $this->fromType);
        $this->fieldsTo = $entityManager->getFieldDefinitions('node', $this->toType);

        $fields_to = ConvertNodes::getToFields($this->fieldsTo);
        $fields_to_names = $fields_to['fields_to_names'];
        $fields_to_types = $fields_to['fields_to_types'];

        $fields_from = ConvertNodes::getFromFields($this->fieldsFrom, $fields_to_names, $fields_to_types);
        $fields_from_names = $fields_from['fields_from_names'];
        $fields_from_form = $fields_from['fields_from_form'];

        // Find missing fields. allowing values to be input later.
        $fields_to_names = array_diff($fields_to_names, ['append_to_body', 'remove']);
        $this->fieldsNewTo = array_diff(array_keys($fields_to_names), $fields_from_names);

        $form = array_merge($form, $fields_from_form);
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 3:
        $form['create_new'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Create field values for fields in new content type'),
        ];
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 4:
        // Put the to fields in the form for new values.
        foreach ($this->fieldsNewTo as $field_name) {
          if (!in_array($field_name, $this->userInput)) {
            // TODO - Date widgets are relative. Fix.
            // Create an arbitrary entity object.
            $ids = (object) [
              'entity_type' => 'node',
              'bundle' => $this->toType,
              'entity_id' => NULL,
            ];
            $fake_entity = _field_create_entity_from_ids($ids);
            $items = $fake_entity->get($field_name);
            $temp_form_element = [];
            $temp_form_state = new FormState();
            $form[$field_name] = $items->defaultValuesForm($temp_form_element, $temp_form_state);
          }
        }
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 5:
        drupal_set_message($this->t('Are you sure you want to convert all nodes of type <em>@from_type</em> to type <em>@to_type</em>?',
                             [
                               '@from_type' => $this->fromType,
                               '@to_type' => $this->toType,
                             ]), 'warning');
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Convert'),
          '#button_type' => 'primary',
        ];
        break;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    switch ($this->step) {
      case 1:
        $this->from_type = $form['convert_nodes_content_type_from']['#value'];
        $this->to_type = $form['convert_nodes_content_type_to']['#value'];
        $query = $this->entityQuery->get('node')->condition('type', $this->from_type);
        $count_type = $query->count()->execute();
        if ($count_type == 0) {
          $form_state->setErrorByName('convert_nodes_content_type_from', $this->t('No content found to convert.'));
        }
        elseif ($this->from_type == $this->to_type) {
          $form_state->setErrorByName('convert_nodes_content_type_to', $this->t('Please select different content types.'));
        }
        break;

      default:
        // TODO - validate other steps.
        break;
    }

  }

}
