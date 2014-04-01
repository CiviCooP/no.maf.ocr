<?php

/*
 * OCR Import/Export Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html
 */

/*
 * Main business logic for the OCR file export process
 * andyw@circle, 03/09/2013
 */

class OCRExport {

    protected $output     = 'file';
    protected $filename   = null;
    protected $start_date = null;
    protected $end_date   = null;
    protected $debug      = false;
    /*
     * BOS1403431 protected $memberFinTypeId
     */
    protected $memberFinTypeId = 0;

    // define the export
    public function __construct($params) {

        // copy contents of $params to correspondingly named properties
        foreach (array_keys($params) as $param)
            $this->$param = $params[$param];

        // start and end date should be set, but provide a default if not
        $date = new DateTime("now");

        $date->modify('first day of next month');
        $date->modify('+14 day');         // = 15th of next month
        if (is_null($this->start_date))
            $this->start_date = $date->format('c');

        if (is_null($this->end_date)) {
            $date->modify('+1 month');    // = 15th of the month after
            $this->end_date = $date->format('c');
        }
        /*
         * BOS1403431 retrieve and store financial type for Medlem
         * throw fatal error if not found, export can not proceed if there
         * is no Medlem financial type
         */
        $finTypeParams = array(
            'name'  =>  "Medlem",
            'return'=>  "id"
        );
        try {
            $finTypeId = civicrm_api3('FinancialType', 'Getvalue', $finTypeParams);
            $this->memberFinTypeId = $finTypeId;
        } catch (CiviCRM_API3_Exception $e) {
            CRM_Core_Error::fatal(ts("Could not find a valid financial type for Medlem, 
            error from API entity FinancialType, action Getvalue is : ".$e->getMessage()));
        }
        // end BOS1403431

    }

    // do the export
    public function generate() {

        $inline   = ($this->output == 'inline');
        $filename = $this->filename;
        $debug    = $this->debug;
        $lines    = array();

        $zerofill = function($length) {
            return str_pad(0, $length, 0);
        };

        // basic sanity check
        if (!function_exists('kid_number_lookup'))
            CRM_Core_Error::fatal(ts('Unable to run export, as the KID Number extension is not installed and/or enabled'));
			
		if (!function_exists('recurring_process_offline_recurring_payments'))
            CRM_Core_Error::fatal(ts('Unable to run export, recurring payment extension is not installed and/or enabled'));

        if (!$inline and !$filename)
            CRM_Core_Error::fatal(ts('No filename specified to write export file to.'));
			
		//generate contributions based on the recurring payments
		recurring_process_offline_recurring_payments();

        $customer_id     = NETS_CUSTOMER_ID_NUMBER;
        $transmission_no = date('dmy') . '7';
        $data_recipient  = '00008080';       // Nets' ID

        // now the magic: generate the file ..

        // 2.1 Start record for transmission
        if ($debug) {
            $lines[] = '# Start record for transmission';
            $lines[] = '';
            $lines[] = "# 'NY',             // pos 1-2:   format code       - alphanumeric - always NY";
            $lines[] = "# '00',             // pos 3-4:   service code      - numeric      - always 00";
            $lines[] = "# '00',             // pos 5-6:   transmission type - numeric      - always 00";
            $lines[] = "# '10',             // pos 7-8:   record type       - numeric      - record type 'start transmission': 10";
            $lines[] = "# customer_id,      // pos 9-16:  data sender       - numeric      - data sender code (MAF's customer ID) - 00131936";
            $lines[] = "# transmission_no,  // pos 17-23: transmission no   - numeric      - generated serial number for transmission - date of file creation + serial number";
            $lines[] = "# data_recipient,   // pos 24-31: data recipient    - numeric      - nets' ID - always 00008080";
            $lines[] = "# zerofill(49)      // pos 32-80: filler            - numeric      - zero fill to 80 chars";
            $lines[] = '';
        }

        $lines[] = implode('', array(
            'NY',             // pos 1-2:   format code       - alphanumeric - always NY
            '00',             // pos 3-4:   service code      - numeric      - always 00
            '00',             // pos 5-6:   transmission type - numeric      - always 00
            '10',             // pos 7-8:   record type       - numeric      - record type 'start transmission': 10
            $customer_id,     // pos 9-16:  data sender       - numeric      - data sender code (customer ID) - using 00008080 as per example file (??)
            $transmission_no, // pos 17-23: transmission no   - numeric      - generated serial number for transmission (??)
            $data_recipient,  // pos 24-31: data recipient    - numeric      - nets' ID - always 00008080
            $zerofill(49)     // pos 32-80: filler            - numeric      - zero fill to 80 chars
        ));


        // 2.2.1 Start record for assignments with due payment
        $assignment_no   = str_pad(date('d', strtotime('+1 day')) . date('m', strtotime('+12 months')) . '16', '7', '0', STR_PAD_LEFT);
        $assignment_acct = '70586360610';

        if ($debug) {
            $lines[] = "\n";
            $lines[] = '# Start record for assignment';
            $lines[] = '';
            $lines[] = "# 'NY',            // pos 1-2:   format code       - alphanumeric - always NY";
            $lines[] = "# '21',            // pos 3-4:   service code      - numeric      - avtalegiro code always 21";
            $lines[] = "# '00',            // pos 5-6:   assignment type   - numeric      - always 00";
            $lines[] = "# '20',            // pos 7-8:   record type       - numeric      - record type 'start assignment': 20";
            $lines[] = "# zerofill(9),     // pos 9-17:  filler            - numeric      - zero fill to 17 chars";
            $lines[] = "# assignment_no,   // pos 18-24: assignment no     - numeric      - unique ID of assignments per payee's recipient agreement 12 months + 1 day ahead";
            $lines[] = "# assignment_acct, // pos 25-35: assignment acct   - numeric      - payee's (agreement's) account number.";
            $lines[] = "# zerofill(45)     // pos 36-80: filler            - numeric      - zero fill to 80 chars";
            $lines[] = '';
        }

        $lines[] = implode('', array(
            'NY',             // pos 1-2:   format code       - alphanumeric - always NY
            '21',             // pos 3-4:   service code      - numeric      - avtalegiro code always 21
            '00',             // pos 5-6:   assignment type   - numeric      - always 00
            '20',             // pos 7-8:   record type       - numeric      - record type 'start assignment': 20
            $zerofill(9),     // pos 9-17:  filler            - numeric      - zero fill to 17 chars
            $assignment_no,   // pos 18-24: assignment no     - numeric      - unique ID of assignments per payee's recipient agreement 12 months + 1 day ahead
            $assignment_acct, // pos 25-35: assignment acct   - numeric      - payee's (agreement's) account number.
            $zerofill(45)     // pos 36-80: filler            - numeric      - zero fill to 80 chars
        ));

        $transaction_count = 0;
        $assignment_total  = 0;
        $transaction_no    = 0;

        foreach ($this->getContributions() as $contribution) {

            // Mark this contribution as having been sent to the bank, so it
            // doesn't get removed or updated later.
            ocr_mark_as_sent_to_bank($contribution['id']);

            ++$transaction_count;
            ++$transaction_no;

            $transaction_no = str_pad($transaction_no, 7, '0', STR_PAD_LEFT);

            // lookup kid number for this contribution
            $kid_number = kid_number_lookup('Contribution', $contribution['id']);

            /*
             * Transaction record
             * A valid OCR-transaction consists of both an amount posting 1 and an amount posting 2.
             * A transaction is only valid if both postings are filled in.
             */

            // 2.2.2 Amount posting 1 record
            if ($debug) {
                $lines[] = "\n";
                $lines[] = '# Amount posting 1 record - contribution id ' . $contribution['id'] . ' - kid no ' . $kid_number;
                $lines[] = '';
                $lines[] = "# 'NY',             // pos 1-2:   format code       - alphanumeric - always NY";
                $lines[] = "# '21',             // pos 3-4:   service code      - numeric      - avtalegiro code always 21";
                $lines[] = "# transaction_type, // pos 5-6:   transaction type  - numeric      - valid values: 02 (no notification from bank) or 21 (notification from bank)";
                $lines[] = "# '30',             // pos 7-8:   record type       - numeric      - record type for amount posting 1 always 30";
                $lines[] = "# transaction_no,   // pos 9-15:  transaction no    - numeric      - seems to be a unique incrementing id per transaction";
                $lines[] = "# date,             // pos 16-21: date              - numeric      - due date - no more than 12 months in advance; if not work day, following work day is used";
                $lines[] = "# str_pad('', 11),  // pos 22-32: filler            - alphanumeric - filler - this field is not in use and must be cleared";
                $lines[] = "# amount,           // pos 33-49: amount            - numeric      - amount in øre - using contribution total amount x 100, zero filled to 17 digits";
                $lines[] = "# kid,              // pos 50-74: kid number        - alphanumeric - kid number, using 15 digit code stored against contribution, left whitespace filled to 25 chars";
                $lines[] = "# zerofill(6)       // pos 75-80: filler            - numeric      - zero filled to 80 chars";
                $lines[] = '';
            }

            $contribution['notification'] == 1 ? $transaction_type = '21' : $transaction_type = '02';

            $date             = date('dmy', strtotime($contribution['receive_date']));
            $amount           = str_pad((float)$contribution['total_amount'] * 100, 17, 0, STR_PAD_LEFT);
            $kid              = str_pad($kid_number, 25, ' ', STR_PAD_LEFT);

            $lines[] = implode('', array(
                'NY',              // pos 1-2:   format code       - alphanumeric - always NY
                '21',              // pos 3-4:   service code      - numeric      - avtalegiro code always 21
                $transaction_type, // pos 5-6:   transaction type  - numeric      - valid values: 02 (no notification from bank) or 21 (notification from bank)
                '30',              // pos 7-8:   record type       - numeric      - record type for amount item 1 always 30
                $transaction_no,   // pos 9-15:  transaction no    - numeric      - seems to be a unique incrementing id per transaction
                $date,             // pos 16-21: date              - numeric      - due date - no more than 12 months in advance; if not work day, following work day is used
                str_pad('', 11),   // pos 22-32: filler            - alphanumeric - filler - this field is not in use and must be cleared
                $amount,           // pos 33-49: amount            - numeric      - amount in øre - using contribution total amount x 100, zero filled to 17 digits
                $kid,              // pos 50-74: kid number        - alphanumeric - kid number, using 15 digit code stored against contribution, left whitespace filled to 25 chars
                $zerofill(6)       // pos 75-80: filler            - numeric      - zero filled to 80 chars
            ));


            // 2.2.3 Amount posting 2 record
            if ($debug) {
                $lines[] = "\n";
                $lines[] = '# Amount posting 2 record - contribution id ' . $contribution['id'] . ' - kid no ' . $kid_number;
                $lines[] = '';
                $lines[] = "# 'NY',             // pos 1-2:   format code        - alphanumeric - always NY";
                $lines[] = "# '21',             // pos 3-4:   service code       - numeric      - avtalegiro code always 21";
                $lines[] = "# transaction_type, // pos 5-6:   transaction type   - numeric      - valid values: 02 (no notifcation from bank) or 21 (notification from bank)";
                $lines[] = "# '31',             // pos 7-8:   record type        - numeric      - record type for amount item 2 always 31";
                $lines[] = "# transaction_no,   // pos 9-15:  transaction no     - numeric      - transaction no from amount posting 1";
                $lines[] = "# abbreviated_name, // pos 16-25: abbreviated name   - alphanumeric - abbreviated name for payer";
                $lines[] = "# str_pad('', 25),  // pos 26-50: filler             - alphanumeric - filler - this field is not in use and must be cleared";
                $lines[] = "# external_ref,     // pos 51-75: external reference - alphanumeric - must be transferred to to payer's statement and AvtaleGiro info";
                $lines[] = "# zerofill(5)       // pos 76-80: filler             - numeric      - zero filled to 80 chars";
            	$lines[] = '';
            }

            $first_name = @iconv("UTF-8", "ASCII//IGNORE", mb_substr($contribution['first_name'], 0, 5));
            $last_name  = @iconv("UTF-8", "ASCII//IGNORE", mb_substr($contribution['last_name'], 0, 5));

            $contribution['notification'] == 1 ? $transaction_type = '21' : $transaction_type = '02';

            $abbreviated_name = str_pad($first_name . $last_name, 10); // First five letters of first name + first five letters of last name, 0 padded to 10
            /*
             * BOS1403431 external_ref has to contain 'Medlemskap' if for membership
             */
            if ($contribution['financial_type_id'] == $this->memberFinTypeId) {
                $external_ref = str_pad('Medlemskap', 25);
            } else { 
                $external_ref     = str_pad('MAF Norge', 25);  // Who are they giving money to?
            }

            $lines[] = implode('', array(
                'NY',              // pos 1-2:   format code        - alphanumeric - always NY
                '21',              // pos 3-4:   service code       - numeric      - avtalegiro code always 21
                $transaction_type, // pos 5-6:   transaction type   - numeric      - valid values: 02 (no notification from bank) or 21 (notification from bank)
                '31',              // pos 7-8:   record type        - numeric      - record type for amount item 2 always 31
                $transaction_no,   // pos 9-15:  transaction no     - numeric      - transaction no from amount item 1
                $abbreviated_name, // pos 16-25: abbreviated name   - alphanumeric - abbreviated name for payer
                str_pad('', 25),   // pos 26-50: filler             - alphanumeric - filler - this field is not in use and must be cleared
                $external_ref,     // pos 51-75: external reference - alphanumeric - must be transferred to payer's statement and AvtaleGiro info
                $zerofill(5)       // pos 76-80: filler             - numeric      - zero filled to 80 chars
            ));

            // keep running total for assignment
            $assignment_total += (float)$contribution['total_amount'];

            // update the list of dates for earliest & latest transactions
            $datelist[] = $contribution['receive_date'];
        }


        // 2.2.5 End record for assignment
        if ($debug) {
            $lines[] = "\n";
            $lines[] = '# End record for assignment';
            $lines[] = '';
            $lines[] = "# 'NY',              // pos 1-2:   format code       - alphanumeric - always NY";
            $lines[] = "# '21',              // pos 3-4:   service code      - numeric      - avtalegiro code always 21";
            $lines[] = "# '00',              // pos 5-6:   assignment type   - numeric      - assignment type always 00";
            $lines[] = "# '88',              // pos 7-8:   record type       - numeric      - record type always 88";
            $lines[] = "# num_transactions,  // pos 9-16:  num transactions  - numeric      - number of transactions in the assignment, left zero filled to 8 digits";
            $lines[] = "# num_records,       // pos 17-24: number of records - numeric      - number of records, 2 per transaction + start and end record, left zero filled to 8 digits (??)";
            $lines[] = "# assignment_total,  // pos 25-41: total amount      - numeric      - the sum of all transactions in the assignment, specified in øre";
            $lines[] = "# earliest_date,     // pos 42-47: earliest due date - numeric      - the earliest due date for transactions in the assignment - using contribution receive date (DDMMYY)";
            $lines[] = "# latest_date,       // pos 48-53: latest due date   - numeric      - the latest due date for transactions in the assignment - using contribution receive date + 1 week (DDMMYY)";
            $lines[] = "# zerofill(27)       // pos 54-80: filler            - numeric      - zero fill to 80 chars";
            $lines[] = '';
        }

        $num_transactions = str_pad($transaction_count, 8, 0, STR_PAD_LEFT);
        $num_records      = ($transaction_count * 2) + 2;  // 2 records per transaction + start and end assignment records
        $num_records      = str_pad($num_records, 8, 0, STR_PAD_LEFT);

        $assignment_total *= 100; // convert nok to øre
        $assignment_total  = str_pad($assignment_total, 17, 0, STR_PAD_LEFT); // zero pad to 17 positions

        $earliest_in_date  = date('dmy', strtotime(min($datelist)));
        $latest_in_date    = date('dmy', strtotime(max($datelist)));

        $lines[] = implode('', array(
            'NY',              // pos 1-2:   format code       - alphanumeric - always NY
            '21',              // pos 3-4:   service code      - numeric      - avtalegiro code always 21
            '00',              // pos 5-6:   assignment type   - numeric      - assignment type always 00
            '88',              // pos 7-8:   record type       - numeric      - record type always 88
            $num_transactions, // pos 9-16:  num transactions  - numeric      - number of transactions in the assignment, left zero filled to 8 digits
            $num_records,      // pos 17-24: number of records - numeric      - number of records, 2 per transaction + start and end record, left zero filled to 8 digits (??)
            $assignment_total, // pos 25-41: total amount      - numeric      - the sum of all transactions in the assignment, specified in øre
            $earliest_in_date, // pos 42-47: earliest due date - numeric      - the earliest due date for transactions in the assignment - using contribution receive date (DDMMYY)
            $latest_in_date,   // pos 48-53: latest due date   - numeric      - the latest due date for transactions in the assignment - using contribution receive date + 1 week (DDMMYY)
            $zerofill(27)      // pos 54-80: filler            - numeric      - zero fill to 80 chars
        ));

        if ($this->getContributions('Cancelled')) {
            $deletions = true;

            // 2.3.1 Start record for assignments with deletion requests
            if ($debug) {
                $lines[] = "\n";
                $lines[] = '# Start record for assignment with deletion requests';
                $lines[] = '';
                $lines[] = "# 'NY',           // pos 1-2:   format code        - alphanumeric - always NY";
                $lines[] = "# '21',           // pos 3-4:   service code       - numeric      - avtalegiro code always 21";
                $lines[] = "# '36',           // pos 5-6:   assignment type    - numeric      - assignment type for deletion always 36";
                $lines[] = "# '20',           // pos 7-8:   record type        - numeric      - record type always 20";
                $lines[] = "# zerofill(9),    // pos 9-17:  filler             - numeric      - zero fill to 17 characters";
                $lines[] = "# assignment_no,  // pos 18-24: assignment number  - numeric      - unique numbering of assignments per payee's recipient agreement";
                $lines[] = "# assignment_acc, // pos 25-35: assignment account - numeric      - payee's bank account";
                $lines[] = "# zerofill(45),   // pos 36-80: filler             - numeric      - zero fill to 80 characters";
                $lines[] = '';
            }

            $assignment_no   = str_pad(date('d', strtotime('+1 day')) . date('m', strtotime('+12 months')) . '17', '7', '0', STR_PAD_LEFT);
            $assignment_acc = '70586360610';

            $lines[] = implode('', array(
                'NY',            // pos 1-2:   format code        - alphanumeric - always NY
                '21',            // pos 3-4:   service code       - numeric      - avtalegiro code always 21
                '36',            // pos 5-6:   assignment type    - numeric      - assignment type for deletion always 36
                '20',            // pos 7-8:   record type        - numeric      - record type always 20
                $zerofill(9),    // pos 9-17:  filler             - numeric      - zero fill to 17 characters
                $assignment_no,  // pos 18-24: assignment number  - numeric      - unique numbering of assignments per payee's recipient agreement
                $assignment_acc, // pos 25-35: assignment account - numeric      - payee's bank account
                $zerofill(45),   // pos 36-80: filler             - numeric      - zero fill to 80 characters
            ));

            // Reset some variables
            $deldatelist    = array();
            $num_deletions  = 0;
            $deletion_total = 0;
            $transaction_no = 0;

            foreach ($this->getContributions('Cancelled') as $contribution) {
                ++$num_deletions;
                ++$transaction_no;

                $transaction_no = str_pad($transaction_no, 7, '0', STR_PAD_LEFT);

                // lookup kid number for this contribution
                $kid_number = kid_number_lookup('Contribution', $contribution['id']);

               /*
                * Transaction record
                * A valid OCR-transaction consists of both an amount posting 1 and an amount posting 2.
                * A deletion request is only valid if both postings are filled in.
                * This is identical to the contribution postings, but with a different transaction type.
                */

                // 2.3.3 Deletion posting 1
                if ($debug) {
                    $lines[] = "\n";
                    $lines[] = '# Amount posting 1 record - contribution id ' . $contribution['id'] . ' - kid no ' . $kid_number;
                    $lines[] = '';
                    $lines[] = "# 'NY',             // pos 1-2:   format code       - alphanumeric - always NY";
                    $lines[] = "# '21',             // pos 3-4:   service code      - numeric      - avtalegiro code always 21";
                    $lines[] = "# transaction_type, // pos 5-6:   transaction type  - numeric      - request for deletion, always 93";
                    $lines[] = "# '30',             // pos 7-8:   record type       - numeric      - record type for amount posting 1 always 30";
                    $lines[] = "# transaction_no,   // pos 9-15:  transaction no    - numeric      - a unique incrementing id per transaction";
                    $lines[] = "# date,             // pos 16-21: date              - numeric      - due date - no more than 12 months in advance; if not work day, following work day is used";
                    $lines[] = "# str_pad('', 11),  // pos 22-32: filler            - alphanumeric - filler - this field is not in use and must be cleared";
                    $lines[] = "# amount,           // pos 33-49: amount            - numeric      - amount in øre - using contribution total amount x 100, zero filled to 17 digits";
                    $lines[] = "# kid,              // pos 50-74: kid number        - alphanumeric - kid number, using 15 digit code stored against contribution, left whitespace filled to 25 chars";
                    $lines[] = "# zerofill(6)       // pos 75-80: filler            - numeric      - zero filled to 80 chars";
                    $lines[] = '';
                }

                $transaction_type = '93';
                $date             = date('dmy', strtotime($contribution['receive_date']));
                $amount           = str_pad((float)$contribution['total_amount'] * 100, 17, 0, STR_PAD_LEFT);
                $kid              = str_pad($kid_number, 25, ' ', STR_PAD_LEFT);

                $lines[] = implode('', array(
                    'NY',              // pos 1-2:   format code       - alphanumeric - always NY
                    '21',              // pos 3-4:   service code      - numeric      - avtalegiro code always 21
                    $transaction_type, // pos 5-6:   transaction type  - numeric      - request for deletion, always 93
                    '30',              // pos 7-8:   record type       - numeric      - record type for amount item 1 always 30
                    $transaction_no,   // pos 9-15:  transaction no    - numeric      - seems to be a unique incrementing id per transaction
                    $date,             // pos 16-21: date              - numeric      - due date - no more than 12 months in advance; if not work day, following work day is used
                    str_pad('', 11),   // pos 22-32: filler            - alphanumeric - filler - this field is not in use and must be cleared
                    $amount,           // pos 33-49: amount            - numeric      - amount in øre - using contribution total amount x 100, zero filled to 17 digits
                    $kid,              // pos 50-74: kid number        - alphanumeric - kid number, using 15 digit code stored against contribution, left whitespace filled to 25 chars
                    $zerofill(6)       // pos 75-80: filler            - numeric      - zero filled to 80 chars
                ));


                // 2.3.4 Deletion posting 2
                if ($debug) {
                    $lines[] = "\n";
                    $lines[] = '# Amount posting 2 record - contribution id ' . $contribution['id'] . ' - kid no ' . $kid_number;
                    $lines[] = '';
                    $lines[] = "# 'NY',             // pos 1-2:   format code        - alphanumeric - always NY";
                    $lines[] = "# '21',             // pos 3-4:   service code       - numeric      - avtalegiro code always 21";
                    $lines[] = "# transaction_type, // pos 5-6:   transaction type   - numeric      - always 93 for deletion records";
                    $lines[] = "# '31',             // pos 7-8:   record type        - numeric      - record type for amount item 2 always 31";
                    $lines[] = "# transaction_no,   // pos 9-15:  transaction no     - numeric      - transaction no from amount posting 1";
                    $lines[] = "# abbreviated_name, // pos 16-25: abbreviated name   - alphanumeric - abbreviated name for payer";
                    $lines[] = "# str_pad('', 25),  // pos 26-50: filler             - alphanumeric - filler - this field is not in use and must be cleared";
                    $lines[] = "# external_ref,     // pos 51-75: external reference - alphanumeric - must be transferred to to payer's statement and AvtaleGiro info";
                    $lines[] = "# zerofill(5)       // pos 76-80: filler             - numeric      - zero filled to 80 chars";
                    $lines[] = '';
                }

                $first_name = @iconv("UTF-8", "ASCII//IGNORE", mb_substr($contribution['first_name'], 0, 5));
                $last_name  = @iconv("UTF-8", "ASCII//IGNORE", mb_substr($contribution['last_name'], 0, 5));

                $transaction_type = '93';
                $abbreviated_name = str_pad($first_name . $last_name, 10); // First five letters of first name + first five letters of last name, 0 padded to 10
                /*
                 * BOS1403431 external_ref has to contain 'Medlemskap' if for membership
                 */
                if ($contribution['financial_type_id'] == $this->memberFinTypeId) {
                    $external_ref = str_pad('Medlemskap', 25);
                } else { 
                    $external_ref     = str_pad('MAF Norge', 25);  // Who are they giving money to?
                }

                $lines[] = implode('', array(
                    'NY',              // pos 1-2:   format code        - alphanumeric - always NY
                    '21',              // pos 3-4:   service code       - numeric      - avtalegiro code always 21
                    $transaction_type, // pos 5-6:   transaction type   - numeric      - valid values: 02 (no notification from bank) or 21 (notification from bank)
                    '31',              // pos 7-8:   record type        - numeric      - record type for amount item 2 always 31
                    $transaction_no,   // pos 9-15:  transaction no     - numeric      - transaction no from amount item 1
                    $abbreviated_name, // pos 16-25: abbreviated name   - alphanumeric - abbreviated name for payer
                    str_pad('', 25),   // pos 26-50: filler             - alphanumeric - filler - this field is not in use and must be cleared
                    $external_ref,     // pos 51-75: external reference - alphanumeric - must be transferred to payer's statement and AvtaleGiro info
                    $zerofill(5)       // pos 76-80: filler             - numeric      - zero filled to 80 chars
                ));

                // keep running total for assignment
                $deletion_total += (float)$contribution['total_amount'];

                // update the list of dates for earliest & latest transactions.
                $datelist[]    = $contribution['receive_date'];
                $deldatelist[] = $contribution['receive_date'];
            }

            // 2.3.5 End record for assignments with deletion requests
            if ($debug) {
                $lines[] = "\n";
                $lines[] = '# End record for assignment with deletion requests';
                $lines[] = '';
                $lines[] = "# 'NY',              // pos 1-2:   format code       - alphanumeric - always NY";
                $lines[] = "# '21',              // pos 3-4:   service code      - numeric      - avtalegiro code always 21";
                $lines[] = "# '36',              // pos 5-6:   assignment type   - numeric      - assignment type always 36";
                $lines[] = "# '88',              // pos 7-8:   record type       - numeric      - record type always 88";
                $lines[] = "# num_transactions,  // pos 9-16:  num transactions  - numeric      - number of transactions in the assignment, left zero filled to 8 digits";
                $lines[] = "# num_records,       // pos 17-24: number of records - numeric      - number of records, 2 per transaction + start and end record, left zero filled to 8 digits (??)";
                $lines[] = "# assignment_total,  // pos 25-41: total amount      - numeric      - the sum of all transactions in the assignment, specified in øre";
                $lines[] = "# earliest_date,     // pos 42-47: earliest due date - numeric      - the earliest due date for transactions in the assignment - using contribution receive date (DDMMYY)";
                $lines[] = "# latest_date,       // pos 48-53: latest due date   - numeric      - the latest due date for transactions in the assignment - using contribution receive date + 1 week (DDMMYY)";
                $lines[] = "# zerofill(27)       // pos 54-80: filler            - numeric      - zero fill to 80 chars";
                $lines[] = '';
            }

            $num_deletions = str_pad($num_deletions, 8, 0, STR_PAD_LEFT);
            $num_records   = ($num_deletions * 2) + 2;  // 2 records per transaction + start and end assignment records
            $num_records   = str_pad($num_records, 8, 0, STR_PAD_LEFT);

            $deletion_total *= 100; // convert nok to øre
            $deletion_total  = str_pad($deletion_total, 17, 0, STR_PAD_LEFT); // zero pad to 17 positions

            $earliest_del_date = date('dmy', strtotime(min($deldatelist)));
            $latest_del_date   = date('dmy', strtotime(max($deldatelist)));

            $lines[] = implode('', array(
                'NY',               // pos 1-2:   format code       - alphanumeric - always NY
                '21',               // pos 3-4:   service code      - numeric      - avtalegiro code always 21
                '36',               // pos 5-6:   assignment type   - numeric      - assignment type always 36
                '88',               // pos 7-8:   record type       - numeric      - record type always 88
                $num_deletions,     // pos 9-16:  num transactions  - numeric      - number of transactions in the assignment, left zero filled to 8 digits
                $num_records,       // pos 17-24: number of records - numeric      - number of records, 2 per transaction + start and end record, left zero filled to 8 digits (??)
                $deletion_total,    // pos 25-41: total amount      - numeric      - the sum of all transactions in the assignment, specified in øre
                $earliest_del_date, // pos 42-47: earliest due date - numeric      - the earliest due date for transactions in the assignment - using contribution receive date (DDMMYY)
                $latest_del_date,   // pos 48-53: latest due date   - numeric      - the latest due date for transactions in the assignment - using contribution receive date (DDMMYY)
                $zerofill(27)       // pos 54-80: filler            - numeric      - zero fill to 80 chars
            ));
        }


        // 2.4 End record for transmission
        if ($debug) {
            $lines[] = "\n";
            $lines[] = '# End record for transmission';
            $lines[] = '';
            $lines[] = "# 'NY',                // pos 1-2:   format code       - alphanumeric - always NY";
            $lines[] = "# '00',                // pos 3-4:   service code      - numeric      - always 00 for end transmission record";
            $lines[] = "# '00',                // pos 5-6:   assignment type   - numeric      - always 00 for end transmission record";
            $lines[] = "# '89',                // pos 7-8:   record type       - numeric      - record type end transmission = 89";
            $lines[] = "# num_transactions,    // pos 9-16:  num transactions  - numeric      - number of transactions in the assignment, left zero filled to 8 digits";
            $lines[] = "# num_records,         // pos 17-24: num records       - numeric      - number of records (lines) in the transmission - includes start/end transmission records";
            $lines[] = "# transmission_total,  // pos 25-41: total amount      - numeric      - total amount for transmission, zero filled left to 17 digits";
            $lines[] = "# transmission_date,   // pos 42-47: transmission date - numeric      - the date of the earliest transmission in the format (DDMMYY)";
            $lines[] = "# zerofill(33)         // pos 48-80: filler            - numeric      - zerofill to 80 chars";
            $lines[] = '';
        }

        $end_recs    = $deletions ? '6' : '4';
        $num_records = (($num_transactions + $num_deletions) * 2) + $end_recs;
        $num_records = str_pad($num_records, 8, 0, STR_PAD_LEFT);

        $num_transactions = $num_transactions + $num_deletions;
        $num_transactions = str_pad($num_transactions, 8, 0, STR_PAD_LEFT);

        $transmission_total = $assignment_total + $deletion_total;
        $transmission_total = str_pad($transmission_total, 17, 0, STR_PAD_LEFT); // Make sure it's 17 characters long

        $earliest_date      = date('dmy', strtotime(min($datelist)));
        $transmission_date  = $earliest_date; // will be the same as the assignment date

        $lines[] = implode('', array(
            'NY',                // pos 1-2:   format code       - alphanumeric - always NY
            '00',                // pos 3-4:   service code      - numeric      - always 00 for end transmission record
            '00',                // pos 5-6:   assignment type   - numeric      - always 00 for end transmission record
            '89',                // pos 7-8:   record type       - numeric      - record type end transmission = 89
            $num_transactions,   // pos 9-16:  num transactions  - numeric      - number of transactions in the assignment, left zero filled to 8 digits
            $num_records,        // pos 17-24: num records       - numeric      - number of records (lines) in the transmission - includes start/end transmission records
            $transmission_total, // pos 25-41: total amount      - numeric      - total amount for transmission, zero filled left to 17 digits
            $transmission_date,  // pos 42-47: transmission date - numeric      - the date of the earliest transmission in the format (DDMMYY)
            $zerofill(33)        // pos 48-80: filler            - numeric      - zerofill to 80 chars
        ));

        if ($inline) {

            $download_filename = date('M', strtotime($this->start_date)) . '-' .
                                 date('M', strtotime($this->end_date)) . '.ocr';

            if ($debug) {
                // output directly to browser in debug mode
                header("Content-Type: text/plain; charset=utf-8");
            } else {
                // otherwise output as file attachment
                header("Content-Type: application/force-download; charset=utf-8");
                header("Content-Disposition: attachment; filename=\"$download_filename\"");
            }

            echo implode("\n", $lines);
            CRM_Utils_System::civiExit();

        } else {
            file_put_contents($filename, implode("\n", $lines));
        }

    }

    // return pending contributions created for between $this->start_date and $this->end_date
    protected function getContributions($status = 'Pending') {

        $fields  = array();
        $results = array();
        
        // get all field names on civicrm_contribution - this allows us to extract
        // only the information we need from the dao following a SELECT * FROM,
        // omitting all the other crap it contains.
        $dao = CRM_Core_DAO::executeQuery("DESC civicrm_contribution");
        while ($dao->fetch())
            $fields[] = $dao->Field;

        // get the correct status_id for 'Pending'
        $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
        $status_id = $status_id[$status];

        // And then get the right payment_type_id for 'Avtale Giro'
        $payment_id = 2;

        // todo: limit to certain financial 
        $dao = CRM_Core_DAO::executeQuery("
            SELECT contact.first_name, contact.last_name, ccr.notification_for_bank, contribution.* FROM civicrm_contribution contribution
        INNER JOIN civicrm_contact contact ON contribution.contact_id = contact.id
        INNER JOIN civicrm_contribution_recur_offline ccr ON contribution.contribution_recur_id = ccr.recur_id
             WHERE contribution.receive_date BETWEEN %1 AND %2
               AND contribution.contribution_status_id = %3
               AND contribution.contribution_recur_id IS NOT NULL
               AND ccr.payment_type_id = %4
        ", array(
              1 => array($this->start_date, 'String'),
              2 => array($this->end_date, 'String'),
              3 => array($status_id, 'Positive'),
              4 => array($payment_id, 'Positive')
           )
        );

        while ($dao->fetch()) {

            $result = array();
            foreach ($fields as $field)
                $result[$field] = $dao->$field;

            $result['first_name']    = $dao->first_name;
            $result['last_name']     = $dao->last_name;
            $result['notification']  = $dao->notification_for_bank;

            $results[] = $result;

        }
        /*
         * BOS1403431 add pending contributions that are linked to a membership 
         * (financial_type = Medlem) and have a payment_instrument AvtaleGiro
         * and are in the selected period
         */
        $optionGroupParams = array(
            'name'      =>  "payment_instrument",
            'return'    =>  "id"
        );
        try {
            $optionGroupId = civicrm_api3('OptionGroup', 'Getvalue', $optionGroupParams);
        } catch (CiviCRM_API3_Exception $e) {
            CRM_Core_Error::fatal(ts("Could not find a valid option group for payment_instrument, 
            error from API entity OptionGroup, action Getvalue is : ".$e->getMessage()));
        }
        $paymentInstrumentParams = array(
            'option_group_id'   =>  $optionGroupId,
            'name'              =>  "AvtaleGiro",
            'return'            =>  "value"
        );
        try {
            $paymentInstrumentId = civicrm_api3('OptionValue', 'Getvalue', $paymentInstrumentParams);
        } catch (CiviCRM_API3_Exception $e) {
            CRM_Core_Error::fatal(ts("Could not find a valid option value for payment instrument AvtaleGiro, 
            error from API entity OptionValue, action Getvalue is : ".$e->getMessage()));
        }
  
        $memberQuery = "
            SELECT b.first_name, b.last_name, a.* FROM civicrm_contribution a
            INNER JOIN civicrm_contact b ON a.contact_id = b.id
            WHERE a.receive_date BETWEEN '{$this->start_date}' AND '{$this->end_date}'
            AND a.contribution_status_id = $status_id
            AND a.financial_type_id = {$this->memberFinTypeId}
            AND a.payment_instrument_id = $paymentInstrumentId";
        $daoMember = CRM_Core_DAO::executeQuery($memberQuery);
        while ($daoMember->fetch()) {
            $result = array();
            foreach ($fields as $field) {
                $result[$field] = $daoMember->$field;
            }
            $result['first_name'] = $daoMember->first_name;
            $result['last_name'] = $daoMember->last_name;
            $result['notification'] = 0;
            $results[] = $result;
        }
        // end BOS1403431
        return $results;
    }

};
