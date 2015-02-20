<?php


/*

loads a csv file and decodes special encoding used in some of the fields

Syntax: php decodeCsv.php in.csv out.csv (optional)

the input csv file is a database extract 
the keys (column numbers) are used to identify the fields and the required processing
by default the expected format of the csv is from a typical "select all" query which is the order of the keys, but this can be altered below

optional parameters: (can be passed in other than the input and output filenames)
[-promo]            Used for filtering Promo names, usage is -promo followed by equals and comma delimited list of names e.g promo1,promo2 (other filtering should be queried via the db) by default all promos are included
[-dupl]             Used for filtering Duplications, usage is -dupl followed by equals and the word on or off, by default duplications are included
[-prof]             Used for removing profainity, usage is -prof followed by equals and the name of a file containing profainity entries, one entry per line, a match removes this from the list
[-seg]              Used for defining the size of the segments, usage is -seg followed by equals and the size of the segments, by default it is 1000
[-fields]	    Used for filtering field names, usage is -fields followed by equals and a coma delimited list of names e.g submissionTime,submissionID,sltClub,txtFirstName,txtSurname by default all fields are included           
[-positives]        Used for creating a csv containing the positives that were excluded
[-dryrun]           Used for displaying the intention without processing, usage is -dryrun followed by equals and on or off
*/


date_default_timezone_set('GMT');
ini_set('memory_limit','6020M');

if(!defined('STDIN')){
	die('CMD line only!');
}



////////////////////
// enviroment defaults
$maxExec        = ini_get("max_execution_time");
$mem            = ini_get("memory_limit");
$safe_mode      = ini_get("safe_mode") ? 'SAFE MODE' : '';
$lineWidth  	= 128; // display width before new line

// keys are fields locked onto during the csv upload
$keys = array(
        0=>'submissionID',
        1=>'submissionTime',
        2=>'submissionIP',
        3=>'formName',
        5=>'dupID',
        6=>'utmCampaign',
        7=>'utmSource',
        8=>'utmMedium'
); // 4 is missing as it is a special feed and handled in the switch


//////////////////////////
// enviroment arguments (change defaults)
$args = array();
foreach($argv as $argKey=>$argVal) {
	if (substr($argVal,0,1)==='-'){
		list($key,$val) = explode('=',$argVal);
		$args[trim($key,'-')] = $val;
	} elseif ($argKey!==0) {
		$args[] = $argVal;
	}
}
////////////////////
// functions
function message($msg,$type=false){

	global $lineWidth;

	switch ($type){

		case 'title':
			print "\n\n".str_pad('',$lineWidth,'-')."\n";
			print "- ".str_pad($msg,$lineWidth-4,' ',STR_PAD_BOTH)." -\n";
			print str_pad('',$lineWidth,'-')."\n\n";
		break;
	
		case 'error':
			print 'ERROR: '.$msg."\n\n";
			die;
		
		case 'hold':
			print "$msg";
			break;
		case 'line':
			print "\n\n".str_pad('',$lineWidth,'-')."\n"; 
			break;
		default:
			print "$msg\n";

	}

}

                        	

////////////////////////////////////////////////////////////
// main loop

// assignment
$in     		= $args[0];
$out    		= $args[1];
$includes               = isset($args['fields']) ? explode(',',$args['fields']) : array();
$promoCodes	 	= isset($args['promo']) ? explode(',',$args['promo']) : array(); 
$sizeOfSegment 		= isset($args['seg']) ? intval($args['seg']) : 1000;
$duplications		= isset($args['dupl']) && $args['dupl']=='off' ? false : true; 
$profanity		= isset($args['prof']) ? $args['prof'] : false;
$positivesOut		= isset($args['positives']) ? $args['positives'] : false;
$dryRun			= isset($args['dryrun']) && $args['dryrun']=='on' ? true : false;

///////////////////////////////
// checks
if ($in=='help' || $in=='man' || $in=='' || $out==''){
	message('');
        message('Syntax is php '.$_SERVER['SCRIPT_NAME'].' in.csv out.csv [OPTIONAL]');
	message('[-promo]    Used for filtering Promo names, usage is -promo followed by equals and a comma delimited list of names e.g promo1,promo2 (other filtering should be queried via the db) by default all promos are included');
	message('[-dupl]     Used for filtering Duplications, usage is -dupl followed by equals and the word on or off, by default duplications are included');
	message('[-prof]     Used for removing profanity, usage is -prof followed by equals and the name of a file containing profanity entries, one entry per line, a match removes this from the list');
	message('[-seg]      Used for defining the size of the segments, usage is -seg followed by equals and the size of the segments, by default it is 1000');
	message('[-fields]   Used for filtering field names, usage is -fields followed by equals and a coma delimited list of names e.g submissionTime,submissionID,sltClub,txtFirstName,txtSurname by default all fields are included');
	message('[-postives] Used for collecting a list of all excluded entries, usage is -positives followed by equals and a filename to output the results to');
	message('[-dryrun]   Used for displaying the intention without processing, , usage is -dryrun followed by equals and the word on or off, by default dry run is off');
	message('','line');
	die();
}

if (!file_exists($in)){
        message("Input file '$in' not found",'error');
}

if (file_exists($out)){
        message("Output file '$out' already exists",'error');
}

if ($profanity && !file_exists($profanity)){
        message("Profanity file '$profanity' not found",'error');
}

if (!$profanity){
	$profanity = array();
} else {
	$profanity = file($profanity, FILE_IGNORE_NEW_LINES);
}
/////////////////////////////

// env display
message("CSV decode $mem available $safeMode",'title');
message("CSV KEYS: ".implode(',',$keys));
message("Fields: ".(empty($includes) ? 'ALL' : implode(',',$includes)));
message("Promos: ".(empty($promoCodes) ? 'ALL' : implode(',',$promoCodes)));
message("Fields: ".(empty($includes) ? 'ALL' : implode(',',$includes)));
message("Segment: ".$sizeOfSegment);
message("Exclude duplicates: ".($duplications ? 'NO' : 'YES'));
message("Profanity: ".(empty($profanity) ? 'Allowed' : 'Exclude ('.count($profanity).' phrases)'));
if ($positivesOut){
	message("Positives output: $positivesOut");
}
message('','line');

if ($dryRun){
	message("!! Halted - Dry Run !!",'error');
} 


message("\n\nReading input csv $in");

$data = array();
$lineNo = 0;	
$records = 0;
$positives = array();
$file = fopen($in, 'r');
while (($line = fgetcsv($file)) !== FALSE) {
	
	$records++; // add the record
	$feedbackChar = '+'; // character used for feedback

	foreach ($line AS $fieldNo=>$fieldData){

		// format
		switch($fieldNo){

			case 1:
				@$data[$lineNo]['submissionTime'] = date("d/m/Y G:i:s", $fieldData);
				break;
			case 4:

				if (count($formDataEntries = explode('[formdata:',$fieldData))>0){
                                       	foreach ($formDataEntries  AS $formDataEntry){
                                               	if (!$formDataEntry=='' && count($formDataPair = explode(']',$formDataEntry))==2){

                                                       	// un-encoded key and value pair
                                               	        $formDataKey = $formDataPair[0];
                                       	                $formDataVal = $formDataPair[1];

                               	                        // store the key
                       	                                if (!isset($keys[$formDataKey])){
               	                                                $keys[$formDataKey] = $formDataKey;
       	                                                }
	
                               	                        // store the value in data
                       	                                $data[$lineNo][$formDataKey] = $formDataVal;
               	                                }

       	                                }
        

                                }


				break;
 
			default:
				if (isset($keys[$fieldNo])){
					$data[$lineNo][$keys[$fieldNo]] = $fieldData;
				} else {
					$data[$lineNo]['unknown '.$fieldNo] = $fieldData;
				}
                               break;

		}


			
        }

	///////////////////////////////////////////////////
	// filters
	$filtered = false;
	


	// promo filter
	if (!empty($promoCodes) && !in_array(trim(isset($data[$lineNo]['hdnPromo']) ? $data[$lineNo]['hdnPromo'] : null),$promoCodes)){
		$filtered = true;
		$feedbackChar = 'C';
		$data[$lineNo]['exclusion'] = "Promocode";
	}

	// duplication filter
	if (!$duplications && isset($data[$lineNo]['dupID']) && $data[$lineNo]['dupID']!=='0'){
		$filtered = true;
		$feedbackChar = 'D';
		$data[$lineNo]['exclusion'] = "Duplication";
	}

	// profanity filter
	if (!empty($profanity) && !$filtered){

		foreach ($profanity AS $phrase){
			foreach ($data[$lineNo] AS $check){
				if (strpos($check,$phrase)){
					$filtered = true;
         		        	$feedbackChar = 'P';
					$data[$lineNo]['exclusion'] = "$phrase found in $check";
					break;
				}
				
			}
			if ($filtered){
				break;
			}
			
		}
		

	}


	// removal off filtered entries 
	if ($filtered){
		// if this entry has been filtered, as a requirement do not include it
		$positives[$lineNo] = $data[$lineNo]; // store it 
                unset($data[$lineNo]); // remove it 
                $records--; // decrement the records
	} 
	/////////////////////////////////////////////////////
 



	// Visual feedback
        if ($lineNo++%$sizeOfSegment==0){
                message("\n $lineNo \t/ ".($lineNo + $sizeOfSegment)."\t\t",'hold');
        } else {
		if ($lineNo%($lineWidth-30)==0){
			message("\n\t\t\t$feedbackChar",'hold');
		} else {
                	message($feedbackChar,'hold');
		}
        }

  	
}	
fclose($file);

message("\n\nLoaded $records records (".count($positives)." excluded)");
message("\n\nWriting output csv $out");

// now we have a full data set - write data out ensuring all data entries have the same keys with there value (if any)
$lineNo =0;
$fp = fopen($out, 'w');
fputcsv($fp, empty($includes) ? $keys : $includes); 
$feedbackChar = '+';
foreach ($data AS $entryNo=>$entryValues){

	$fields = array();
	
	if (!empty($includes)){
      	foreach($includes AS $key){
        	$fields[$key] = isset($entryValues[$key]) ? $entryValues[$key] : '';
                 
       	}
	} else {
      	foreach($keys AS $key){
        	$fields[$key] = isset($entryValues[$key]) ? $entryValues[$key] : '';
                 
       	}
	
	}
	fputcsv($fp, $fields);	


	// Visual feedback
        if ($lineNo++%$sizeOfSegment==0){
                message("\n $lineNo \t/ ".($lineNo + $sizeOfSegment)."\t\t",'hold');
        } else {
                if ($lineNo%($lineWidth-30)==0){
                        message("\n\t\t\t$feedbackChar",'hold');
                } else {
                        message($feedbackChar,'hold');
                }
        }

}
fclose($fp);
message("\n\nWrote $lineNo records to $out");

if ($positivesOut){

	$feedbackChar = '+';
	message("\n\nWriting positives to $positivesOut");

	$lineNo =0;
	$fp = fopen($positivesOut, 'w');


	if (!empty($includes)){
		$includes['exclusion'] = 'exclusion';
	} else {
		$keys['exclusion'] = 'exclusion';
	}


/*	print_r($keys);
	foreach ($positives AS $entryNo=>$entryValues){
	$fields = array();
	  foreach($keys AS $key){

                  $fields[$key] = isset($entryValues[$key]) ? $entryValues[$key] : '!!!';

            }
	print_r($fields);
die;
}*/

	fputcsv($fp, empty($includes) ? $keys : $includes);

	foreach ($positives AS $entryNo=>$entryValues){ 

        	$fields = array();

        	if (!empty($includes)){
        		foreach($includes AS $key){
                		$fields[$key] = isset($entryValues[$key]) ? $entryValues[$key] : '';

        		}
        	} else {
        		foreach($keys AS $key){

                		$fields[$key] = isset($entryValues[$key]) ? $entryValues[$key] : '';

        		}

        	}	
        	fputcsv($fp, $fields);

		// Visual feedback
        	if ($lineNo++%$sizeOfSegment==0){
                	message("\n $lineNo \t/ ".($lineNo + $sizeOfSegment)."\t\t",'hold');
        	} else {
                	if ($lineNo%($lineWidth-30)==0){
                        	message("\n\t\t\t$feedbackChar",'hold');
                	} else {
                        	message($feedbackChar,'hold');
                	}
        	}

	}
	fclose($fp);
	message("\n\nWrote $lineNo filtered records to $positivesOut");

}

message("\n\nComplete\n\n");

?>
