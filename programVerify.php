<?php
date_default_timezone_set("UTC"); 
$debug = false;
$runtime = time();
$output_file = 'device_program_results_'.$runtime.'.csv';

print("
	* ********* Device Program Success Validation ********* *
	* This tool will evaluate the software's log file to    *
	* determine which ESNs that were attempted to be        *
	* programmed completed successfully and which did not.  *
	* In default mode every ESN that the software has       *
	* processed will be checked and results returned. Users *
	* can optionally supply an ESN list to check against EG *
	* '0-123456                                             *
	*  0-455678                                             *
	*  0-987653'                                            *
	* If a specific list is given, the results will only    *
	* pertain to those.                                     *
	*                                                       *
	* If ESN is programmed more than 1 time, the most       *
	* recent attempt will be evaluated as final truth thus  *
	* several programming failures followed by a success    *
	* will be presented as a successful program of ESN.     *
	* programVerify.php [string log_file] [string ESN/s]    *
	* Author: @KevinFranke                                  *
	* Date: August 2019                                     *
	* ***************************************************** *
	");
print("\n");
readline("Enter to continue...");
$limited_search = false;
$valid_log_file = false;
$stats = array();

if( !$argv[1] ){
	print("No log file specified at startup! \n");
	$io_log_file = trim(readline("Please enter path to log file for processing? "));
	// fails if log file name has spaces
	if( file_exists($io_log_file) ) $valid_log_file = true;
}
else{
	$io_log_file = $argv[1];
	// fails if log file name has spaces
	if( file_exists($io_log_file) ) $valid_log_file = true;
}
$log_file = $io_log_file;

if( isset($argv[2]) )
{
	$limited_search = true;
	$search_esns = array();
	preg_match_all('/3[0-9]{6}/', $argv[2], $search_esns);
	print("Search ESNs: \n");
	print( implode("\n", $search_esns[0]) . "\n");
	$stats['mode'] = 'filtered';
}
else
{
	print("Searching all ESNs \n");
	$stats['mode'] = 'all';
}

print("Supplied file set to: $log_file\n");

if(!$valid_log_file) die("Cannot find specified log file: $log_file\n");

$records = array();

$handle = @fopen($log_file, "r");
if ($handle)
{
    while (($buffer = fgets($handle, 4096)) !== false)
    {
        // search for all ESNs list entries and add to records. 
        // Note BTLE will not have a ListViewItem entry oddly
        // 6/7/2019,10:31:06,ListViewItem B| 3315175 :: 1.713 - COM38 -- 9600
        // 5/7/2019,10:54:39,ListViewItem 7877| 3316369 :: 1.7.0 - COM28 -- 9600
    	
		$query_ptn = '/([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4},[0-9]{2}:[0-9]{2}:[0-9]{2}).*ListViewItem.*(3[0-9]{6})/';
		preg_match($query_ptn, $buffer, $matches);
		if( count($matches === 3) && isset($matches[1]) && isset($matches[2]) )
		{
			// add query record and set state to fail on intial
			$records[$matches[2]][] = array(
				'timestamp' => strtotime(str_replace(',', ' ', $matches[1])), 
				'esn' => $matches[2], 
				'state' => 'FAIL',
				'desc' => 'esn only initialized');
		} 
    }
    print "Found " . count($records) . " ListViewItem ESN records\n";
    if($debug) print_r($records);

    fseek($handle, 0); // rewind
    while (($buffer = fgets($handle, 4096)) !== false)
    {
    	// search for success
        // via serial cable
        // 6/7/2019,11:11:07,Successfully queried the ESN 3313740 on: COM41 (via Cable)
        $pass_ptn = '/([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4},[0-9]{2}:[0-9]{2}:[0-9]{2}).*Successfully(?: queried the ESN).*(3[0-9]{6})/';
		preg_match($pass_ptn, $buffer, $matches);
		if( count($matches === 3) && isset($matches[1]) && isset($matches[2]) )
		{
			if($debug) print "Found successful program response for: $matches[2] via serial cable\n";
			// add successful record to array keyed on ESN
			$records[$matches[2]][] = array(
				'timestamp' => strtotime(str_replace(',', ' ', $matches[1])), 
				'esn' => $matches[2], 
				'state' => 'PASS',
				'desc' => 'successful program response serial');
		}
		// via btle
		// 7/31/2019,11:59:08,Successfully Programmed: 3325502 via Bluetooth (via BTLE)
		$pass_ptn = '/([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4},[0-9]{2}:[0-9]{2}:[0-9]{2}).*Successfully(?: Programmed:).*(3[0-9]{6})/';
		preg_match($pass_ptn, $buffer, $matches);
		if( count($matches === 3) && isset($matches[1]) && isset($matches[2]) )
		{
			if($debug) print "Found successful program response for: $matches[2] via BTLE\n";
			// add successful record to array keyed on ESN
			$records[$matches[2]][] = array(
				'timestamp' => strtotime(str_replace(',', ' ', $matches[1])), 
				'esn' => $matches[2], 
				'state' => 'PASS',
				'desc' => 'successful program response btle');
		}

    }

    if (!feof($handle))
    {
        echo "Error: unexpected fgets() fail\n";
    }
    fclose($handle);
}

print "Updated records with status...\n";
if($debug) print_r($records);

// loop through records and pull out max timestamp record
$results = array();
foreach ($records as $record) {
	$item = array();
	$ts = 0;
	foreach($record as $entry)
	{
		if($entry['timestamp'] > $ts)
		{
			if($debug) print "Entry of: ".$entry['timestamp']. " for " . $entry['esn'] . " is > than ts: " . $ts . "\n";
			$item['esn'] = $entry['esn'];
			$item['timestamp'] = $entry['timestamp'];
			$item['state'] = $entry['state'];
			$item['desc'] = $entry['desc'];
			$results[$entry['esn']] = $item;
		}
		else
		{
			if($debug) print "Entry of: " . $entry['timestamp'] . " for " . $entry['esn'] . " is not > than ts: " . $ts . "\n";
		}
		$ts = $entry['timestamp'];	
	}
}
$stats['results'] = count($results);
print "Results are in...\n";

if($limited_search)
{
	print "Filtering results for supplied ". count($search_esns[0]) ." ESNs\n";
	$filtered = array();
	foreach ($search_esns[0] as $esn)
	{
		if( isset($results[$esn]) ) $filtered[$esn] = $results[$esn];
		else $filtered[$esn] = array('esn' => $esn, 'timestamp' => 0, 'state' => 'FAIL', 'desc' => 'record for esn not found');
	}
	if($debug) print_r($filtered);
	$stats['results'] = count($filtered);
}

// write records to csv file
if($limited_search)
{
	$columns = '';
	$rows = '';
	foreach ($filtered as $entry)
	{
		$columns = implode(',', array_keys($entry)) . "\n";
		$row = implode(',', array_values($entry)) . "\n";
		$rows .= $row;
		if($entry['state'] == 'PASS') $stats['passed'] += 1;
		if($entry['state'] == 'FAIL') $stats['failed'] += 1;
	}
	$fp = fopen($output_file, 'w');
	fwrite($fp, $columns);
	fwrite($fp, $rows);
	fclose($fp);
}
else
{
	$columns = '';
	$rows = '';
	foreach ($results as $entry)
	{
		$columns = implode(',', array_keys($entry)) . "\n";
		$row = implode(',', array_values($entry)) . "\n";
		$rows .= $row;
		if($entry['state'] == 'PASS') $stats['passed'] += 1;
		if($entry['state'] == 'FAIL') $stats['failed'] += 1;
	}
	$fp = fopen('device_program_results_'.$runtime.'.csv', 'w');
	fwrite($fp, $columns);
	fwrite($fp, $rows);
	fclose($fp);
}

// stats. mode, X total, Y pass, Z fail
$data = file_get_contents($output_file);
$summary = '';
foreach ($stats as $key => $value) {
	$summary .= str_repeat(',', count($columns)+4) . $key . ':,' . $value . "\n";
}
print "Statistics\n";
print str_replace(',', '', $summary);
file_put_contents($output_file, $summary . $data);
print "Wrote data to: " . $output_file . "\n";
