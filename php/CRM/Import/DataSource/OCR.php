<?php

/* 
 * OCR Import / Export Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

/*
 * Data extraction class for OCR Files
 */

class CRM_Import_DataSource_OCR extends CRM_Import_DataSource {
    
    public static $line;
    protected static $line_no;

    public function getInfo() {
        return array('title' => ts('OCR File'));
    }

    public function preProcess(&$form) { /* Shhh, I am not really here */ }

    public function buildQuickForm(&$form) {

        $form->add('hidden', 'hidden_dataSource', 'CRM_Import_DataSource_OCR');

        $uploadFileSize = CRM_Core_Config::singleton()->maxImportFileSize;
        $uploadSize     = round(($uploadFileSize / (1024 * 1024)), 2);
        
        $form->assign('uploadSize', $uploadSize);
        $form->add('file', 'uploadFile', ts('Import OCR File'), 'size=30 maxlength=255', TRUE);

        $form->setMaxFileSize($uploadFileSize);
        $form->addRule('uploadFile', ts('File size should be less than %1 MBytes (%2 bytes)', array(1 => $uploadSize, 2 => $uploadFileSize)), 'maxfilesize', $uploadFileSize);
        $form->addRule('uploadFile', ts('Input file must be in OCR format'), 'utf8File');
        $form->addRule('uploadFile', ts('A valid file must be uploaded.'), 'uploadedfile');

        $form->addElement('checkbox', 'skipColumnHeader', ts('First row contains column headers'));

    }

    public function postProcess(&$params, &$db, &$form = null) {
      
        $file = $params['uploadFile']['name'];

        // hack to prevent multiple tables
        $this->_params['import_table_name'] = $this->get('importTableName');
        if (!$this->_params['import_table_name'])
            $this->_params['import_table_name'] = 'civicrm_import_job_' . md5(uniqid(rand(), true));

        $result = self::OCRFile2Table($db, $file, $this->_params['import_table_name']);

        //$this->set('originalColHeader', CRM_Utils_Array::value('original_col_header', $result));

        $table = $result['import_table_name'];
        $importJob = new CRM_Import_ImportJob($table);
        $this->set('importTableName', $importJob->getTableName());
    
    }

    /**
    * Create import table and populate it with data based on the contents of the OCR file
    *
    * @param object $db     handle to the database connection
    * @param string $file   file name to load
    * @param string $table  name of temporary table to create
    *
    * @return array $result containing name of the created table
    */

    private static function OCRFile2Table(&$db, $file, $table) {

        $result = array(
            'import_table_name' => $table
        );

        // attempt to validate the file, as much as possible
        $conditions = array(
             'has_start_transmission_record' => false,
             'has_start_assignment_record'   => false
        );

        // helper function to get characters between (and including) the specified start
        // and end positions (first character is position 1)
        $getChars = function($startpos, $endpos) {
            $length = $endpos - $startpos;
            return substr(CRM_Import_DataSource_OCR::$line, --$startpos, ++$length);
        };

        $fileData = file_get_contents($file);
        if ($fileData === false)
            CRM_Core_Error::fatal(ts("Unable to read %1", array(1 => $file)));
        elseif (!$fileData)
            CRM_Core_Error::fatal(ts("%1 was empty", array(1 => $file)));

        // Create temporary import tables:

        $table_global = $table . '_global';

        $db->query("DROP TABLE IF EXISTS $table");
        $db->query("DROP TABLE IF EXISTS $table_global");

        $db->query("        
            CREATE TABLE IF NOT EXISTS $table (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `transmission_number` varchar(7) NOT NULL,
              `transaction_type` varchar(2) NOT NULL,
              `transaction_number` varchar(7) NOT NULL,
              `amount` varchar(17) NOT NULL,
              `kid` varchar(25) NOT NULL,
              `bank_date` varchar(6) NOT NULL,
              `nets_date` varchar(6) NOT NULL,
              `debit_account` varchar(11) NOT NULL,
              `line_no` int(11) NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS $table_global (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `total_amount` varchar(17) NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;
        ");

        $fileData = str_replace("\r", '', $fileData);
        $fileData = explode("\n", $fileData);

        // iterate through lines in file
        self::$line_no = 0;

        foreach ($fileData as self::$line) {
           
            ++self::$line_no;

            // if line is not a whitespace line ..   
            if (trim(self::$line)) {
                
                // work out what type of line we're dealing with ..
                switch(true) {
                    
                    // start transmission record 
                    case $getChars(1, 8) == 'NY000010':
                        
                        // get and store transmission number - this will be the same for 
                        // all imported records
                        $conditions['has_start_transmission_record'] = true;
                        $transmission_no = $getChars(17, 23);

                        // check data recipient field, make sure the file was intended for us.
                        if ($getChars(24, 31) != NETS_CUSTOMER_ID_NUMBER and 
                            $getChars(24, 31) != NETS_TEST_CUSTOMER_ID_NUMBER)
                            CRM_Core_Error::fatal(
                                ts('Invalid data recipient (%1), should be: %2 (live) or %3 (test)', array(
                                    1 => $getChars(24, 31),
                                    2 => NETS_CUSTOMER_ID_NUMBER,
                                    3 => NETS_TEST_CUSTOMER_ID_NUMBER
                                ))
                            );

                        break;
                    
                    // start assignment record
                    case $getChars(1, 8) == 'NY090020':
                        $conditions['has_start_assignment_record'] = true;
                        break;

                    // amount item 1 record
                    case $getChars(1, 4) == 'NY09' and
                         $getChars(7, 8) == '30':
                        
                        // check all conditions have been met before attempting to go any further
                        if (in_array(false, $conditions))
                            CRM_Core_Error::fatal(ts("%1 is not a valid OCR file", array(1 => $file)));

                        $amountItem = array(
                            'transmission_number' => $transmission_no,
                            'transaction_type'    => $getChars(5, 6),
                            'transaction_number'  => $getChars(9, 15),
                            'nets_date'           => $getChars(16, 21),
                            'amount'              => $getChars(33, 49),
                            'kid'                 => $getChars(50, 74)
                        );
                        break;

                    // amount item 2 record
                    case $getChars(1, 4) == 'NY09' and
                         $getChars(7, 8) == '31':
                        
                        $amountItem += array(
                            'bank_date'     => $getChars(42, 47),
                            'debit_account' => $getChars(48, 58)
                        );

                        // end of amount item 2 record - insert data into table
                        CRM_Core_DAO::executeQuery("
                            INSERT INTO $table (
                                id, transmission_number, transaction_type, transaction_number, 
                                amount, kid, bank_date, nets_date, debit_account, line_no
                            )
                            VALUES (
                                NULL, %1, %2, %3, %4, %5, %6, %7, %8, %9
                            ) 
                        ", array(
                              1 => array($amountItem['transmission_number'], 'String'),
                              2 => array($amountItem['transaction_type'], 'String'),
                              3 => array($amountItem['transaction_number'], 'String'),
                              4 => array($amountItem['amount'], 'String'),
                              5 => array($amountItem['kid'], 'String'),
                              6 => array($amountItem['bank_date'], 'String'),
                              7 => array($amountItem['nets_date'], 'String'),
                              8 => array($amountItem['debit_account'], 'String'),
                              9 => array(self::$line_no-1, 'Integer')
                           )
                        );
                    
                        break;

                    // amount item 3 record
                    case $getChars(1, 4) == 'NY09' and
                         $getChars(7, 8) == '32':
                        // not applicable to transaction types we're importing
                        break;

                    // end assignment record
                    case $getChars(1, 8) == 'NY090088':
                        // ignore for now, may need to grab some data from this line in due course
                        break;

                    // end tranmission record
                    case $getChars(1, 8) == 'NY000089':
                        // query total_amount from end transmission record and place in import global table
                        $total_amount = $getChars(25, 41);
                        CRM_Core_DAO::executeQuery("
                            INSERT INTO $table_global (id, total_amount) VALUES (NULL, %1)
                        ", array(
                              1 => array((string)$total_amount, 'String')
                           )
                        );
                        break;


                }
            
            }

        }
        
        return $result;

    }
};