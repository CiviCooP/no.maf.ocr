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

/*
 * BOS1405148 define constant for top level donor journey group
 */
define('MAF_DONORJOURNEY_GROUP', 6509);

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
      /*
       * BOS1405148 - add donor group
       */
      $values = $form->getVar('_values');
      $donorGroups = array(0 => ts('Select...')) + ocr_get_donorgroups();
      $form->add('select', 'donor_group', ts('Donor Group'), $donorGroups, false);
      /*
       * set defaults for donor_group and linked activity
       */
      $defaults = ocr_set_contribution_enhanced_defaults($contribution_id, $contact_id);
      if (!empty($defaults)) {
        $form->setDefaults($defaults);
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
/**
 * Function to set the defaults for contribution donorgroup and linked activity
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 17 Jun 2014
 * @param int $contributionId
 * @param int $contactId
 * @return array $defaults
 * 
 */
function ocr_set_contribution_enhanced_defaults($contributionId, $contactId) {
  $defaults = array();
  ocr_set_default_contribution_activity($contributionId, $defaults, $contactId);
  ocr_set_default_contribution_donorgroup($contributionId, $defaults, $contactId);
  return $defaults;
}
/**
 * Function to get contribution_activity record and set default when found
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicooporg>
 * @date 17 Jun 2014
 * @param int $contributionId
 * @param array $defaults
 */
function ocr_set_default_contribution_activity($contributionId, &$defaults, $contactId) {
  if (!empty($contributionId)) {
    $query = 'SELECT activity_id FROM civicrm_contribution_activity WHERE contribution_id = %1';
    $dao = CRM_Core_DAO::executeQuery($query, array(1 => array($contributionId, 'Positive')));
    if ($dao->fetch()) {
      $defaults['ocr_activity'] = $dao->activity_id;
    }
  } else {
    $defaults['ocr_activity'] = ocr_get_latest_activity($contactId);
  }
}
/**
 * Function to get contribution_donorgroup record and set default when found
 * Set default to donorgroup at receipt date when not found
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicooporg>
 * @date 17 Jun 2014
 * @param int $contributionId
 * @param int $contactId
 * @param array $defaults
 */
function ocr_set_default_contribution_donorgroup($contributionId, &$defaults, $contactId) {
  if (!empty($contributionId)) {
    $query = 'SELECT group_id FROM civicrm_contribution_donorgroup WHERE contribution_id = %1';
    $dao = CRM_Core_DAO::executeQuery($query, array(1 => array($contributionId, 'Positive')));
    if ($dao->fetch()) {
      $defaults['donor_group'] = $dao->group_id;
    } else {
      $receiveDate = civicrm_api3('Contribution','Getvalue', array('id' => $contributionId, 'return' => 'receive_date'));
      if (!empty($receiveDate)) {
        $defaults['donor_group'] = ocr_get_contribution_donorgroup($contributionId, $receiveDate, $contactId);
      }
    }
  } else {
    $defaults['donor_group'] = ocr_get_contribution_donorgroup(0, NULL, $contactId);
  }
}
/**
 * Function to retrieve groups in Donor Journey
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommmel@civicoop.org>
 * @date 12 Jun 2014
 * @return array $groupList
 * 
 */
function ocr_get_donorgroups() {
  $groupList = array();
  /*
   * retrieve Donor Journey Group children
   */
  $parents = array(MAF_DONORJOURNEY_GROUP);
  $hasChildren = ocr_check_group_has_children($parents);
  while ($hasChildren == TRUE) {
    foreach ($parents as $parent) {
      $groupChildren = civicrm_api3('Group', 'Getvalue', array('id' => $parent, 'return' => 'children'));
      if (!empty($groupChildren)) {
        $children = explode(',', $groupChildren);
        $parents = array();
        foreach($children as $child) {
          $parents[] = $child;
          $groupData = civicrm_api3('Group', 'Getsingle', array('id' => $child));
          $groupList[$groupData['id']] = $groupData['title'];
        }
      }
    }
    $hasChildren = ocr_check_group_has_children($parents);
  }
  return $groupList;
}
/**
 * Function to check if one of the group parents has children
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 12 Jun 2014
 * @param array $groupIds
 * @return boolean $hasChildren
 */
function ocr_check_group_has_children($groupIds) {
  $hasChildren = FALSE;
  foreach ($groupIds as $groupId) {
    $groupChildren = civicrm_api3('Group', 'Getvalue', array('id' => $groupId, 'return' => 'children'));
    if (!empty($groupChildren)) {
      $hasChildren = TRUE;
    }
  }
  return $hasChildren;
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
  
  /*
   * BOS1405148 create table to record group of donor at time of contribution
   */
  CRM_Core_DAO::executeQuery('
    CREATE TABLE IF NOT EXISTS `civicrm_contribution_donorgroup` (
    `contribution_id` int(10) unsigned NOT NULL,
    `group_id` int(10) unsigned NOT NULL,
    PRIMARY KEY (`contribution_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
  ');
  
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
   * to completed and linked activity is still empty. Defaulted to latest activity 
   * of contact (also for edit if not set yet)
   * 
   * Delete record in contribution_activity when contribution is deleted
   */
  if ($objectName == 'Contribution') {
    if ($op == 'delete' && !empty($objectId)) {
      $delActQuery = 'DELETE FROM civicrm_contribution_activity WHERE contribution_id = %1';
      $delParams = array(1 => array($objectId, 'Positive'));
      $delDonorQuery = 'DELETE FROM civicrm_contribution_donorgroup WHERE contribution_id = %1';
      CRM_Core_DAO::executeQuery($delActQuery, $delParams);
      CRM_Core_DAO::executeQuery($delDonorQuery, $delParams);
    }
    if ($op == 'create') {
      ocr_process_contribution_activity($objectId, $objectRef->contact_id);
      ocr_process_contribution_donorgroup($objectId, $objectRef->receive_date);
    }
  }
}
/**
 * Implementation of hook civicrm_postProcess
 * (BOS1405148 - update contribution activity of donorgroup)
 */
function ocr_civicrm_postProcess($formName, &$form) {
  if ($formName == 'CRM_Contribute_Form_Contribution') {
    $action = $form->getVar('_action');
    $contributionId = $form->getVar('_id');
    if ($action == CRM_Core_Action::UPDATE) {
      $values = $form->getVar('_submitValues');
      if (isset($values['ocr_activity'])) {
        ocr_create_contribution_activity($contributionId, $values['ocr_activity']);
      }
      if (isset($values['donor_group'])) {
        ocr_create_contribution_donorgroup($contributionId, $values['donor_group']);
      }
    }
  }
}
/**
 * Function to process civicrm_contribution_group
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 12 Jun 2014
 * @param int $contributionId
 * @param date $receiptDate
 */
function ocr_process_contribution_donorgroup($contributionId, $receiptDate) {
  $checkExists = ocr_check_contribution_group($contributionId);
  if ($checkExists == FALSE) {
    $groupId = ocr_get_contribution_donorgroup($contributionId, $receiptDate, 0);
    ocr_create_contribution_donorgroup($contributionId, $groupId);
  }
}
/**
 * Function get get the donor journey group the contact is member of, if any
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 17 Jun 2014
 * @param array $contribution
 * @param date $receiptDate
 * @return int $groupId
 */
function ocr_get_contribution_donorgroup($contributionId, $receiptDate, $contactId) {
  $groupId = 0;
  if (!empty($contributionId)) {
    $contactId = civicrm_api3('Contribution', 'Getvalue', array('id' => $contributionId, 'return' => 'contact_id'));
  }
  if (empty($receiptDate)) {
    $receiptDate = date('Ymd');
  }
  /*
   * get all groups for contact
   */
  $contactGroups = CRM_Contact_BAO_GroupContact::getContactGroup($contactId);
  foreach ($contactGroups as $contactGroup) {
    /*
     * if group is donor journey group, check if active on receip date
     */
    if (ocr_check_group_is_donorgroup($contactGroup['group_id']) == TRUE 
      && ocr_donorgroup_active_on_date($contactGroup, $receiptDate) == TRUE) {
      $groupId = $contactGroup['group_id'];
    }
  }  
  return $groupId;
}
/**
 * Function to check if group membership is active on input date
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 17 Jun 2014
 * @param array $group
 * @param date $date
 * @return boolean
 */
function ocr_donorgroup_active_on_date($group, $date) {
  /*
   * if isset in_date in group, then check if in_date is before or 
   * on date because contact is in group at the moment
   */
  $checkDate = CRM_Utils_Date::processDate($date);
  if (isset($group['in_date'])) {
    $inDate = CRM_Utils_Date::processDate($group['in_date']);
    if ($inDate <= $checkDate) {
      return TRUE;
    } else {
      return FALSE;
    }
  }
  if (isset($group['out_date'])) {
    $outDate = CRM_Utils_Date::processDate($group['out_date']);
    if ($outDate > $checkDate) {
      return TRUE;
    } else {
      return FALSE;
    }
  }
  return FALSE;
}
/**
 * Function to check if group is part of donor journey group
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 17 Jun 2014
 * @param int $groupId
 * @return boolean
 */
function ocr_check_group_is_donorgroup($groupId) {
  if ($groupId == MAF_DONORJOURNEY_GROUP) {
    return TRUE;
  }
  $processedAll = FALSE;
  $levelParents = array($groupId);
  while ($processedAll == FALSE) {
    foreach ($levelParents as $levelParent) {
      $groupParents = civicrm_api3('Group', 'Getvalue', array('id' => $levelParent, 'return' => 'parents'));
      if (empty($groupParents)) {
        $processedAll = TRUE;
      } else {
        $processedAll = FALSE;
      }
      if (!empty($groupParents)) {
        $parents = explode(',', $groupParents);
        $levelParents = array();
        foreach($parents as $parent) {
          $levelParents[] = $parent;
          if ($parent == MAF_DONORJOURNEY_GROUP) {
            return TRUE;
          }
        }
      }
    }
  }
  return FALSE;
}

/**
 * Function to process civicrm_contribution_activity
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 5 Jun 2014
 * @param int $contributionId
 * @param int $contactId
 */
function ocr_process_contribution_activity($contributionId, $contactId) {
  $checkExists = ocr_check_contribution_activity($contributionId);
  if ($checkExists == FALSE) {
    $activityId = ocr_get_latest_activity($contactId);
    ocr_create_contribution_activity($contributionId, $activityId);
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
 * Function to check if there is a contribution_group record for contribution
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 12 Jun 2014
 * @param int $contributionId
 * @return boolean
 */
function ocr_check_contribution_group($contributionId) {
  if (empty($contributionId)) {
    return FALSE;
  }
  $query = 'SELECT COUNT(*) AS groupCount FROM civicrm_contribution_donorgroup WHERE contribution_id = %1';
  $params = array(1 => array($contributionId, 'Positive'));
  $dao = CRM_Core_DAO::executeQuery($query, $params);
  
  if ($dao->fetch()) {
    if ($dao->groupCount > 0) {
      return TRUE;
    }
  }
  return FALSE;
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
    if ($dao->actCount > 0) {
      return TRUE;
    }
  }
  return FALSE;
}
/**
 * Function to create civicrm_contribution_donorgroup record
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 12 Jun 2014
 * @param int $contributionId
 * @param int $groupId
 */
function ocr_create_contribution_donorgroup($contributionId, $groupId) {
  $insQuery = ocr_contribution_donorgroup_query($contributionId);
  $insParams = array(1 => array($contributionId, 'Positive'), 2 => array($groupId, 'Positive'));
  CRM_Core_DAO::executeQuery($insQuery, $insParams);  
}
/**
 * Function to create civicrm_contribution_activity record
 * (BOS1405148)
 * 
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 5 Jun 2014
 * @param int $contributionId
 * @param int $activityId
 */
function ocr_create_contribution_activity($contributionId, $activityId) {
  $insQuery = ocr_contribution_activity_query($contributionId);
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
/**
* Function to create the insert or update query for contribution/activity
* (BOS1406389)
*
* @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
* @date 16 Jun 2014
* @param int $contributionId
* @return string $query
*/
function ocr_contribution_activity_query($contributionId) {
  $query = '';
  if (empty($contributionId)) {
    return $query;
  }
  $countQry = 'SELECT COUNT(*) AS countRecords FROM civicrm_contribution_activity WHERE contribution_id = %1';
  $countDao = CRM_Core_DAO::executeQuery($countQry, array(1 => array($contributionId, 'Positive')));
  if ($countDao->fetch()) {
    $query .= ' civicrm_contribution_activity SET contribution_id = %1, activity_id = %2';
    if ($countDao->countRecords > 0) {
      $query = 'UPDATE '.$query.' WHERE contribution_id = %1';
    } else {
      $query = 'INSERT INTO '.$query;
    }
  }
  return $query;
}
/**
* Function to create the insert or update query for contribution/donorgroup
* (BOS1406389)
*
* @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
* @date 21 Jun 2014
* @param int $contributionId
* @return string $query
*/
function ocr_contribution_donorgroup_query($contributionId) {
  $query = '';
  if (empty($contributionId)) {
    return $query;
  }
  $countQry = 'SELECT COUNT(*) AS countRecords FROM civicrm_contribution_donorgroup WHERE contribution_id = %1';
  $countDao = CRM_Core_DAO::executeQuery($countQry, array(1 => array($contributionId, 'Positive')));
  if ($countDao->fetch()) {
    $query .= ' civicrm_contribution_donorgroup SET contribution_id = %1, group_id = %2';
    if ($countDao->countRecords > 0) {
      $query = 'UPDATE '.$query.' WHERE contribution_id = %1';
    } else {
      $query = 'INSERT INTO '.$query;
    }
  }
  return $query;
}
