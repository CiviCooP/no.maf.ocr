<?php

/* 
 * OCR Import Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

class CustomImport_OCR_Controller extends CRM_Core_Controller {

    /**
     * constructor
     */
    function __construct($title=null, $action=CRM_Core_Action::NONE, $modal=true) {
        
        parent::__construct($title, $modal);

        if (!ini_get('safe_mode')) {
            set_time_limit(0);
        }
        
        require_once "CustomImport/OCR/StateMachine.php";
        $this->_stateMachine = new CustomImport_OCR_StateMachine($this, $action);

        // create and instantiate pages
        $this->addPages($this->_stateMachine, $action);

        // add actions
        $config = CRM_Core_Config::singleton();
        $this->addActions($config->uploadDir, array('uploadFile'));

    }

}
