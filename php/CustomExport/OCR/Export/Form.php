<?php

/* 
 * OCR Import Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

class CustomExport_OCR_Export_Form extends CRM_Core_Form {

   /*
    * Build form
    */
    function buildQuickForm() {

        CRM_Utils_System::setTitle(ts('OCR File Export'));
        
        // set date defaults
        $defaults = array();
        $date     = new DateTime("now");

        $date->modify('first day of next month');
        $date->modify('+14 day'); // = 15th of next month
        $defaults['start_date'] = $date->format('c');

        /*
         * Erik Hommel (CiviCooP) - remarks from CiviCRM 4.4.12 upgrade:
         * second date should be the 14th of the next month, not 15th
         */
        $date->modify('+1 month -1 day');

        $defaults['end_date'] = $date->format('c');

        list($defaults['start_date'], $defaults['start_date_time']) 
            = CRM_Utils_Date::setDateDefaults($defaults['start_date'], 'activityDate');  

        list($defaults['end_date'], $defaults['end_date_time']) 
            = CRM_Utils_Date::setDateDefaults($defaults['end_date'], 'activityDate');

        // add date fields
        $this->addDate('start_date', ts('Start Date'), true, array('formatType' => 'activityDate'));
        $this->addDate('end_date', ts('End Date'), true, array('formatType' => 'activityDate'));

        // for testing ..
        $this->addElement('checkbox', 'debug', ts('Debug mode'));      

        // apply defaults
        if (isset($defaults))        
            $this->setDefaults($defaults);

        $this->addButtons(
            array( 
                array(
                    'type'      => 'next', 
                    'name'      => ts('Generate OCR File') . ' »',  
                    'isDefault' => true   
                ), 
            ) 
        );
    }

   /*
    * Generate OCR file
    */
    public function postProcess() {
        
        $post = $this->controller->exportValues();

        require_once implode(
            DIRECTORY_SEPARATOR,
            array(
                CRM_Core_Config::singleton()->extensionsDir,
                'no.maf.ocr',
                'php',
                'CustomExport',
                'OCRExport.php'
            )
        );

        $ocr = new OCRExport(array(
            'output'     => 'inline',
            'debug'      => isset($post['debug']) and !empty($post['debug']),
            'start_date' => date('c', strtotime($post['start_date'])),
            'end_date'   => date('c', strtotime($post['end_date']))
        ));

        $ocr->generate();
    
    }

}
