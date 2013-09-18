<?php

/**
 * Processes admin-settings page
 *
 * @author		Jon LaBass
 * @version		$Id:$
 * @copyright	2013
 *
 */

include(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_tools.php');
include(ABSPATH . 'fm-modules/' . $_SESSION['module'] . '/classes/class_zones.php');
$available_zones = $fm_dns_zones->availableZones();
$zone_options = $available_zones ? buildSelect('domain_id', 1, $available_zones) : 'You need to define one or more zones first.';

$tools_option[] = <<<HTML
			<h2>Connection Tests</h2>
			<p>Test the connectivity of your DNS servers with the $fm_name server.</p>
			<p class="step"><input id="connect-test" name="submit" type="submit" value="Run Tests" class="button" $disabled /></p>
			<br />
HTML;

$disabled = (($_SESSION['user']['fm_perms'] & PERM_FM_RUN_TOOLS) && ($_SESSION['user']['module_perms']['perm_value'] & PERM_DNS_RECORD_MANAGEMENT) || ($_SESSION['user']['fm_perms'] & PERM_FM_SUPER_ADMIN)) ? null : 'disabled';
$tools_option[] = <<<HTML
			<h2>Import Zone Files</h2>
			<p>Import records from BIND-compatible zone files.</p>
			<table class="form-table">
				<tr>
					<th>File to import:</th>
					<td><input id="import-file" name="import-file" type="file" $disabled /></td>
				</tr>
				<tr>
					<th>Zone to import to:</th>
					<td>
						$zone_options
					</td>
			</table>
			<p class="step"><input id="import-records" name="submit" type="submit" value="Import Records" class="button" $disabled /></p>
			<br />
HTML;

if (array_key_exists('submit', $_POST)) {
	switch($_POST['submit']) {
		case 'Import Records':
			if (!empty($_FILES['import-file']['tmp_name'])) {
				$block_style = 'style="display: block;"';
				$output = $fm_dns_tools->zoneImportWizard();
			}
			break;
	}
}

?>