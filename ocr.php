<?php

/* 
 * OCR Import Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

define('__OCR', null);

// MAF specific setting - would ideally like to move this to a settings page, but
// don't really want to implement a whole form just to deal with that.
define('NETS_CUSTOMER_ID_NUMBER', '00131936');      // live file
define('NETS_TEST_CUSTOMER_ID_NUMBER', '00076473'); // test file

define('MAF_HISTORIC_CUTOFF_ID', 700000);

// Include civicrm_api3 wrapper for early 4.3 versions
if (!class_exists('CiviCRM_API3_Exception')) {
    
  class CiviCRM_API3_Exception extends Exception {
      
    private $extraParams = array();

    public function __construct($message, $error_code, $extraParams = array(),Exception $previous = null) {  
      parent::__construct(ts($message));
      $this->extraParams = $extraParams + array('error_code' => $error_code);
    }

    // custom string representation of object
    public function __toString() {
      return __CLASS__ . ": [{$this->extraParams['error_code']}: {$this->message}\n";
    }

    public function getErrorCode() {
      return $this->extraParams['error_code'];
    }

    public function getExtraParams() {
      return $this->extraParams;
    }
  }
}

if (!function_exists('civicrm_api3')) {
    
  function civicrm_api3($entity, $action, $params = array()) {
    $params['version'] = 3;
    $result = civicrm_api($entity, $action, $params);
    if(is_array($result) && !empty($result['is_error'])){
      throw new CiviCRM_API3_Exception($result['error_message'], CRM_Utils_Array::value('error_code', $result, 'undefined'), $result);
    }
    return $result;
  }
}

/*
 * Implementation of hook_civicrm_buildForm
 */
function ocr_civicrm_buildForm($formName, &$form) {
  switch ($formName) {
    // Add activity selection list to add/edit Contribution form
    case 'CRM_Contribute_Form_Contribution':
      $contact_id      = @$_POST['contact_id'] or $contact_id = @$_GET['cid'];
      $contribution_id = @$_GET['id'];
   
      if (!$contact_id) {
        return;
      }
      $activities = array(0 => ts('Select...')) + ocr_get_activities_for_contact($contact_id);
      $form->add('select', 'ocr_activity', ts('Linked to Activity'), $activities, false);
      if ($contribution_id) {
        if ($selected_activity = CRM_Core_DAO::singleValueQuery("
          SELECT activity_id FROM civicrm_contribution_activity WHERE contribution_id = %1
          ", array(
          1 => array($contribution_id, 'Positive')))) {
          $form->setDefaults(array('ocr_activity' => $selected_activity));
        }
      }
      break;

    // Add custom css to the Import Preview and Summary form
    case 'CustomImport_OCR_Form_Preview':
      break;
    case 'CustomImport_OCR_Form_Summary':
      CRM_Core_Resources::singleton()->addStyleFile('no.maf.ocr', 'css/import.css', CRM_Core_Resources::DEFAULT_WEIGHT, 'html-header');
      break;
  }
}

/*
 * Implementation of hook_civicrm_config
 */
function ocr_civicrm_config() {
  $template    = &CRM_Core_Smarty::singleton();
  $templateDir = __DIR__ . DIRECTORY_SEPARATOR . 'templates';
  $phpDir      = __DIR__ . DIRECTORY_SEPARATOR . 'php';

  // Register custom templates directory
  if (is_array($template->template_dir)) {
    array_unshift($template->template_dir, $templateDir);
  } else {
    $template->template_dir = array($templateDir, $template->template_dir);
  }
  // Add custom php directory to search path
  set_include_path($phpDir . PATH_SEPARATOR . get_include_path());
}

function ocr_civicrm_disable() {
  ocr_navigation_destroy();
}

function ocr_civicrm_enable() {
  if (ocr_dependency_check()) {
    ocr_navigation_build();
  }
  // temp
  CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS civicrm_contribution_recur_mailing');
  
  // create table to link contributions to activities (of type 'Direct Mail')
  CRM_Core_DAO::executeQuery("
    CREATE TABLE IF NOT EXISTS `civicrm_contribution_activity` (
    `contribution_id` int(10) unsigned NOT NULL,
    `activity_id` int(10) unsigned NOT NULL,
    PRIMARY KEY (`contribution_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
  ");

  CRM_Core_DAO::executeQuery("
    CREATE TABLE IF NOT EXISTS `civicrm_failed_kid_numbers` (
    `transmission_number` varchar(7) NOT NULL,
    `transaction_number` varchar(7) NOT NULL,
    `kid_number` varchar(25) NOT NULL,
    `amount` decimal(20,2) NOT NULL,
    `bank_date` datetime NOT NULL,
    `import_date` datetime NOT NULL,
    `message` text NOT NULL,
    PRIMARY KEY (`transmission_number`,`transaction_number`),
    KEY `bank_date` (`bank_date`),
    KEY `import_date` (`import_date`)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
  ");
}

/*
 * Implementation of hook_civicrm_install
 */
function ocr_civicrm_install() {
  require_once __DIR__ . DIRECTORY_SEPARATOR . "install.php";
}

/*
 * Implementation of hook_civicrm_pageRun
 */
function ocr_civicrm_pageRun(&$page) {
  if ($page instanceof CRM_Admin_Page_Extensions) {
    // Do own dependency check when on extensions page, as the 
    // extensions framework won't do this for us yet.
    if (!ocr_dependency_check()) {
      ocr_set_message(ts('The MAF OCR Import extension requires the MAF KID Number extension to also be installed. ' . 
        'Please install and enable this extension to make OCR Import functionality available.'
      ));
    }
  }
}

/*
 * Implementation of hook_civicrm_post()
 */
function ocr_civicrm_post($op, $objectName, $objectId, &$objectRef) {

  /*
   * BOS1405148
   * set default activity for contribution when status is set
   * to completed. Defaulted to latest activity of contact (also for edit if 
   * not set yet)
   * 
   * Delete record in contribution_activity when contribution is deleted
   */
  if ($objectName == 'Contribution') {
    if ($op == 'delete' && !empty($objectId)) {
      $delQuery = 'DELETE FROM civicrm_contribution_activity WHERE contribution_id = %1';
      $delParams = array(1 => array($objectId, 'Positive'));
      CRM_Core_DAO::executeQuery($delQuery, $delParams);
    }
    if ($op == 'create' || $op == 'edit') {
      if ($objectRef->contribution_status_id == 1) {
        ocr_process_contribution_activity($objectId, $objectRef->contact_id, $op);
      }
    }
  }
}
/**
 * Function to process civicrm_contribution_activity
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 5 Jun 2014
 * @param int $contributionId
 * @param int $contactId
 * @param string $op
 */
function ocr_process_contribution_activity($contributionId, $contactId, $op) {
  /*
   * if op == create, get latest activity for contact and create record
   */
  if ($op == 'create') {
    $activityId = ocr_get_latest_activity($contactId);
    ocr_create_contribution_activity($contributionId, $activityId);
  }
  /*
   * if op = edit, only create record if there is none yet
   */
  if ($op == 'edit') {
    if (ocr_check_contribution_activity($contributionId) == FALSE) {
      $activityId = ocr_get_latest_activity($contactId);
      ocr_create_contribution_activity($contributionId, $activityId);
    }
  }
}
/**
 * Function to get latest activity for contact
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 5 Jun 2014
 * @param int $contactId
 * @return int $activityId
 */
function ocr_get_latest_activity($contactId) {
  $activityId = 0;
  if (empty($contactId)) {
    return $activityId;
  }
  $query = 'SELECT a.id FROM civicrm_activity a INNER JOIN civicrm_activity_target b '
    . 'ON a.id = b.activity_id WHERE target_contact_id = %1 AND is_current_revision = 1 '
    . 'ORDER BY activity_date_time DESC';
  $params = array(1 => array($contactId, 'Positive'));
  $dao = CRM_Core_DAO::executeQuery($query, $params);
  if ($dao->fetch()) {
    $activityId = $dao->id;
  }
  return $activityId;
}

/**
 * Function to check if there is a contribution_activity record for contribution
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 5 Jun 2014
 * @param int $contributionId
 * @return boolean
 */
function ocr_check_contribution_activity($contributionId) {
  if (empty($contributionId)) {
    return FALSE;
  }
  $query = 'SELECT COUNT(*) AS actCount FROM civicrm_contribution_activity WHERE contribution_id = %1';
  $params = array(1 => array($contributionId, 'Positive'));
  $dao = CRM_Core_DAO::executeQuery($query, $params);
  if ($dao->fetch()) {
    if ($dao->actCount == 0) {
      return TRUE;
    }
  }
  return FALSE;
}
/**
 * Function to create civicrm_contribution_activity_record
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 5 Jun 2014
 * @param int $contributionId
 * @param int $activityId
 */
function ocr_create_contribution_activity($contributionId, $activityId) {
  $insQuery = 'INSERT INTO civicrm_contribution_activity SET contribution_id = %1, activity_id = %2';
  $insParams = array(1 => array($contributionId, 'Positive'), 2 => array($activityId, 'Positive'));
  CRM_Core_DAO::executeQuery($insQuery, $insParams);  
}

/*
 * Implementation of hook_civicrm_xmlMenu
 */
function ocr_civicrm_xmlMenu( &$files ) {
  if (ocr_dependency_check()) {
    $files[] = __DIR__ . DIRECTORY_SEPARATOR . 'menu.xml';
  }
}

function ocr_dependency_check() {
  return function_exists('kid_number_get_info');
}

// 
function ocr_get_activities_for_contact($contact_id) {
  if (!ocr_dependency_check()) {
    CRM_Core_Error::fatal(ts("Unable to retrieve activities of type 'Direct Mail', as the KID Number extension is not installed/enabled"));
  }
  $activities = array();
  $dao = CRM_Core_DAO::executeQuery("
    SELECT a.id, a.subject, a.activity_date_time, ov.label AS activity_type 
    FROM civicrm_activity a
    INNER JOIN civicrm_activity_target at ON at.activity_id = a.id
    LEFT JOIN civicrm_option_value ov ON a.activity_type_id = ov.value AND option_group_id = 2
    WHERE at.target_contact_id = %1
    ORDER BY a.activity_date_time
    ", array(
      1 => array($contact_id, 'Positive')));
    while ($dao->fetch()) {
      $activities[$dao->id] = $dao->activity_type. ' - '.$dao->subject . ' - ' . 
        date('d-m-Y', strtotime($dao->activity_date_time));
    }
    return $activities;
}

// Create Import/Export menu
function ocr_navigation_build() {

  $parent_id = CRM_Core_BAO_Navigation::add($params = array(
    'domain_id'  => CRM_Core_Config::domainID(),
    'label'      => ts('Import/Export'),
    'name'       => 'Import/Export',
    'permission' => 'administer CiviCRM',
    'is_active'  => 1,
    'weight'     => 61,
    'parent_id'  => null
  ))->id;
    
  // Seems to set it to a different weight to the one we specified.
  // Let's update that.
  CRM_Core_DAO::executeQuery("
    UPDATE civicrm_navigation SET weight = 61 WHERE id = %1
    ", array(
      1 => array($parent_id, 'Integer')
  ));

  CRM_Core_BAO_Navigation::add($params = array(
    'domain_id'  => CRM_Core_Config::domainID(),
    'label'      => ts('OCR File Import'),
    'name'       => 'OCR File Import',
    'url'        => 'civicrm/import/ocr',
    'permission' => 'administer CiviCRM',
    'is_active'  => 1,
    'weight'     => 0,
    'parent_id'  => $parent_id
  ));

  CRM_Core_BAO_Navigation::add($params = array(
    'domain_id'  => CRM_Core_Config::domainID(),
    'label'      => ts('OCR File Export'),
    'name'       => 'OCR File Export',
    'url'        => 'civicrm/export/ocr',
    'permission' => 'administer CiviCRM',
    'is_active'  => 1,
    'weight'     => 1,
    'parent_id'  => $parent_id
  ));
  
  CRM_Core_BAO_Setting::setItem($parent_id, 'no.maf.ocr', 'menu_id');
}

// mark contributions as sent to bank (set custom field) when we export them
function ocr_mark_as_sent_to_bank($contribution_id) {
  if (!$custom_fields = reset(CRM_Core_BAO_Setting::getItem('no.maf.ocr', 'custom_fields'))) {
    CRM_Core_Error::fatal(ts(
      'Unable to retrieve custom fields for entity type contribution in %1 at line %2',
      array(
        1 => __FILE__,
        2 => __LINE__
      )
    ));
  }
  $custom_group_id = key(CRM_Core_BAO_Setting::getItem('no.maf.ocr', 'custom_fields'));
  $sent_to_bank_id = $custom_fields['sent_to_bank'];

  CRM_Core_DAO::executeQuery("
    UPDATE civicrm_value_nets_transactions_$custom_group_id
    SET sent_to_bank_$sent_to_bank_id = 1
    WHERE entity_id = $contribution_id
  ");
}
// Remove the Import/Export menu
function ocr_navigation_destroy() {
  if ($parent_id = CRM_Core_BAO_Setting::getItem('no.maf.ocr', 'menu_id')) {
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_navigation WHERE parent_id = %1
      ", array(
        1 => array($parent_id, 'Integer')
      )
    );

    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_navigation WHERE id = %1
      ", array(
        1 => array($parent_id, 'Integer')
      )
    );

    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_setting WHERE group_name = 'no.maf.ocr' AND name = 'menu_id'");
  }
}

function ocr_set_message($message) {
  // CRM_Utils_System::setUFMessage is currently unimplemented on Joomla, so ..
  if ('Joomla' == CRM_Core_Config::singleton()->userFramework) {
    return JFactory::getApplication()->enqueueMessage($message);
  }
  // Otherwise ..
  return CRM_Utils_System::setUFMessage($message);
}
/**
 * BOS1312346 function to retrieve earmarking_id from matched activity and
 * set the values in the nets_transactions custom group
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 1 Apr 2014
 * @param int $activityId
 * @param int $contributionId
 * @return void
 */
function ocr_set_act_earmark($activityId, $contributionId) {
  if (empty($activityId) || empty($contributionId)) {
    return;
  }
  try {
    $kidEarmarkCustomGroup = civicrm_api3('CustomGroup', 'Getsingle', array('name' => 'kid_earmark'));
  } catch (CiviCRM_API3_Exception $e) {
    throw new CiviCRM_API3_Exception('Could not retrieve custom group with '
      . 'name kid_earmark, configuration is incorrect. Error message from '
      . 'API CustomGroup Getsingle: '.$e->getMessage());
  }
  if (isset($kidEarmarkCustomGroup['id'])) {
    $kidEarmarkGroupId = $kidEarmarkCustomGroup['id'];
  }
  if (isset($kidEarmarkCustomGroup['table_name'])) {
    $kidEarmarkTable = $kidEarmarkCustomGroup['table_name'];
  }
  $kidEarmarkFieldParams = array(
    'custom_group_id'   =>  $kidEarmarkGroupId,
    'name'              =>  'earmarking',
    'return'            =>  'column_name'
  );
  try {
    $kidEarmarkColumn = civicrm_api3('CustomField', 'Getvalue', $kidEarmarkFieldParams);
  } catch (CiviCRM_API3_Exception $e) {
    throw new CiviCRM_API3_Exception('Could not retrieve custom field with '
      . 'name earmarking in custom group '.$kidEarmarkGroupId.', configuration '
      . 'is incorrect. Error message from API CustomField Getvalue: '.$e->getMessage());
  }
  $kidEarmarkSql = 'SELECT '.$kidEarmarkColumn.' FROM '.$kidEarmarkTable.' '
    . 'WHERE entity_id = '.$activityId;
  $daoKidEarmark = CRM_Core_DAO::executeQuery($kidEarmarkSql);
  if ($daoKidEarmark->fetch()) {
    $netsGroupParams = array(
      'name'  =>  'nets_transactions',
      'return'=>  'table_name'
    );
    try {
      $netsGroupTable = civicrm_api3('CustomGroup', 'Getvalue', $netsGroupParams);
    } catch (CiviCRM_API3_Exception $e) {
      throw new CiviCRM_API3_Exception('Could not find custom group with name
        nets_transactions, configuration is incorrect. Error message 
        from API CustomGroup Getvalue :'.$e->getMessage());
    }
    $earMarkingField = _recurring_getNetsField('earmarking');
    $balanseKontoField = _recurring_getNetsField('balansekonto');
    $netsSql = 'REPLACE INTO '.$netsGroupTable.' (entity_id, '.$earMarkingField.', '.
    $balanseKontoField.') VALUES(%1, %2, %3)';
    $netsParams = array(
      1 => array($contributionId, 'Integer'),
      2 => array($daoKidEarmark->$kidEarmarkColumn, 'Integer'),
      3 => array(1920, 'Integer')
    );
    CRM_Core_DAO::executeQuery($netsSql, $netsParams);
    /*
     * update financial type id on contribution based on earmarking
     */
    $finTypeId = _recurring_getFinType($daoKidEarmark->$kidEarmarkColumn);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_contribution SET financial_type_id = '
      . '%1 WHERE id = %2', array(
      1 => array($finTypeId, 'Integer'),
      2 => array($contributionId, 'Integer')
    ));
  }
}
