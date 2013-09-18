<?php

/**
 * fmDNS Functions
 *
 * @package fmDNS
 * @subpackage Client
 *
 */


/**
 * Prints the module help file
 *
 * @since 1.0
 * @package facileManager
 *
 * @return null
 */
function printModuleHelp () {
	global $argv;
	
	echo <<<HELP
  -n|dryrun      Do not save - just output what will happen
  -b|buildconf   Build named config and zone files
  -z|zones       Build all associated zone files
  -c|cron        Run in cron mode
     id=XX       Specify the individual DomainID to build and reload
  
HELP;
}


function installFMModule($module_name, $proto, $compress, $data, $server_location, $url) {
	global $argv;
	
	extract($server_location);

	echo "  --> Running version tests...";
	$daemon = detectDaemonVersion(true);
	if ($daemon === null) {
		echo "failed\n\n";
		echo "Cannot find a supported DNS server - please check the INSTALL document for supported DNS servers.  Aborting.\n";
		exit(1);
	}
	extract($daemon);
	$data['server_type'] = $server['type'];
	if (versionCheck($daemon_version, $proto . '://' . $hostname . '/' . $path, $compress) == true) {
		echo "ok\n";
	} else {
		echo "failed\n\n";
		echo "$daemon_version is not supported.\n";
		exit(1);
	}
	$data['server_version'] = $daemon_version;
	
	echo "\n  --> Tests complete.  Continuing installation.\n\n";
	
	/** Update via cron or http/s? */
	$update_choices = array('c', 'h');
	while (!isset($update_method)) {
		echo 'Will ' . $data['server_name'] . ' get updates via cron or http(s) [c|h]? ';
		$update_method = trim(strtolower(fgets(STDIN)));
		
		/** Must be a valid option */
		if (!in_array($update_method, $update_choices)) unset($update_method);
	}
	
	/** Handle the update method */
	switch($update_method) {
		/** cron */
		case 'c':
			$tmpfile = '/tmp/crontab.facileManager';
			$dump = shell_exec('crontab -l -u root | grep -v ' . $argv[0] . '> ' . $tmpfile . ' 2>/dev/null');
			
			$cmd = "echo '*/5 * * * * " . findProgram('php') . ' ' . $argv[0] . " cron' >> $tmpfile && " . findProgram('crontab') . " -u root $tmpfile";
			$cron_update = system($cmd, $retval);
			unlink($tmpfile);
			
			if ($retval) echo "  --> The crontab cannot be created.\n  --> $cmd\n";
			else echo "  --> The crontab has been created.\n";
			
			$data['server_update_method'] = 'cron';

			break;
		/** http(s) */
		case 'h':
			/** Detect which web server is running */
			$web_server = detectHttpd();
			if (!is_array($web_server)) {
				echo "\nCannot find a supported web server - please check the INSTALL document for supported web servers.  Aborting.\n";
				exit(1);
			}
			
			/** Add a symlink to the docroot */
			$httpdconf = findFile($web_server['file']);
			if (!$httpdconf) {
				echo "\nCannot find " . $web_server['file'] . '.  Please enter the full path of ' . $web_server['file'] . ' (/etc/httpd/conf/httpd.conf): ';
				$httpdconf = trim(strtolower(fgets(STDIN)));
				
				/** Check if the file exists */
				if (!is_file($httpdconf)) {
					echo "  --> $httpdconf does not exist.  Aborting.\n";
					exit(1);
				}
			}
			$raw_root = explode('"', shell_exec('grep ^DocumentRoot ' . $httpdconf));
			/** Get the docroot from STDIN if it's not found */
			if (count($raw_root) <= 1) {
				echo "\nCannot find DocumentRoot in " . $web_server['file'] . ".  Please enter the full path of your\n default DocumentRoot (/var/www/html): ";
				$docroot = rtrim(trim(strtolower(fgets(STDIN))), '/');
			} else $docroot = trim($raw_root[1]);
				
			/** Check if the docroot exists */
			if (!is_dir($docroot)) {
				echo "  --> $docroot does not exist.  Aborting.\n";
				exit(1);
			}
			$link_name = "$docroot/fmDNS";
			
			echo "  --> Creating $link_name link.\n";
			
			if (!is_link($link_name)) {
				symlink(dirname(__FILE__) . '/www', $link_name);
			} else echo "      --> $link_name already exists...skipping\n";
			
			/** Add an entry to sudoers */
			$sudoers = findFile('sudoers');
			$raw_user = explode(' ', shell_exec('grep ^User ' . $httpdconf));
			$user = trim($raw_user[1]);
			if ($user[0] == '$') {
				$user_var = preg_replace(array('/\$/', '/{/', '/}/'), '', $user);
				$raw_user = explode('=', shell_exec('grep ' . $user_var . ' ' . findFile('envvars')));
				if (count($raw_user)) {
					$user = trim($raw_user[1]);
				}
			}
			$sudoers_line = "$user\tALL=(root)\tNOPASSWD: " . findProgram('php') . ' ' . $argv[0] . ' *';
			
			echo '  --> Detected ' . $web_server['daemon'] . " runs as '$user'\n";
			
			if (!$sudoers) {
				echo "  --> It does not appear sudo is installed.  Please install it and add the following to the sudoers file:\n";
				echo "\n      $sudoers_line\n";
				
				echo "\nInstallation aborted.\n";
				exit(1);
			} else {
				$cmd = "echo '$sudoers_line' >> $sudoers 2>/dev/null";
				if (strpos(file_get_contents($sudoers), $sudoers_line) === false) {
					$sudoers_update = system($cmd, $retval);
				
					if ($retval) echo "  --> The sudoers entry cannot be added.\n$cmd\n";
					else echo "  --> The sudoers entry has been added.\n";
				} else echo "  --> The sudoers entry already exists...skipping\n";
				
				/** Check for bad settings and disable */
				$bad_settings = array('requiretty', 'env_reset');
				foreach ($bad_settings as $setting) {
					$found_bad = shell_exec("grep $setting $sudoers | grep -cv '^#'");
					if ($found_bad != 0) {
						echo "  --> Disabling 'Defaults $setting' in $sudoers...\n";
						shell_exec("sed -i 's/.*$setting/#&/' $sudoers");
					}
				}
			}

			$data['server_update_method'] = 'http';

			break;
	}

	$raw_data = getPostData(str_replace('genserial', 'addserial', $url), $data);
	$raw_data = $data['compress'] ? @unserialize(gzuncompress($raw_data)) : @unserialize($raw_data);
	
	return $data;
}


function buildConf($url, $data) {
	global $proto, $debug;
	
	if ($data['dryrun'] && $debug) echo "Dryrun mode (nothing will be written to disk).\n\n";
	
	$raw_data = getPostData($url, $data);
	$raw_data = $data['compress'] ? @unserialize(gzuncompress($raw_data)) : @unserialize($raw_data);
	if (!is_array($raw_data)) {
		if ($debug) echo $raw_data;
		exit(1);
	}
	if ($debug) {
		foreach ($raw_data['files'] as $filename => $contents) {
			echo str_repeat('=', 50) . "\n";
			echo $filename . ":\n";
			echo str_repeat('=', 50) . "\n";
			echo $contents . "\n\n";
		}
	}
	
	extract($raw_data, EXTR_SKIP);
	
	$runas = ($server_run_as_predefined == 'as defined:') ? $server_run_as : $server_run_as_predefined;
		
	if ($debug) echo "Setting directory and file permissions for $runas.\n";
	if (!$data['dryrun']) {
		/** chown the files/dirs */
		$chown_files = array($server_root_dir, $server_zones_dir);
		foreach($chown_files as $file) {
			@chown($file, $runas);
		}
	}
		
		/** Remove previous files so there are no stale files */
//		foreach (scandir($server_zones_dir) as $item) {
//			if ($item == '.' || $item == '..') continue;
//			unlink($server_zones_dir . DIRECTORY_SEPARATOR . $item);
//		}
		
	/** Process the files */
	if (count($files)) {
		foreach($files as $filename => $contents) {
			if ($debug) echo "Writing $filename.\n";
			if (!$data['dryrun']) {
				@mkdir(dirname($filename), 0755, true);
				@chown(dirname($filename), $runas);
				file_put_contents($filename, $contents);
				@chown($filename, $runas);
			}
		}
	} else {
		echo "There are no files to save. Aborting.\n";
		exit(1);
	}
	
	/** Reload the server */
	if ($debug) echo "Reloading the server.\n";
	if (!$data['dryrun']) {
		if (shell_exec('ps -A | grep named | grep -vc grep') > 0) {
			system(findProgram('rndc') . ' reload 2>&1 > /dev/null', $retval);
		} else {
			if ($debug) echo "The server is not running. Attempting to start it.\n";
			$named_rc_script = getStartupScript();
			if ($named_rc_script === false) {
				if ($debug) echo "Cannot locate the start script.\n";
				$retval = true;
			} else {
				system($named_rc_script . ' 2>&1', $retval);
			}
		}
		if ($retval) {
			if ($debug) echo "There was an error reloading the server.  Please check the logs for details.\n";
			return false;
		} else {
			/** Only update reloaded zones */
			$data['built_domain_ids'] = $built_domain_ids;
			if (!isset($server_build_all)) {
				$data['zone'] = 'update';
			}
			
			/** Update the server with a successful reload */
			$data['action'] = 'update';
			$raw_update = getPostData($url, $data);
			$raw_update = $data['compress'] ? @unserialize(gzuncompress($raw_update)) : @unserialize($raw_update);
		}
	}
	return true;
}


function findFile($file) {
	$path = array('/etc/httpd/conf', '/usr/local/etc/apache', '/usr/local/etc/apache2', '/usr/local/etc/apache22',
				'/etc', '/usr/local/etc', '/etc/apache2', '/etc', '/etc/named', '/etc/namedb', '/etc/bind'
				);

	while ($this_path = current($path)) {
		if (is_file("$this_path/$file")) {
			return "$this_path/$file";
		}
		next($path);
	}

	return false;
}


function detectHttpd() {
	$httpd_choices = array('httpd'=>'httpd.conf', 'lighttpd'=>'', 'apache2'=>'apache2.conf');
	
	foreach ($httpd_choices as $daemon => $file) {
		if (findProgram($daemon)) return array('daemon'=>$daemon, 'file'=>$file);
	}
	
	return false;
}


function detectServerType() {
	$supported_servers = array('bind9'=>'named');
	
	foreach($supported_servers as $type => $daemon) {
		if (findProgram($daemon)) return array('type'=>$type, 'daemon'=>$daemon);
	}
	
	return null;
}


function moduleAddServer($url, $data) {
	/** Attempt to determine default variables */
	$named_conf = findFile('named.conf');
	if ($named_conf) {
		if ($run_as = posix_getgrgid(filegroup($named_conf))) {
			$data['server_run_as_predefined'] = $run_as['name'];
		}
		$data['server_config_file'] = $named_conf;
		$raw_root = explode('"', shell_exec('grep directory ' . $named_conf));
		
		if (count($raw_root) <= 1) {
			if (file_exists($named_conf . '.options')) {
				$raw_root = explode('"', shell_exec('grep directory ' . $named_conf . '.options'));
			}
		}
		$data['server_root_dir'] = @trim($raw_root[1]);
		
		$data['server_zones_dir'] = (dirname($named_conf) == '/etc') ? null : dirname($named_conf) . '/zones';
	} else $data['server_run_as_predefined'] = 'named';
	
	/** Add the server to the account */
	$servertype = detectServerType();
	$data['server_type'] = is_array($servertype) ? $servertype['type'] : $servertype;
	$raw_data = getPostData(str_replace('genserial', 'addserial', $url), $data);
	$raw_data = $data['compress'] ? @unserialize(gzuncompress($raw_data)) : @unserialize($raw_data);
	if (!is_array($raw_data)) {
		if (!$raw_data) echo "An error occurred.\n";
		else echo $raw_data;
		exit(1);
	}
	
	return array('data' => $data, 'add_result' => "Success\n");
}


function detectDaemonVersion($return_array = false) {
	$dns_server = detectServerType();
	$dns_flags = array('named'=>'-v | sed "s/BIND //"');
	
	if ($dns_server) {
		$version = shell_exec(findProgram($dns_server['daemon']) . ' ' . $dns_flags[$dns_server['daemon']]);
		if ($return_array) {
			return array('server' => $dns_server, 'daemon_version' => $version);
		} else return trim($version);
	}
	
	return null;
}


function versionCheck($daemon_version, $serverhost, $compress) {
	$url = $serverhost . '/buildconf';
	$data['action'] = 'version_check';
	$server_type = detectServerType();
	$data['server_type'] = $server_type['type'];
	$data['server_version'] = $daemon_version;
	$data['compress'] = $compress;
	
	$raw_data = getPostData($url, $data);
	$raw_data = $compress ? @unserialize(gzuncompress($raw_data)) : @unserialize($raw_data);
	
	return $raw_data;
}


function getStartupScript() {
	$distros = array(
		'Arch'      => '/etc/rc.d/named start',
		'Debian'    => '/etc/init.d/bind9 start',
		'Fedora'    => '/etc/init.d/named start',
		'Ubuntu'    => '/etc/init.d/bind9 start',
		'Redhat'    => '/etc/init.d/named start',
		'CentOS'    => '/etc/init.d/named start',
		'Slackware' => '/etc/rc.d/rc.bind start',
		'FreeBSD'   => '/etc/rc.d/named start',
		'Darwin'    => 'launchctl start org.isc.named'
		);
	
	$os = detectOSDistro();
	
	if (array_key_exists($os, $distros)) {
		return $distros[$os];
	}
	
	return false;
}


?>