<?php

namespace Drupal\convert_nodes\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\convert_nodes\ConvertNodes;

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
      'title' => t('Converting Base Tables...'),
      'operations' => [
        [
          '\Drupal\convert_nodes\ConvertNodes::convertBaseTables',
          [$nids, $base_table_names, $this->toType],
        ],
        [
          '\Drupal\convert_nodes\ConvertNodes::convertFieldTables',
          [$nids, $field_table_names, $this->toType, $update_fields],
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
        $this->createNew = $form['create_new'];
        if (empty($this->createNew)) {
          goto five;
        }
        $form_state->setRebuild();
        break;

      case 4:
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
        $this->userInput = array_merge($this->userInput, $data_to_process);
        $form_state->setRebuild();
        break;

      case 5:
        // Used also for goto.
        five:
        if (method_exists($this, 'convertNodes')) {
          $return_verify = $this->convertNodes();
        }
        drupal_set_message($return_verify);
        \Drupal::service("router.builder")->rebuild();
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
    switch ($this->step) {
      case 1:
        drupal_set_message('This module is experiemental. PLEASE do not use on production databases without prior testing and a complete database dump.', 'warning');
        // Get content types and put them in the form.
        $contentTypesList = ConvertNodes::getContentTypes();
        $form['convert_nodes_content_type_from'] = [
          '#type' => 'select',
          '#title' => t('From Content Type'),
          '#options' => $contentTypesList,
        ];
        $form['convert_nodes_content_type_to'] = [
          '#type' => 'select',
          '#title' => t('To Content Type'),
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
        $entityManager = \Drupal::service('entity_field.manager');
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
        $entityManager = \Drupal::service('entity_field.manager');
        // Put the to fields in the form for new values.
        foreach ($this->fieldsNewTo as $field_name) {
          if (!in_array($field_name, $this->userInput)) {
            // TODO Need to figure out a way to get form element
            // based on field def here. for now just a textfield
            /*
            $field = $entityManager->getFieldDefinitions('node', $this->toType)
            $field = $field[$field_name];
            $field_type = $field->getFieldStorageDefinition()->getType();
            $field_options = $field->getFieldStorageDefinition()->getSettings();
            $element = array (
            '#title' => 'test',
            '#description' => 'some desc',
            '#required' => true,
            '#delta' => 0,
            );
            $test = WidgetBase('test', 'test', $field, array(), array());
            $test->formElement($field, 0, $element, $form, $form_state);
             */
            $form[$field_name] = [
              '#type' => 'textfield',
              '#title' => t('Set Field @field_name', ['@field_name' => $field_name]),
            ];
          }
        }
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 5:
        drupal_set_message('This module is experiemental. PLEASE do not use on production databases without prior testing and a complete database dump.', 'warning');
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
    // TODO - this will need to incorporate form steps!!!!
  }

}
