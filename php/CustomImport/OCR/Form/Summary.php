<?php

/* 
 * OCR Import Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

/**
 * Summarizes the import results
 */
class CustomImport_OCR_Form_Summary extends CRM_Core_Form {

    public function preProcess() {
		
        $this->assign('final_report', $this->get('final_report'));
		$this->assign('final_report_csv_url', $this->get('final_report_csv_url'));
		$config = CRM_Core_Config::singleton();
        
    }

    public function buildQuickForm() {
        
        $this->addButtons(array(
             array(
                'type'      => 'next',
                'name'      => ts('Done'),
                'isDefault' => true   
             ),
        ));

    }
    
    public function postProcess() {
        
        $dao = new CRM_Core_DAO();
        $db  = $dao->getDatabaseConnection();
        
        $importTableName = $this->get('importTableName');
        
        // do a basic sanity check here
        if (strpos($importTableName, 'civicrm_import_job_') === 0) {
            $query = "DROP TABLE IF EXISTS $importTableName";
            $db->query($query);
        }

    }

    public function getTitle() {
        return ts('Summary');
    }

}