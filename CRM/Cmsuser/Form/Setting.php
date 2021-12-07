<?php

use CRM_Cmsuser_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Cmsuser_Form_Setting extends CRM_Core_Form {
  public function buildQuickForm() {

    // add form elements
    $this->add('text', 'cmsuser_pattern', 'Username pattern', ['size' => 60], TRUE);
    //get the tokens.
    $tokens = CRM_Core_SelectValues::contactTokens();
    $tokens = array_merge($tokens, CRM_Core_SelectValues::domainTokens());
    $this->assign('tokens', CRM_Utils_Token::formatTokensForDisplay($tokens));

    $this->add('advcheckbox', 'cmsuser_notify', ts('Notify User?'));
    $this->add('advcheckbox', 'cmsuser_create_immediately', ts('Create New User Immediately?'));
    $this->add('advcheckbox', 'cmsuser_login_immediately', ts('Login New User Immediately?'));
    if (CIVICRM_UF == 'Drupal8') {
      $user_role_names = user_role_names(TRUE);
      unset($user_role_names['authenticated']);
      $this->add('select', 'cmsuser_cms_roles', ts('Assign Role to Users'),
        $user_role_names, FALSE, ['class' => 'crm-select2 huge', 'multiple' => 1]);

      $userFields = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');
      $fieldHtml = '<table><tr><th>Label</th><th>Field Name</th><th>Is Required</th></tr>';
      foreach ($userFields as $fieldName => $fieldObject) {
        if (get_class($fieldObject) == 'Drupal\field\Entity\FieldConfig') {
          $isRequired = $fieldObject->isRequired() ? '<strong>True</strong>' : 'False';
          if ($fieldObject->getType() == 'address') {
            $field_overrides = $fieldObject->getSetting('field_overrides');
            foreach ($field_overrides as $field_overrideName => $field_override) {
              $field_overrideName = $this->fromCamelCase($field_overrideName);
              $isRequiredOverRide = 'False';
              if ($field_override['override'] == 'required') {
                $isRequiredOverRide = '<strong>True</strong>';
              }
              $fieldNameOverRide = $fieldName . '[0][' . $field_overrideName . ']';
              $fieldHtml .= '<tr><td>' . $fieldObject->getLabel() . '</td><td>' . $fieldNameOverRide . '</td><td>' . $isRequiredOverRide . '</td></tr>';
            }
          }
          else {
            $fieldHtml .= '<tr><td>' . $fieldObject->getLabel() . '</td><td>' . $fieldName . '</td><td>' . $isRequired . '</td></tr>';
          }
        }
      }
      $fieldHtml .= "</table>";
      $this->assign('fieldHtml', $fieldHtml);
      $this->addElement('textarea', 'cmsuser_user_fields', ts('Drupal User Fields'), ['rows' => 5, 'cols' => 50]);
    }
    elseif (CIVICRM_UF == 'Drupal') {
      $entity_type = 'user';
      $bundle_name = NULL;
      $fields_info = field_info_instances($entity_type, $bundle_name);
      $user_role_names = user_roles(TRUE);
      if (defined('DRUPAL_AUTHENTICATED_RID')) {
        unset($user_role_names[DRUPAL_AUTHENTICATED_RID]);
      }
      $this->add('select', 'cmsuser_cms_roles', ts('Assign Role to Users'),
        $user_role_names, FALSE, ['class' => 'crm-select2 huge', 'multiple' => 1]);

      $fieldHtml = '<table><tr><th>Label</th><th>Field Name</th><th>Is Required</th></tr>';
      foreach ($fields_info['user'] as $fieldName => $fieldDetails) {
        $isRequired = !empty($fieldDetails['required']) ? '<strong>True</strong>' : 'False';
        $fieldHtml .= '<tr><td>' . $fieldDetails['label'] . '</td><td>' . $fieldName . '</td><td>' . $isRequired . '</td></tr>';
      }
      $fieldHtml .= "</table>";
      $this->assign('fieldHtml', $fieldHtml);
      $this->addElement('textarea', 'cmsuser_user_fields', ts('Drupal User Fields'), ['rows' => 5, 'cols' => 50]);
    }
    elseif (CIVICRM_UF == 'WordPress') {
      global $wp_roles;
      $user_role_names = ['' => '-select-'] + $wp_roles->get_names();
      $this->add('select', 'cmsuser_cms_roles', ts('Assign Role to Users'),
        $user_role_names, FALSE, ['class' => 'crm-select2 huge']);
    }

    $groups = ['' => '-- select --'] + CRM_Core_PseudoConstant::nestedGroup();
    $tags = ['' => '-- select --'] + CRM_Core_PseudoConstant::get('CRM_Core_DAO_EntityTag', 'tag_id', ['onlyActive' => FALSE]);

    $this->add('select', 'cmsuser_group_create', ts('Create CMS User for Group contact'), $groups);
    $this->add('select', 'cmsuser_group_history', ts('Assign New CMS user to Group'), $groups);
    $this->add('select', 'cmsuser_group_reset', ts('Reset CMS Password from Group Contact'), $groups);

    $this->add('select', 'cmsuser_tag_create', ts('Create CMS User for Tagged contact'), $tags);
    $this->add('select', 'cmsuser_tag_history', ts('Assign New CMS user to Tag'), $tags);
    $this->add('select', 'cmsuser_tag_reset', ts('Reset CMS Password from Tagged Contact'), $tags);


    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    // use settings as defined in default domain
    $domainID = CRM_Core_Config::domainID();
    $settings = Civi::settings($domainID);
    $setDefaults = [];
    foreach ($this->getRenderableElementNames() as $elementName) {
      $setDefaults[$elementName] = $settings->get($elementName);
    }
    $this->setDefaults($setDefaults);
    $this->addFormRule(['CRM_Cmsuser_Form_Setting', 'formRule'], $this);

    parent::buildQuickForm();
  }

  public static function formRule($values, $files, &$self) {
    $errors = [];
    if (!empty($values['cmsuser_login_immediately']) && empty($values['cmsuser_create_immediately'])) {
      $errors['cmsuser_login_immediately'] = ts('Login Immediately only work with Create Immediately field.');
    }

    return empty($errors) ? TRUE : $errors;
  }

  public function postProcess() {
    $values = $this->exportValues();

    // use settings as defined in default domain
    $domainID = CRM_Core_Config::domainID();
    $settings = Civi::settings($domainID);

    foreach ($values as $k => $v) {
      if (strpos($k, 'cmsuser_') === 0) {
        $settings->set($k, $v);
      }
    }
    CRM_Core_Session::setStatus(E::ts('Setting updated successfully'));
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }

    return $elementNames;
  }

  /**
   * @param $input
   * @return string
   */
  function fromCamelCase($input) {
    preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
    $ret = $matches[0];
    foreach ($ret as &$match) {
      $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
    }
    return implode('_', $ret);
  }

}
