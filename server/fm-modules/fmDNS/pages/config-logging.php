<?php

/**
 * Processes options config page
 *
 * @author		Jon LaBass
 * @version		$Id:$
 * @copyright	2013
 *
 */

$page_name = 'Config';
$page_name_sub = 'Logging';

include(ABSPATH . 'fm-modules/fmDNS/classes/class_logging.php');

$type = (isset($_GET['type'])) ? sanitize(strtolower($_GET['type'])) : 'channel';
$display_type = ucfirst($__FM_CONFIG['logging']['avail_types'][$type]);
$channel_category = ($type == 'channel') ? 'channel' : 'category';
$server_serial_no = (isset($_REQUEST['server_serial_no'])) ? sanitize($_REQUEST['server_serial_no']) : 0;

/* Ensure proper type is defined */
if (!array_key_exists($type, $__FM_CONFIG['logging']['avail_types'])) {
	header('Location: ' . $GLOBALS['basename']);
}

if ($allowed_to_manage_servers) {
	$action = (isset($_REQUEST['action'])) ? $_REQUEST['action'] : 'add';
	$server_serial_no_uri = (array_key_exists('server_serial_no', $_REQUEST) && $server_serial_no) ? '&server_serial_no=' . $server_serial_no : null;
	switch ($action) {
	case 'add':
		if (!empty($_POST)) {
			if ($_POST['sub_type'] == 'channel') {
				$result = $fm_dns_logging->addChannel($_POST);
				if ($result !== true) {
					$response = $result;
					$form_data = $_POST;
				} else {
					setBuildUpdateConfigFlag($server_serial_no, 'yes', 'build');
					header('Location: ' . $GLOBALS['basename'] . '?type=' . $type . $server_serial_no_uri);
				}
			} elseif ($_POST['sub_type'] == 'category') {
				$result = $fm_dns_logging->addCategory($_POST);
				if ($result !== true) {
					$response = $result;
					$form_data = $_POST;
				} else {
					setBuildUpdateConfigFlag($server_serial_no, 'yes', 'build');
					header('Location: ' . $GLOBALS['basename'] . '?type=' . $type . $server_serial_no_uri);
				}
			}
		}
		break;
	case 'delete':
		if (isset($_GET['id'])) {
			/** Check if channel is associated first */
			if ($type == 'channel' && is_array($fm_dns_logging->getAssocCategories(sanitize($_GET['id'])))) {
				$response = 'This ' . $type . ' is associated with a category and cannot be deleted.';
				$action = 'add';
			} else {
				$delete_status = $fm_dns_logging->delete(sanitize($_GET['id']), $server_serial_no, $type);
				if ($delete_status !== true) {
					$response = $delete_status;
				} else header('Location: ' . $GLOBALS['basename'] . '?type=' . $type . $server_serial_no_uri);
			}
		}
		break;
	case 'edit':
		if (!empty($_POST)) {
			$result = $fm_dns_logging->update($_POST);
			if ($result !== true) {
				$response = $result;
				$form_data = $_POST;
			} else {
				setBuildUpdateConfigFlag($server_serial_no, 'yes', 'build');
				header('Location: ' . $GLOBALS['basename'] . '?type=' . $_POST['sub_type'] . $server_serial_no_uri);
			}
		}
		if (isset($_GET['status'])) {
			if (!updateStatus('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'config', $_GET['id'], 'cfg_', $_GET['status'], 'cfg_id')) {
				$response = 'This ' . $type . ' could not be ' . $_GET['status'] . '.';
			} else {
				setBuildUpdateConfigFlag($server_serial_no, 'yes', 'build');
				header('Location: ' . $GLOBALS['basename'] . '?type=' . $type . $server_serial_no_uri);
			}
		}
	}
} $server_serial_no_uri = null;

printHeader();
@printMenu($page_name, $page_name_sub);

$avail_types = buildSubMenu($type, $server_serial_no_uri);
$avail_servers = buildServerSubMenu($server_serial_no, 'log_space');

if (!empty($response)) echo '<div id="response"><p class="error">' . $response . "</p></div>\n";
echo '<div id="response" style="display: none;"></div>' . "\n";
echo '<div id="body_container"';
if (!empty($response)) echo ' style="margin-top: 4em;"';
echo ">
	<h2>Logging $display_type";

if ($allowed_to_manage_servers) {
	echo '<a id="plus" name="' . $type . '" href="#" title="Add New">' . $__FM_CONFIG['icons']['add'] . '</a>';
}

echo '</h2>' . "\n$avail_types\n$avail_servers\n";
	
$result = basicGetList('fm_' . $__FM_CONFIG['fmDNS']['prefix'] . 'config', 'cfg_name', 'cfg_', 'AND cfg_type="logging" AND cfg_name="' . $channel_category . '" AND server_serial_no=' . $server_serial_no);
$fm_dns_logging->rows($result, $channel_category);

printFooter();


function buildSubMenu($option_type = 'channel', $server_serial_no_uri = null) {
	global $__FM_CONFIG;
	
	$menu_selects = null;
	
	foreach ($__FM_CONFIG['logging']['avail_types'] as $general => $type) {
		$select = ($option_type == $general) ? ' class="selected"' : '';
		$menu_selects .= "<span$select><a$select href=\"config-logging?type=$general$server_serial_no_uri\">" . ucfirst($type) . "</a></span>\n";
	}
	
	return '<div id="configtypesmenu">' . $menu_selects . '</div>';
}

?>