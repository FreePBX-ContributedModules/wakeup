<?php

function wakeup_get_config($engine) {
	$modulename = 'wakeup';
	
	// This generates the dialplan
	global $ext;  
	switch($engine) {
		case "asterisk":
			if (is_array($featurelist = featurecodes_getModuleFeatures($modulename))) {
				foreach($featurelist as $item) {
					$featurename = $item['featurename'];
					$fname = $modulename.'_'.$featurename;
					if (function_exists($fname)) {
						$fcc = new featurecode($modulename, $featurename);
						$fc = $fcc->getCodeActive();
						unset($fcc);
						
						if ($fc != '')
							$fname($fc);
					} else {
						$ext->add('from-internal-additional', 'debug', '', new ext_noop($modulename.": No func $fname"));
						var_dump($item);
					}	
				}
			}
		break;
	}
}

function wakeup_wakeup($c) {
	global $ext;

	$id = "app-wakeup"; // The context to be included

	$ext->addInclude('from-internal-additional', $id); // Add the include from from-internal

   $ext->add($id, $c, '', new ext_macro('user-callerid'));
	$ext->add($id, $c, '', new ext_answer('')); // $cmd,1,Answer
	$ext->add($id, $c, '', new ext_wait('1'));
	$ext->add($id, $c, '', new ext_agi('wakeup.php')); 
	$ext->add($id, $c, '', new ext_macro('hangupcall')); 
}

?>
