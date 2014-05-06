<?php
/**
 * Class following Singleton pattern for specific extension configuration
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 6 May 2014
 */
class php_CRM_OcrConfig {
  /*
   * singleton pattern
   */
  static private $_singleton = NULL;
  /*
   * custom group table for cases with field for financial type with group
   */
  public $finTypeGroupName = NULL;
  public $finTypeGroupId = NULL;
  public $finTypeGroupTableName = NULL;
  public $finTypeFieldName = NULL;
  public $finTypeFieldId = NULL;
  public $finTypeFieldColumnName = NULL;
  public $topLevelDonorGroupId = NULL;
  public $defaultFinTypeId = NULL;
  /**
   * Constructor function
   */
  function __construct() {
    $this->setFinTypeGroupName('fin_type_group');
    $this->getCustomGroup();
    $this->setFinTypeFieldName('fin_type_maf');
    $this->getCustomField();
    $this->setTopLevelDonorGroupId(6508);
    $this->setDefaultFinTypeId(40);
  }
  private function setFinTypeGroupName($finTypeGroupName) {
    $this->finTypeGroupName = $finTypeGroupName;
  }
  private function setFinTypeGroupId($finTypeGroupId) {
    $this->finTypeGroupId = $finTypeGroupId;
  }
  private function setFinTypeGroupTableName($finTypeGroupTableName) {
    $this->finTypeGroupTableName = $finTypeGroupTableName;
  }
  private function setFinTypeFieldName($finTypeFieldName) {
    $this->finTypeFieldName = $finTypeFieldName;
  }
  private function setFinTypeFieldId($finTypeFieldId) {
    $this->finTypeFieldId = $finTypeFieldId;
  }
  private function setFinTypeFieldColumnName($finTypeFieldColumnName) {
    $this->finTypeFieldColumnName = $finTypeFieldColumnName;
  }
  private function setTopLevelDonorGroupId($topLevelDonorGroupId) {
    $this->topLevelDonorGroupId = $topLevelDonorGroupId;
  }
  private function setDefaultFinTypeId($defaultFinTypeId) {
    $this->setDefaultFinTypeId($defaultFinTypeId);
  }
  /**
   * Function to return singleton object
   * 
   * @return object $_singleton
   * @access public
   * @static
   */
  public static function &singleton() {
    if (self::$_singleton === NULL) {
      self::$_singleton = new php_CRM_OcrConfig();
    }
    return self::$_singleton;
  }
  /**
   * Function to get custom group
   */
  private function getCustomGroup() {
    try {
      $customGroup = civicrm_api3('CustomGroup', 'Getsingle', array('name' => $this->finTypeGroupName));
      if (isset($customGroup['id'])) {
        $this->setFinTypeGroupId($customGroup['id']);
      } else {
        $this->setFinTypeGroupId(0);
      }
      if (isset($customGroup['table_name'])) {
        $this->setFinTypeGroupTableName($customGroup['table_name']);
      } else {
        $this->setFinTypeGroupTableName('');
      }
    } catch (CiviCRM_API3_Exception $ex) {
      $this->setFinTypeGroupId(0);
      $this->setFinTypeGroupTableName('');
    }
  }
  /**
   * Function to get custom field
   */
  private function getCustomField() {
    try {
      $customField = civicrm_api3('CustomField', 'Getsingle',
        array('custom_group_id' => $this->finTypeGroupId, 'name' => $this->finTypeFieldName));
      if (isset($customField['id'])) {
        $this->setFinTypeFieldId($customField['id']);
      } else {
        $this->setFinTypeFieldId(0);
      }
      if (isset($customField['column_name'])) {
        $this->setFinTypeFieldColumnName($customField['column_name']);
      } else {
        $this->setFinTypeFieldColumnName('');
      }
    } catch (CiviCRM_API3_Exception $ex) {
      $this->setFinTypeFieldId(0);
      $this->setFinTypeFieldColumnName('');
    }
  }}