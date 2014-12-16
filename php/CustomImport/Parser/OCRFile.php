<?php

/* 
 * OCR Import / Export Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 * 
 *---------------------------------------------------------------------- 
 * BOS1403820 Process weekly updates with payment method from bank and
 *            notification to bank
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 24 Mar 2014
 *----------------------------------------------------------------------
 * BOS1312346 Add earmark to contribution for 9 digit KID import
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 1 Apr 2014
 *----------------------------------------------------------------------
 */

//require_once 'CRM/Import/Parser.php';

// map civi payment_instrument_ids to nets payment types - would have
// got the extension to create these on install, but MAF wanted to set them
// up themselves

define('NETS_PAYMENT_TYPES', serialize(array(
    10 => array(
        'nets_name'             => 'Giro Debited Account',
        'payment_instrument_id' => 18
    ),
    11 => array(
        'nets_name'             => 'Standing Order',
        'payment_instrument_id' => 19
    ),
    12 => array(
        'nets_name'             => 'Direct Remittance',
        'payment_instrument_id' => 20
    ),
    13 => array(
        'nets_name'             => 'BTG (Business Terminal Giro)',
        'payment_instrument_id' => 21
    ),
    14 => array(
        'nets_name'             => 'Counter Giro',
        'payment_instrument_id' => 22,
    ),
    15 => array(
        'nets_name'             => 'Avtale Giro',
        'payment_instrument_id' => 13,
    ),
    16 => array(
        'nets_name'             => 'Telegiro',
        'payment_instrument_id' => 23,
    ),
    17 => array(
        'nets_name'             => 'Giro - Paid in Cash',
        'payment_instrument_id' => 24
    ),
    18 => array(
        'nets_name'             => 'Reversing with KID',
        'payment_instrument_id' => 25
    ),
    19 => array(
        'nets_name'             => 'Purchase with KID',
        'payment_instrument_id' => 26
    ),
    20 => array(
        'nets_name'             => 'Reversing with free text',
        'payment_instrument_id' => 27
    ),
    21 => array(
        'nets_name'             => 'Purchase with free text',
        'payment_instrument_id' => 28
    )
)));

/**
 * import nets ocr file data from import table
 */
require_once 'CustomImport/Parser/Custom.php';

class CustomImport_Parser_OCRFile extends CustomImport_Parser_Custom {

    protected $custom_fields = array();
    protected $import_total  = 0;
    protected $overview      = array();

    public function getOverview() {
        return $this->overview;
    }
    
    // create an 'overview' message line (like total amount in file is invalid) which will be passed to Smarty
    protected function addOverviewLine($status, $message) {
        $this->overview[] = array(
            'status'  => $status,
            'message' => $message
        );
    }

    // return mapping array for nets payment types => civi payment instrument ids
    protected function getNetsPaymentTypes() {
        return unserialize(NETS_PAYMENT_TYPES);
    }

    // return payment instrument id for the supplied nets payment type
    protected function getPaymentInstrumentID($nets_code) {
        static $payment_types = null;
        if (!$payment_types)
            $payment_types = $this->getNetsPaymentTypes();
        if (
            isset($payment_types[$nets_code]) and 
            $this->paymentInstrumentExists($payment_types[$nets_code]['payment_instrument_id'])
        )
            return $payment_types[$nets_code]['payment_instrument_id'];
        return false;
    }

    protected function createFailureTableEntry($record, $message) {
        
        $nets_date = $this->convertNETSDate($record['nets_date']);
        
        CRM_Core_DAO::executeQuery("
            REPLACE INTO civicrm_failed_kid_numbers (transmission_number, transaction_number, kid_number, amount, bank_date, import_date, message)
            VALUES (%1, %2, %3, %4, %5, NOW(), %6)
        ", array(
              1 => array($record['transmission_number'], 'String'),
              2 => array($record['transaction_number'], 'String'),
              3 => array($record['kid'], 'String'),
              4 => array($record['amount'] / 100, 'String'),
              5 => array($nets_date, 'String'),
              6 => array($message, 'String')
           )
        );

    }

    public function import(){
        
        if (!function_exists('kid_number_lookup'))
            CRM_Core_Error::fatal(ts('Unable to run import, as the KID Number extension is not installed or enabled'));
    
        if (!$this->custom_fields = reset(CRM_Core_BAO_Setting::getItem('no.maf.ocr', 'custom_fields')))
            CRM_Core_Error::fatal(ts(
                'Unable to retrieve custom fields for entity type contribution in %1 at line %2',
                array(
                    1 => __FILE__,
                    2 => __LINE__
                )
            ));

        $table        = $this->db_table;
        $table_global = $table . '_global';
        
      /*
       * BOS1403820 table for weekly processing
       * read and process all records from $table_weekly
       */
      $table_weekly = $table.'_weekly';
      $daoWeekly = CRM_Core_DAO::executeQuery('SELECT * FROM '.$table_weekly);
      while ($daoWeekly->fetch()) {
        /*
         * first retrieve id from civicrm_contribution_recur using contact_id
         * then update civicrm_contribution_recur_offline
         */
        $recurQuery = "SELECT id FROM civicrm_contribution_recur
          WHERE contact_id = ".$daoWeekly->donor_number;
        $daoRecur = CRM_Core_DAO::executeQuery($recurQuery);
        /*
         * error if no records
         */
        if ($daoRecur->N == 0) {
          $warningMessage = "Could not find an active recurring contribution for contact ";
          $warningMessage .= $daoWeekly->donor_number.", please manually change";
          $warningMessage .= " to betalingsType ".$daoWeekly->betalings_type;
          $warningMessage .= " and notification to bank ".$daoWeekly->notification_bank;
          $this->addReportLine('warning', ts($warningMessage));
        } else {
          while ($daoRecur->fetch()) {
            if (!$this->test) {
              $updateFields = array();
              if ($daoWeekly->notification_bank == "Yes") {
                $updateFields[] = "notification_for_bank = 1";
              } else {
                $updateFields[] = "notification_for_bank = 0";
              }
              if ($daoWeekly->betalings_type == "AvtaleGiro") {
                $updateFields[] = "payment_type_id = 2";
              } elseif ($daoWeekly->betalings_type == "PaperGiro") {
                $updateFields[] = "payment_type_id = 3";
              }
              if (!empty($updateFields)) {
                $updQuery = "UPDATE civicrm_contribution_recur_offline SET ";
                $updQuery .= implode(", ", $updateFields);
                $updQuery .= " WHERE recur_id = ".$daoRecur->id;
                CRM_Core_DAO::executeQuery($updQuery);
              }
            }
            $successMessage = "Recurring contribution for contact ".$daoWeekly->donor_number;
            $successMessage .= " updated with notification is ".$daoWeekly->notification_bank;
            if (!empty($daoWeekly->betalings_type)) {
              $successMessage .= " and payment type is ".$daoWeekly->betalings_type;
            }
            $this->addReportLine('ok', ts($successMessage));
          }
        }
      }
      // end BOS1403820
        
        
        
        // get all fieldnames on import table
        $fields = $this->getFieldNames();

        // get all ids on import table and iterate through them
        foreach ($this->getAllIDs() as $id) {
            
            // query entire record for this id ..
            $record    = array(); 
            $resultDAO = CRM_Core_DAO::executeQuery(
                "SELECT * FROM $table WHERE id = %1",
                array(
                    1 => array($id, 'Positive')
                )
            );
            if ($resultDAO->fetch())        
                foreach ($fields as $field)
                    $record[$field] = $resultDAO->$field;

            $record['kid'] = trim($record['kid']);
            $this->import_total += (float)($record['amount'] / 100);
            
            switch (strlen($record['kid'])) {
                
                // 9 digit kid number ..
                case 9:
                    // delegate to import_from_activity function
                    $this->import_from_activity($record);
                    break;
                
                // 15 digit kid number ..
                case 15:
                    // delegate to import_from_contribution_recur function(s)
                    // nb: there is now a different one for historic contribution recurs
                    if (((int)substr($record['kid'], 6, 8)) < MAF_HISTORIC_CUTOFF_ID)                           
                        $this->import_from_historic_contribution_recur($record);
                    else
                        $this->import_from_contribution_recur($record);
                    break;

                default:
                    // kid number of invalid length
                    $this->addReportLine('error', ts(
                        "KID number '%1' is an invalid length (%2 chars detected, should be 9 or 15) at line %3",
                        array(
                            1 => $record['kid'],
                            2 => strlen($record['kid']),
                            3 => $record['line_no']
                        )
                    ));
                    break;

            }

        }

        // in test mode, validate the total amount we've accumulated matches 
        // that in the end transmission line of the OCR File
        if ($this->test) {
            
            $ocr_file_total = (float)
                (CRM_Core_DAO::singleValueQuery("SELECT total_amount FROM $table_global") / 100);
            
            if (CRM_Utils_Money::format($this->import_total) === CRM_Utils_Money::format($ocr_file_total))
                $this->addOverviewLine('ok', ts(
                    'Total sum of transactions matches total amount in OCR File (%1)',
                    array(
                        1 => CRM_Utils_Money::format($ocr_file_total)
                    )
                ));
            else
                $this->addOverviewLine('warning', ts(
                    'Total sum of transactions (%1) differs from total amount in OCR File (%2)',
                    array(
                        1 => CRM_Utils_Money::format($this->import_total),
                        2 => CRM_Utils_Money::format($ocr_file_total)
                    )
                ));             
        
        }

        if (!$this->test) {
            CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS $table");
            CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS $table_global");
            /*
             * BOS1403820 table for weekly processing
             */
            CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS $table_weekly");
        }            
    }

    // sub method of import() - handles importing as part of activity-based direct mailing
    // (9 digit KID number)
    protected function import_from_activity(&$record) {
      static $status_id = null;
      if (!$status_id) {
        $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
      }
      // lookup activity
      if (!$kid = kid_number_get_info($record['kid'])) {
        $message = ts("Failed looking up activity for KID number '%1' at line %2", array(
          1 => $record['kid'],
          2 => $record['line_no']
        ));
        $this->addReportLine('warning', $message);
        if (!$this->test) {
          $this->createFailureTableEntry($record, $message);
        }
        return;
      }

      //code below is not neccesary
      //we check if a 9KID number is linked to a contact rather than demanding a 
      //certain entity
      /* if ($kid['entity'] != 'Activity' && $kid['entity'] != 'ActivityTarget') {

        $message = ts(
        "Matched wrong type of entity (%1 - should be Activity) for KID number '%2' at line %3",
        array(
        1 => $kid['entity'],
        2 => $record['kid'],
        3 => $record['line_no']
        )
        );

        $this->addReportLine('error', $message);

        if (!$this->test)
        $this->createFailureTableEntry($record, $message);

        return;
        } */
      $activity_id = false;
      if ($kid['entity'] == 'Activity' || $kid['entity'] == 'ActivityTarget') {
        $activity_id = $kid['entity_id'];
      }
      
      //get contact ID from kid record
      $contact_id = $kid['contact_id'];
      //if contact ID is not set try to retrieve it from the linked entity (Activity)
      if (empty($contact_id) && ($kid['entity'] == 'Activity' || $kid['entity'] == 'ActivityTarget')) {
        $contact_id = CRM_Core_DAO::singleValueQuery("
               SELECT target_contact_id FROM civicrm_activity_target
               WHERE activity_id = %1 and target_contact_id = %2", array(
              1 => array($kid['entity_id'], 'Positive'),
              2 => array($kid['contact_id'], 'Positive'),
        ));
      }

      //check if contact_id is found
      if (empty($contact_id)) { 
        $message = ts("Failed looking up activity target contact for KID number '%1' at line %2 (%3 ID %4 and Contact ID %5)", array(
          1 => $record['kid'],
          2 => $record['line_no'],
          3 => $kid['entity'],
          4 => $kid['entity_id'],
          5 => $kid['contact_id'],
            )
        );
        $this->addReportLine('warning', $message);
        if (!$test) {
          $this->createFailureTableEntry($record, $message);
        }
        return;
      }

      // check for duplicate transaction numbers ..
      if ($this->transactionIDExists($record)) {
          return;
      }

      $trxn_id = $record['transmission_number'] . '-' . $record['transaction_number'];
      $aksjon_id = $kid['aksjon_id'];

      // lookup aksjon_id from the activity, if it exists
      if (empty($aksjon_id) && !empty($activity_id)) {
        $aksjon_id = CRM_Core_DAO::singleValueQuery("SELECT aksjon_id_38 FROM civicrm_value_maf_norway_aksjon_import_1578 WHERE entity_id = %1", array(1 => array($activity_id, 'Positive')));
      }
      if (empty($kid['earmarking']) && !empty($activity_id)) {
        $kid['earmarking'] = CRM_Core_DAO::singleValueQuery("SELECT _remerking_98 FROM civicrm_value_kid_earmark_1591 WHERE entity_id = %1", array(1 => array($activity_id, 'Positive')));
      }

      // payment instrument check - this will fail on circle dev server, as payment instruments not present
      // we should not see this warning in production
      if (!$payment_instrument_id = $this->getPaymentInstrumentID($record['transaction_type'])) {
        $this->addReportLine('warning', ts("Payment instrument unmatched for transaction type %1 - KID Number '%2', line %3. %4", array(
            1 => $record['transaction_type'],
            2 => $record['kid'],
            3 => $record['line_no'],
            4 => $this->test ? ts('Record will still be imported, but with payment instrument unset.') : ts('Record was imported with payment instrument unset.')
        )));
      }

      if ($this->test) {
        // if test mode, inform the user a match was made, but do not update
        $this->addReportLine('ok', ts("Matched KID number '%1' with activity id %2 at line %3", array(
          1 => $record['kid'],
          2 => $kid['entity_id'],
          3 => $record['line_no']
        )));  
      } else {      
        // create contribution linked to the activity
        $params = array(
          'total_amount'           => $record['amount'] / 100,
          'financial_type_id'      => 1, 
          'contact_id'             => $contact_id,
          'receive_date'           => $this->convertNETSDate($record['nets_date']),
          'trxn_id'                => $trxn_id,
          'invoice_id'             => md5(uniqid(rand())),
          'source'                 => 'NETS',
          'contribution_status_id' => $status_id['Completed']
        );

        if ($payment_instrument_id) {
          $params['payment_instrument_id'] = $payment_instrument_id;
        }

        foreach ($this->custom_fields as $name => $id) {                
          switch (true) {
            case isset($record[$name]):
              $params['custom_' . $id] = $record[$name];
              break;
            case $name == 'kid_number':
              $params['custom_' . $id] = $record['kid'];
              break;
                    
            /*
             * BOS1311802 Critical bug in OCR import
             * Erik Hommel (erik.hommel@civicoop.org) on 22/11/2013
             * trying to create a contribution with a null value
             * in aksjon_id. So included a test on aksjon_id being
             * set and not empty
             */
            case $name == 'aksjon_id':
              if (isset($aksjon_id) && !empty($aksjon_id)) {
                $params['custom_' . $id] = $aksjon_id;
              }
              break;
            case $name == 'balans_konto':
              // balans konto always set to 1920
              $params['custom_' . $id] = '1920';
              break;
            case $name == 'sent_to_bank':
              // always set to 'No' for this type of transaction
              $params['custom_' . $id] = 0;
              break;
          }
        }
        try {
          $result = civicrm_api3('contribution', 'create', $params);  
        } catch (CiviCRM_API3_Exception $e) {
          $message = ts("An error occurred saving contribution data for KID Number '%1' (%2) at line %3: %4", array(
            1 => $record['kid'],
            2 => $this->getDisplayName($contact_id),
            3 => $record['line_no'],
            4 => $e->getMessage()
          ));

          $this->addReportLine('error', $message);
          $this->createFailureTableEntry($record, $message);

          return;
        }
        
        $contribution = reset($result['values']);
        /*
         * BOS1406389/BOS1405148
         */
        $actQuery = ocr_contribution_activity_query($contribution['id'], $activity_id); 
        CRM_Core_DAO::singleValueQuery($actQuery, array(
              1 => array($contribution['id'], 'Positive'),
              2 => array($activity_id, 'Positive')
           )
        );
        /*
         * BOS1312346 retrieve earmarking from activity and set as default
         * for contribution in nets transactions custom group
         */
        $earmark = '';
        if (!empty($kid['earmarking'])) {
          $earmark = $kid['earmarking'];
        } else {
          $earmark = ocr_get_act_earmark($activity_id, $contribution['id']);
        }
        ocr_set_act_earmark($earmark, $contribution['id']);
        // end BOS1312346

        $this->addReportLine('ok', ts("Successfully created contribution (id: %1) for KID Number '%2' (%3) at line %4", array(
          1 => $contribution['id'],
          2 => $record['kid'],
          3 => CRM_Core_DAO::singleValueQuery(
              "SELECT display_name FROM civicrm_contact WHERE id = %1",
              array(1 => array($contact_id, 'Positive'))
          ),
          4 => $record['line_no']
        )));
        /*
         * BOS1405148 add contribution/activity and contribution/donor group
         * based on contact and receive_date
         */
        $latestActivityId = ocr_get_latest_activity($contribution['contact_id']);
        ocr_create_contribution_activity($contribution['id'], $latestActivityId);
        $donorGroupId = ocr_get_contribution_donorgroup($contribution['id'], $contribution['receive_date'], $contribution['contact_id']);
        ocr_create_contribution_donorgroup($contribution['id'], $donorGroupId);

        $this->addReportLine('ok', ts("Successfully completed contribution (id: %1) for KID Number '%2' (%3) at line %4", array(
          1 => $contribution['id'],
          2 => $record['kid'],
          3 => $this->getDisplayName($contribution['contact_id']),
          4 => $record['line_no']
        )));
      }
    }
    
    /*
     * Following an IRC conversation with Steinar on 16/10/2013, we decided the only way to 
     * get historic Avtale Giro transactions to successfully import, would be to write
     * a different routine just to handle those. This acts on any 15 digit KID numbers
     * where digits 7-14 are less than 600,000 (at the current time of writing)
     * andyw@circle, 16/10/2013
     */
    protected function import_from_historic_contribution_recur(&$record) {
        
        static $status_id = null;
        if (!$status_id)
            $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());

        $test = $this->test;

        // Get the Activity id of the AV Activity (type 52) for this KID number
        $dao = CRM_Core_DAO::executeQuery("
            SELECT custom.entity_id, custom.aksjon_id_38 FROM civicrm_value_maf_norway_aksjon_import_1578 custom
        INNER JOIN civicrm_activity a ON a.id = custom.entity_id
             WHERE custom.aksjon_kid15_correction_44 = %1 
               AND a.activity_type_id = %2
        ", array(
              1 => array($record['kid'], 'String'),
              2 => array(52, 'Positive') // 52 - activity type 'AV'
           )
        );

        if (!$dao->fetch()) {
            
            $message = ts(
                "Failed looking up activity for KID number '%1' at line %2",
                array(
                    1 => $record['kid'],
                    2 => $record['line_no']
                )
            );
            $this->addReportLine('warning', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);
            
            return;
        
        }

        $activity_id = $dao->entity_id;
        $aksjon_id   = $dao->aksjon_id_38;

        // retrieve the activity
        try {
            $result = civicrm_api3('activity', 'get', array(
                'id' => $activity_id
            ));
        } catch (CiviCRM_API3_Exception $e) {
            
            $message = ts(
                "An error occurred retrieving activity id %1 for KID Number '%2' at line %3: %4",
                array(
                    1 => $activity_id,
                    2 => $record['kid'],
                    3 => $record['line_no'],
                    4 => $e->getMessage()
                )
            ); 
            $this->addReportLine('warning', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);

            return;
        
        }

        // check api returned something ..
        if (!$result['values']) {
            
            // this should never happen, as we already joined against civicrm_activity in the intial query,
            // but it doesn't hurt to check.
            $message = ts(
                "API returned no result for activity_id %1 (KID %2) at line %3. Cannot import this record.",
                array(
                    1 => $activity_id,
                    2 => $record['kid'],
                    3 => $record['line_no']
                )
            );
            $this->addReportLine('error', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);

            return;       
        }

        $activity = reset($result['values']);

        // get target contact id for the activity (does not support multiple targets)
        if (!$contact_id = CRM_Core_DAO::singleValueQuery("
            SELECT target_contact_id FROM civicrm_activity_target
             WHERE activity_id = %1
        ", array(
              1 => array($activity['id'], 'Positive')
           )
        )) {
            
            $message = ts(
                "Unable to get target contact for activity id %1 - KID Number '%2', line %3. %4",
                array(
                    1 => $activity['id'],
                    2 => $record['kid'],
                    3 => $record['line_no'],
                    4 => $test ? ts('Record will not be imported.') : ts('Record was not imported.')
                )
            );
            $this->addReportLine('warning', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);
                  
            return;          
        }

        // check for duplicate transaction numbers ..
        if ($this->transactionIDExists($record))
            return;

        $trxn_id = $record['transmission_number'] . '-' . $record['transaction_number'];

        // payment instrument check - this will fail on circle dev server, as payment instruments not present
        // we should not see this warning in production
        if (!$payment_instrument_id = $this->getPaymentInstrumentID($record['transaction_type']))
            $this->addReportLine('warning', ts(
                "Payment instrument unmatched for transaction type %1 - KID Number '%2', line %3. %4",
                array(
                    1 => $record['transaction_type'],
                    2 => $record['kid'],
                    3 => $record['line_no'],
                    4 => $test ? ts('Record will still be imported, but with payment instrument unset.') : ts('Record was imported with payment instrument unset.')
                )
            ));
       
        if ($test) {
            
            // in test mode, let user know a contribution was matched, but do not update
            $this->addReportLine('ok', ts(
                "Matched KID number '%1' with historic activity (id %2) at line %3. Record is ready to import.",
                array(
                    1 => $record['kid'],
                    2 => $activity['id'],
                    3 => $record['line_no']
                )
            ));

        } else {

            $nets_date = $this->convertNETSDate($record['nets_date']);

            // create contribution_recur
            try {
                $result = civicrm_api3('contribution_recur', 'create', array(
                    'contact_id'         => $contact_id,
                    'installments'       => 1,
                    'frequency_interval' => 1,
                    'frequency_unit'     => 'day',
                    'amount'             => $record['amount'] / 100,
                    'start_date'         => $nets_date,
                    'end_date'           => $nets_date
                ));
            } catch (CiviCRM_API3_Exception $e) {
                
                $message = ts(
                    "An error occurred creating Contribution Recur for historic KID Number '%1' (%2) at line %3: %4",
                    array(
                        1 => $record['kid'],
                        2 => $this->getDisplayName($contact_id),
                        3 => $record['line_no'],
                        4 => $e->getMessage()
                    )
                );
                $this->addReportLine('error', $message);
                if (!$test)
                    $this->createFailureTableEntry($record, $message);

                return;
            
            }
            
            $contribution_recur = reset($result['values']);
            
            // prepare contribution params ..
            $params = array(
                'contribution_recur_id'  => $contribution_recur['id'],
                'contact_id'             => $contact_id,
                'receive_date'           => $nets_date,
                'total_amount'           => $record['amount'] / 100,
                'trxn_id'                => $trxn_id,
                'source'                 => 'NETS',
                'contribution_status_id' => $status_id['Completed'],
                'financial_type_id'      => 1
            );

            foreach ($this->custom_fields as $name => $id) {
                
                switch (true) {
                    case isset($record[$name]):
                        $params['custom_' . $id] = $record[$name];
                        break;
                    case $name == 'kid_number':
                        $params['custom_' . $id] = $record['kid'];
                        break;
                    case $name == 'aksjon_id':
                        $params['custom_' . $id] = $aksjon_id;
                        break;
                    case $name == 'balans_konto':
                        // balans konto always set to 1920
                        $params['custom_' . $id] = '1920';
                        break;
                    case $name == 'sent_to_bank':
                        // always set to 'No' for this type of transaction
                        $params['custom_' . $id] = 0;
                        break;
                }

            }

            // create contribution
            try {
                $result = civicrm_api3('contribution', 'create', $params);
            } catch (CiviCRM_API3_Exception $e) {
                
                $message = ts(
                    "An error occurred creating Contribution for historic KID Number '%1' (%2) at line %3: %4",
                    array(
                        1 => $record['kid'],
                        2 => $this->getDisplayName($contact_id),
                        3 => $record['line_no'],
                        4 => $e->getMessage()
                    )
                );
                $this->addReportLine('error', $message);
                if (!$test)
                    $this->createFailureTableEntry($record, $message);
                       
                return;
            
            }

            $contribution = reset($result['values']);
            /*
             * BOS1406389/BOS1405148
             */
            $actQuery = ocr_contribution_activity_query($contribution['id'], $activity_id);
            // link to activity
            CRM_Core_DAO::singleValueQuery($actQuery, array(
                  1 => array($contribution['id'], 'Positive'),
                  2 => array($activity_id, 'Positive')
               )
            );

            $this->addReportLine('ok', ts(
                "Successfully created contribution (id: %1) for KID Number '%2' (%3) at line %4",
                array(
                    1 => $contribution['id'],
                    2 => $record['kid'],
                    3 => $this->getDisplayName($contact_id),
                    4 => $record['line_no']
                )
            ));

        }

    }

    protected function import_from_contribution_recur(&$record) {

        static $status_id = null;
        if (!$status_id)
            $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());

        $test = $this->test;

        // lookup contribution
        if (!$kid = kid_number_get_info($record['kid'])) {
            
            $message = ts(
                "Failed looking up KID number '%1' at line %2",
                array(
                    1 => $record['kid'],
                    2 => $record['line_no']
                )
            );
            $this->addReportLine('warning', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);
            
            return;
        
        }
             
        if ($kid['entity'] != 'Contribution') {
            
            $message = ts(
                "Matched wrong type of entity (%1 - should be Contribution) for KID number '%2' at line %3",
                array(
                    1 => $kid['entity'],
                    2 => $record['kid'],
                    3 => $record['line_no']
                )
            );
            $this->addReportLine('error', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);

            return;
        }

        // check for duplicate transaction numbers ..
        if ($this->transactionIDExists($record))
            return;

        $trxn_id = $record['transmission_number'] . '-' . $record['transaction_number'];

        // payment instrument check - this will fail on circle dev server, as payment instruments not present
        // we should not see this warning in production
        if (!$payment_instrument_id = $this->getPaymentInstrumentID($record['transaction_type']))
            $this->addReportLine('warning', ts(
                "Payment instrument unmatched for transaction type %1 - KID Number '%2', line %3",
                array(
                    1 => $record['transaction_type'],
                    2 => $record['kid'],
                    3 => $record['line_no']
                )
            ));
        
        // changed, andyw - as per IRC conversation with Steinar 15/10/2013
        // 15 digit KIDs are now stored on contributions, so lookup the 
        // contribution associated with the KID number instead
        try {    
            
            $result = civicrm_api3('contribution', 'get', array('id' => $kid['entity_id']));
        
        } catch (CiviCRM_API3_Exception $e) {

            // warn if no match
            $message = ts(
                "Failed looking up contribution for KID number '%1' at line %2",
                array(
                    1 => $record['kid'],
                    2 => $record['line_no']
                )
            );
            $this->addReportLine('warning', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);

            return;
        }

        $contribution = reset($result['values']);
		
        if (!isset($contribution['id']) || !$contribution['id']) {
			$message = ts(
                "Failed looking up contribution for KID number '%1' at line %2",
                array(
                    1 => $record['kid'],
                    2 => $record['line_no']
                )
            );
            $this->addReportLine('warning', $message);
            if (!$test)
                $this->createFailureTableEntry($record, $message);

            return;
        }
            
        if ($test) {
            /*
             * BOS1403275 message depending on contribution status:
             * - if pending, match message
             * - if other, match and create
             */
            if ($contribution['contribution_status_id'] == 2) {
                $warningMessage = ts(
                    "Matched KID number '%1' with pending contribution (id %2) at line %3",
                    array(
                        1 => $record['kid'],
                        2 => $contribution['id'],
                        3 => $record['line_no']
                    ));
            } else {
                $warningMessage = ts(
                    "KID number '%1' found in existing but not pending contribution (id %2), new contribution will be created and matched for line %3",
                    array(
                        1 => $record['kid'],
                        2 => $contribution['id'],
                        3 => $record['line_no']
                    ));
            }
            // in test mode, let user know a contribution was matched, but do not update
            $this->addReportLine('ok', $warningMessage);

        } else {

            // not a test - construct api params and update contribution

            // nets_date in a non-compatible format - fix that:
            $nets_date = $this->convertNETSDate($record['nets_date']);

            $params = array(
                'total_amount'           => $record['amount'] / 100,
                'financial_type_id'      => 1, 
                'contact_id'             => $contribution['contact_id'],
                'receive_date'           => $nets_date,
                'trxn_id'                => $trxn_id,
                'source'                 => 'NETS',
                'contribution_status_id' => $status_id['Completed']
            );
            
            /*
             * BOS1403275 only add contribution['id'] to param list
             * if status was pending so contribution is updated.
             * In all other cases, add a new contribution to match with
             */
            if ($contribution['contribution_status_id'] == 2) {
                $params['id'] = $contribution['id'];
            }

            if ($payment_instrument_id)
                $params['payment_instrument_id'] = $payment_instrument_id;

            foreach ($this->custom_fields as $name => $id) {
                
                switch (true) {
                    case isset($record[$name]):
                        $params['custom_' . $id] = $record[$name];
                        break;
                    case $name == 'kid_number':
                        $params['custom_' . $id] = $record['kid'];
                        break;
                    /*case $name == 'aksjon_id':
                        $params['custom_' . $id] = $aksjon_id;
                        break;*/
                    case $name == 'balans_konto':
                        // balans konto always set to 1920
                        $params['custom_' . $id] = '1920';
                        break;
                    /*case $name == 'sent_to_bank':
                        // always set to 'No' for this type of transaction
                        $params['custom_' . $id] = 0;
                        break;*/
                }

            }

            try {    
                
                $result = civicrm_api3('contribution', 'create', $params);
            
            } catch (CiviCRM_API3_Exception $e) {
                
                $message = ts(
                    "An error occurred saving contribution data for KID Number '%1' (%2) at line %3: %4",
                    array(
                        1 => $record['kid'],
                        2 => $this->getDisplayName($contribution['contact_id']),
                        3 => $record['line_no'],
                        4 => $e->getMessage()
                    )
                );
                $this->addReportLine('error', $message);
                if (!$test)
                    $this->createFailureTableEntry($record, $message);

                return;

            }

            $contribution = reset($result['values']);
            
            /*
             * BOS1405148 add contribution/activity and contribution/donor group
             * based on contact and receive_date
             */
            $latestActivityId = ocr_get_latest_activity($contribution['contact_id']);
            ocr_create_contribution_activity($contribution['id'], $latestActivityId);
            $donorGroupId = ocr_get_contribution_donorgroup($contribution['id'], $contribution['receive_date'], $contribution['contact_id']);
            ocr_create_contribution_donorgroup($contribution['id'], $donorGroupId);
            
            $this->addReportLine('ok', ts(
                "Successfully completed contribution (id: %1) for KID Number '%2' (%3) at line %4",
                array(
                    1 => $contribution['id'],
                    2 => $record['kid'],
                    3 => $this->getDisplayName($contribution['contact_id']),
                    4 => $record['line_no']
                )
            ));

        }


    }

    // convert nets dates to a format parsable by strtotime
    protected function convertNETSDate($nets_date) {
        return implode('', array(
            '20',
            substr($nets_date, -2),
            '-',
            substr($nets_date, 2, 2),
            '-',
            substr($nets_date, 0, 2)
        ));
    }

    // check payment instrument id exists
    protected function paymentInstrumentExists($payment_instrument_id) {
        
        return (bool)CRM_Core_DAO::singleValueQuery("
                SELECT 1 FROM civicrm_option_value ov
            INNER JOIN civicrm_option_group og ON ov.option_group_id = og.id
                 WHERE ov.value = %1
                   AND og.name  = 'payment_instrument'
        ", array(
              1 => array($payment_instrument_id, 'Positive')
           )
        );

    }

    // check for duplicate transaction numbers
    protected function transactionIDExists(&$record) {
        
        $trxn_id = $record['transmission_number'] . '-' . $record['transaction_number'];

        $dao = CRM_Core_DAO::executeQuery(
            "SELECT id, contact_id FROM civicrm_contribution WHERE trxn_id = %1",
            array(
                1 => array($trxn_id, 'String')
            )
        );

        if ($dao->fetch()) {
            $message = ts(
                "Duplicate transaction number (%1) - already exists for contribution id %2 (%3) - KID number '%4' at line %5. %6",
                array(
                    1 => $record['transaction_number'],
                    2 => $dao->id,
                    3 => $this->getDisplayName($dao->contact_id),
                    4 => $record['kid'],
                    5 => $record['line_no'],
                    6 => $this->test ? ts('Record will not be imported.') : ts('Record was not imported.')
                )
            );
            $this->addReportLine('warning', $message);
            if (!$this->test)
                $this->createFailureTableEntry($record, $message);
            return true;
        }
        
        return false;    
    
    }

}