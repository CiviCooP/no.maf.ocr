<?php

/* 
 * OCR Import Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

/**
 * State machine for defining the different states of the import process.
 */
class CustomImport_OCR_StateMachine extends CRM_Core_StateMachine {

    /**
     * constructor
     *
     * @param object  CRM_Import_Controller
     * @param int     $action
     *
     * @return object CRM_Import_StateMachine
     */
    function __construct(&$controller, $action = CRM_Core_Action::NONE) {
        
        parent::__construct($controller, $action);
        
        $this->_pages = array(
            'CustomImport_OCR_Form_DataSource' => null,
            'CustomImport_OCR_Form_Preview'    => null,
            'CustomImport_OCR_Form_Summary'    => null
        );
        
        $this->addSequentialPages($this->_pages, $action);
    
    }

}