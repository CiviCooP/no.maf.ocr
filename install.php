<?php

/* 
 * OCR Import/Export Extension for CiviCRM - Circle Interactive 2013
 * Author: andyw@circle
 *
 * Distributed under the GNU Affero General Public License, version 3
 * http://www.gnu.org/licenses/agpl-3.0.html 
 */

/* Installation tasks file */

// cannot be run directly ..
if (!defined('__OCR'))
	die;

// if fields already exist, exit containing function (hook_civicrm_install)
if (CRM_Core_BAO_Setting::getItem('no.maf.ocr', 'custom_fields'))
	return;

require_once 'api/api.php';
$fields = array();

// create custom group
$result = civicrm_api('custom_group', 'create', array(
	'version'          => 3,
    'title'            => ts('Nets Transactions'),
    'name'             => 'nets_transactions',
	'extends'          => array('Contribution'),
    'weight'           => 10,
    'collapse_display' => 1,
    'style'            => 'Inline',
    'is_active'        => 1
));
if ($result['is_error']) {
	ocr_set_message(ts(
		'Unable to create custom group: %1',
		array(
			1 => $result['error_message']
		)
	));
	return;
} else { 
	$group = reset($result['values']);
	$group_id = $group['id'];
}

// create custom text fields on Contribution
$weight = 1;
foreach (array(
	'transmission_number' => ts('Transmission No'),
	'transaction_number'  => ts('Transaction No'),
	'kid_number'          => ts('KID Number'),
	'debit_account'       => ts('Debit Account'),
	'aksjon_id'           => ts('Aksjon ID'),
	'balans_konto'        => ts('Balans Konto')
) as $name => $label) {
	
	$result = civicrm_api('custom_field', 'create', array(
		'version'         => 3,
		'custom_group_id' => $group_id,
		'name'            => $name,
		'label'           => $label,
		'html_type'       => 'Text',
		'data_type'       => 'String',
		'default_value'   => '',
		'weight'          => $weight++,
		'is_required'     => 0,
		'is_searchable'   => 1,
		'is_active'       => 1,
	));
	if ($result['is_error']) {
		ocr_set_message(ts(
			"Unable to create custom field '%1': %2",
			array(
				1 => $label,
				2 => $result['error_message']
			)
		));
	} else {
		$field         = reset($result['values']);
		$fields[$name] = $field['id'];
	}

}

// Create a custom checkbox for sent_to_bank
$result = civicrm_api('custom_field', 'create', array(
	'version'         => 3,
	'custom_group_id' => $group_id,
	'name'            => 'sent_to_bank',
	'label'           => 'Sent to bank',
	'html_type'       => 'Radio',
	'data_type'       => 'Boolean',
	'default_value'   => 0,
	'weight'          => $weight++,
	'is_required'     => 0,
	'is_searchable'   => 1,
	'is_active'       => 1,
));

if ($result['is_error']) {
	ocr_set_message(ts(
		"Unable to create custom field '%1': %2",
		array(
			1 => $label,
			2 => $result['error_message']
		)
	));
} else {
	$field                  = reset($result['values']);
	$fields['sent_to_bank'] = $field['id'];
}

// Create a custom date field for bank_date
/*
$result = civicrm_api('custom_field', 'create', array(
	'version'         => 3,
	'custom_group_id' => $group_id,
	'name'            => 'bank_date',
	'label'           => ts('Bank Date'),
	'html_type'       => 'Select Date',
	'data_type'       => 'Date',
	'date_format'     => 'dd/mm/yyyy',
	'time_format'     => 0,
	'default_value'   => '',
	'weight'          => $weight++,
	'is_required'     => 0,
	'is_searchable'   => 1,
	'is_active'       => 1,
));
if ($result['is_error']) {
	ocr_set_message(ts(
		"Unable to create custom field 'Bank Date': %1",
		array(
			1 => $result['error_message']
		)
	));
} else {
	$field               = reset($result['values']);
	$fields['bank_date'] = $field['id'];
}
*/

CRM_Core_BAO_Setting::setItem(array($group_id => $fields), 'no.maf.ocr', 'custom_fields');


