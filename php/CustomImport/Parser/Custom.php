<?php

/* 
 * OCR Import / Export Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

/*
 * Abstract generic importer functionality into a parent class we can inherit from
 * andyw@circle, 06/10/2013
 */

abstract class CustomImport_Parser_Custom {

    public $test = false;

    public $results = array(); // array showing results for each transaction as they are processed.
    public $db_table;

    protected $report  = array(); // item report lines, eg: unmatched kid number

    // force child classes to implement import() method
    abstract public function import();

    public function getReport() {
        return $this->report;
    }
    
    // create a report message line, which will be passed to Smarty
    protected function addReportLine($status, $message) {
        $this->report[] = array(
            'status'  => $status,
            'message' => $message
        );
    }

    protected function addResult($message) {
        $this->results[] = $message;
    }

    // get all ids for records in import table
    protected function getAllIDs() {
        $ids = array();
        $dao = CRM_Core_DAO::executeQuery("SELECT id FROM {$this->db_table}");
        while ($dao->fetch())
            $ids[] = $dao->id;
        return $ids;     
    }

    protected function getDisplayName($contact_id) {
    	return CRM_Core_DAO::singleValueQuery(
            "SELECT display_name FROM civicrm_contact WHERE id = %1",
            array(1 => array($contact_id, 'Positive'))
        );
    }

    // return all fields on import table
    protected function getFieldNames() {
        $fields = array();
        $dao    = CRM_Core_DAO::executeQuery("DESC {$this->db_table}");
        while ($dao->fetch())
            $fields[] = $dao->Field;
        return $fields;
    }

}


