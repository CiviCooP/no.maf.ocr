<?php

/* 
 * OCR Import Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

/**
 * Previews the uploaded file and returns summary
 * statistics
 */
class CustomImport_OCR_Form_Preview extends CRM_Core_Form {

    public function preProcess()
    {
        //get the data from the session
        if (!$this->get('import_done')) {             
			require_once('CustomImport/Parser/OCRFile.php');
			$ImportJob = new CustomImport_Parser_OCRFile();
			$ImportJob->db_table = $this->get('importTableName');
			$ImportJob->test = TRUE;
			$ImportJob->import();        
	        $this->assign('report', $ImportJob->getReport());
            $this->assign('overview', $ImportJob->getOverview());
		}
    }

    public function buildQuickForm( ) {
        
        CRM_Core_Resources::singleton()->addStyleFile(
            'no.maf.ocr', 
            'css/import.css',
            CRM_Core_Resources::DEFAULT_WEIGHT,
            'html-header'
        );

        $path  = "_qf_MapField_display=true";
        $qfKey = CRM_Utils_Request::retrieve( 'qfKey', 'String', $form );
        
        if (CRM_Utils_Rule::qfKey($qfKey))
            $path .= "&qfKey=$qfKey";
        
        $previousURL = CRM_Utils_System::url('civicrm/import/ocr', $path, false, null, false);
        $cancelURL   = CRM_Utils_System::url('civicrm/import/ocr', 'reset=1');
        
        $buttons = array(
             array ( 'type'      => 'back',
                     'name'      => ts('<< Previous'),
                     'js'        => array( 'onclick' => "location.href='{$previousURL}'; return false;" ) ),
             array ( 'type'      => 'next',
                     'name'      => ts('Import Now >>'),
                     'spacing'   => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
                     'isDefault' => true,
                     'js'        => array( 'onclick' => "return verify( );" )

                     ),
             array ( 'type'      => 'cancel',
                     'name'      => ts('Cancel'),
                     'js'        => array( 'onclick' => "location.href='{$cancelURL}'; return false;" ) ),
        );
        
        $this->addButtons($buttons);

    }


    public function getTitle( ) {
        return ts('Preview');
    }

    public function postProcess( ) {
	
        $session =& CRM_Core_Session::singleton();
        $userID  = $session->get('userID');
        require_once 'CRM/ACL/BAO/Cache.php';
        CRM_ACL_BAO_Cache::updateEntry($userID);

        // run the import
        if(!$this->get('import_done')) {             
			//require_once('CustomImport/Parser/OCR.php');
			$ImportJob = new CustomImport_Parser_OCRFile();
			$ImportJob->db_table=$this->get('importTableName');
			$ImportJob->import();        
	        $this->set('final_report', $ImportJob->getReport());
	        $this->set('import_done', true);
	
		}
               
        // update cache after we done with runImport
        require_once 'CRM/ACL/BAO/Cache.php';
        CRM_ACL_BAO_Cache::updateEntry( $userID );

        // add all the necessary variables to the form
        $this->set('final_report', $ImportJob->getReport());
//		$importJob->setFormVariables( $this );
        
        // check if there is any error occured
        
        $errorMessage = array();
       
		foreach($ImportJob->getReport() as $key => $value) {
		    $errorMessage[] = strip_tags('"'.$value['status'].'","'.$value['message'].'"');
		}

        


		$config = CRM_Core_Config::singleton( );
		$errorFilename = "ocr." . $this->get('importTableName') . ".custom.report.csv"; 
		$this->set('final_report_csv_url', $config->imageUploadURL . $errorFilename);
		$errorFilePath = $config->imageUploadDir . $errorFilename;
		if ( $fd = fopen( $errorFilePath, 'w' ) ) {
		    fwrite($fd, implode("\n", $errorMessage));
		}
		fclose($fd);

    }


}
