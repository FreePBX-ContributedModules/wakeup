#!/usr/bin/php -q
<?php
{
    // Version 1.11 2005-Nov-15
    // ------------
    // CONFIG Parms
    // ------------
    
    // If the application is having problems you can log to this file
    $parm_error_log = '/var/log/asterisk/wakeup.log';
    
    // Set to 1 to turn on the log file
    $parm_debug_on = 1;	
    
    // This is where the Temporary WakeUp Call Files will be created
    $parm_temp_dir = '/tmp/wakeup';
    
    // This is where the WakeUp Call Files will be moved to when finished
    $parm_call_dir = '/var/spool/asterisk/outgoing';

    // How many times to try the call
    $parm_maxretries = 3;
    
    // How long to keep the phone ringing
    $parm_waittime = 20;		// How long to keep the phone ringing
    
    // Number of seconds between retries
    $parm_retrytime = 60;
    
    // Caller ID of who the wakeup call is from Change this to the extension you want to display on the phone
    $parm_wakeupcallerid = '"Wakeup Call" <*62>';
    
    
    // Set to 1 to use the Channel
    // Set to 0 for Caller ID,  Caller IS is assumed just a number ### or "Name Name" <##>
    // The big difference is using caller ID will call ANY phone with that extension number
    // Where using Channel will only wake up the one specific phone
    $parm_chan_ext = 0;

    // ----------------------------------------------------
    // Which application to run when the call is connected. 
    //$parm_application =""; 
    //$parm_application = 'MusicOnHold';
	 //$parm_data = 'wakeconfirm.agi';

	// -- Use this for the ANNOY application
	$parm_application = 'AGI';
	$parm_data = 'wakeconfirm.agi';
    // ----------------------------------------------------

    
    //-------------------
    // END CONFIG PARMS
    //-------------------
    
    GLOBAL	$stdin, $stdout, $stdlog, $result, $parm_debug_on, $parm_test_mode;
    
    // These setting are on the WIKI pages http://www.voip-info.org
    ob_implicit_flush(false);
    set_time_limit(30);
    error_reporting(0);
    
    $stdin = fopen( 'php://stdin', 'r' );
    $stdout = fopen( 'php://stdout', 'w' );
    
    // You will see a whole bunch of this its for development or if you change anything and
    // want to write to a log file in the TMP directory
    if ($parm_debug_on)
    {
        $stdlog = fopen( $parm_error_log, 'w' );
        fputs( $stdlog, "---Start---\n" );
    }
    
    
    // ASTERISK * Sends in a bunch of variables, This accepts them and puts them in an array
    // http://www.voip-info.org/tiki-index.php?page=Asterisk%20AGI%20php
    while ( !feof($stdin) ) 
    {
        $temp = fgets( $stdin );
        
        if ($parm_debug_on)
            fputs( $stdlog, $temp );
        
        // Strip off any new-line characters
        $temp = str_replace( "\n", "", $temp );
        
        $s = explode( ":", $temp );
        $agivar[$s[0]] = trim( $s[1] );
        if ( ( $temp == "") || ($temp == "\n") )
        {
            break;
        }
    } 
    
    
    // There are two ways to contact a phone, by its channel or by its local 
    // extension number.  This next session will extract both sides
    
    // split the Channel  SIP/11-3ef4  or Zap/4-1 into is components
    $channel = $agivar[agi_channel];
    if (preg_match('.^([a-zA-Z]+)/([0-9]+)([0-9a-zA-Z-]*).', $channel, $match) )
    {
        $sta = trim($match[2]);
        $chan = trim($match[1]);
    }
    
    
    
    // Go Split the Caller ID into its components
    $callerid = $agivar[agi_callerid];
    
    // First look for the Extension in <####> 
    if (preg_match('/<([ 0-9]+)>/', $callerid, $match) )
    {
        $cidn = trim($match[1]);
    }
    else	// Did not find the <##...> look for the first number in the string to use as CID
    {
        if (preg_match('/([0-9]+)/', $callerid, $match) )
        {
            $cidn = trim($match[1]);
        }
        else
            $cidn = -1;		// I'm saying an error no caller id # found
    }
    
    
    
    // Check if we have an outstanding Wakeup Call file
    if ( $parm_chan_ext )
        $dir_check = "$chan.$sta.call";
    else
        $dir_check = "ext.$cidn.call";
    
    if ($parm_debug_on)
        fputs( $stdlog, "Checking Directory [$parm_call_dir] Check=[$dir_check]\n" );
    
    
    
    // I started to think we could have many outstanding wakup calls, but then
    // it got very confusing on how to delete just one of them.  I wasn't about
    // to prompt each and every one.  So I went back to JUST ONE wakeup call
    // But this will get a list of all of them incase of problems
    $outc=0;
    $dir_handle = opendir( $parm_call_dir );
    while( $file = readdir($dir_handle ) )
    {
        if ($parm_debug_on)
            fputs( $stdlog, "File=$file\n" );
        
        // Check if we have an outstanding wakup call
        if (strstr( $file, $dir_check ) )
            $out[$outc++] = $file;
        
    }
    closedir( $dir_handle );
    
    
    //=========================================================================
    // This is where we interact with the caller.  Answer the phone and so on
    //=========================================================================
    
    
    $rc = execute_agi( "ANSWER ");
    
    sleep(1);	// Wait for the channel to get created and RTP packets to be sent
				// On my system the welcome you would only hear 'elcome'  So I paused for 1 second

    


    if ( !$rc[result] )
        $rc = execute_agi( "STREAM FILE welcome \"\" ");
    
    // They have an outstanding wakup call
    if ( $outc )
    {
        $i = 0;
        while ( $out[$i] )
        {
            
            $wtime = strtok( $out[$i], '.' );
            
            if ($parm_debug_on)
                fputs( $stdlog, "wakeup found=$out[0] saying time $wtime\n" );
            
            
            say_wakeup( $wtime );
            $i++;
            
        }
        
    }
    
    
    // Check if any outstanding wakeup calls 
    if ( $outc )
    {
        // Start prompting them if they want to create or delete a wakeup call
        while ( !$rc[result] )
        {
            if ( !$rc[result] )
                $rc = execute_agi( "STREAM FILE for-wakeup-call \"12\" ");
            if ( !$rc[result] )
                $rc = execute_agi( "STREAM FILE press-1 \"12\" ");
            if ( !$rc[result] )
                $rc = execute_agi( "STREAM FILE to-cancel-wakeup \"12\" ");
            if ( !$rc[result] )
                $rc = execute_agi( "STREAM FILE press-2 \"12\" ");
            if ( !$rc[result] )
            {
                $rc = execute_agi( "WAIT FOR DIGIT 15000");
            }
            if ( $rc[result] != -1 )
            {
                if ( $rc[result] == 49 || $rc[result] == 50 )
                {
                    ; // Do nothing
                }
                else
                {
                    // This was just for fun, if they press something other than 1 or 2
                    $rc[result] = 0;
                    $rc = execute_agi( "STREAM FILE you-dialed-wrong-number \"\" ");
                    $rc = execute_agi( "STREAM FILE wrong-try-again-smarty \"\" ");
                }
                
            }
        }
    }
    else	// Default to Creating a wakeup call
        $rc[result] = '49';
    // Process which key they pressed
    //  I check most of my Return Codes incase the call is hung up
    //
    //  Being a phone person, I want to be able to skip the prompts, so I allow for entry over
    //  The prompts.  Makes for more code, but make for quicker entry after you know what to key
    //
    switch( $rc[result] )
    {
    case '49':	// Pressed 1  - This is to schedule a NEW wakeup call
        {
            $rc[result] = 0;
            while ( !$rc[result] )
            {
                $rc = execute_agi( "STREAM FILE please-enter-the \"0123456789\" ");
                
                if ( !$rc[result] )
                    $rc = execute_agi( "STREAM FILE time \"0123456789\" ");
                if ( !$rc[result] )
                    $rc = execute_agi( "STREAM FILE for \"0123456789\" ");
                if ( !$rc[result] )
                    $rc = execute_agi( "STREAM FILE your \"0123456789\" ");
                
                // If we get here, they haven't pressed anything yet.
                if ( !$rc[result] )
                    $rc = execute_agi( "GET DATA wakeup-call 5000 4");
                
                if ( $rc[result] != -1 )
                {


                    if ($parm_debug_on)
                        fputs( $stdlog, "We have num digits:" . strlen( $rc[result]) . "- $rc[result] \r\n\r\n" );


                    // This check how many digits, if 2 then they pre-pressed a digit, 
                    // Otherwise it will be 4
                    if ( strlen( $rc[result] ) == 2 )
                    {
                        $num= $rc[result]-48;
                        while ( strlen($num) < 4 && $rc[result] > 0 )
                        {
                            $rc = execute_agi( "WAIT FOR DIGIT 15000");
                            if ( $rc[result] >= 48 && $rc[result] <= 57 )
                                $num .= $rc[result] - 48;
                            else	
                                $rc[result] = 0;
                            
                        }
                        if (strlen($num) == 4 )
                            $rc[result] = $num;
                    }

                    if ($parm_debug_on)
                        fputs( $stdlog, "Checking Results [$rc[result]] \r\n\r\n" );

                    // They entered 4 digits,  check if its a valid time or they hung up
                    if ( $rc[result] > 2359 || strlen( $rc[result]) < 3 || substr($rc[result],2,2) > 59 || $rc[result] < 0)
                    {
                        $rc[result] = 0;
                        //$rc = execute_agi( "STREAM FILE invalid \"\" ");
                        //$rc = execute_agi( "STREAM FILE time \"\" ");
                        $rc = execute_agi( "STREAM FILE please-try-again \"\" ");
                    }

                    if (strlen( $rc[result] ) == 4 && $rc[result] == 0 )
                        $rc[result] = -2;	// Special 00:00 time
                    
                }	
            }
            
            if ( $rc[result] == -2 )
                $rc[result] = '0000';	
            else if ( $rc[result] == -1 )
                exit;			// The user hung up
            
            
            
            // Save the time entered
            $wtime = $rc[result];
            
            
            // We don't know who the user is, so if its less than 1300 it could be AM or PM, so prompt
            // them for am pm
            if ( $wtime != -1 && $wtime < 1300 )
            {
                $rc[result] = 0;
                while ( !$rc[result] )
                {
                    
                    if ( !$rc[result] )
                        $rc = execute_agi( "GET DATA 1-for-am-2-for-pm 15000 1");
                    
                }	
                
                switch( $rc[result] )
                {
                case '1':			// Set to AM should be under 1159 or less
                    if ( $wtime > 1159 )
                        $wtime -= 1200;
                    $rc[result] = 0;
                    break;
                    
                case '2':			// Set to PM should be equal or over 1200
                    if ( $wtime < 1159 )
                        $wtime += 1200;
                    $rc[result] = 0;
                    break;
                }
            }
            
            
            // At this point we have a millitary time in the $wtime variable
            if ( $parm_chan_ext )
			{
                $wakefile = "$parm_temp_dir/$wtime.$chan.$sta.call";
				$callfile = "$parm_call_dir/$wtime.$chan.$sta.call";
			}
            else
			{
                $wakefile = "$parm_temp_dir/$wtime.ext.$cidn.call";
                $callfile = "$parm_call_dir/$wtime.ext.$cidn.call";
			}            

            if ($parm_debug_on)
                fputs( $stdlog, "Wakeup File [$wakefile]\n" );
            
            // Open up a wakeup file to write it out.
            $wuc = fopen( $wakefile, 'w');
            
            if ( $wuc )
            {
                // Delete any old Wakeup call files this one will override 
                for ($i=0; $i < $outc; $i++ )
                {
                    if( file_exists( "$parm_call_dir/$out[$i]" ) )
                    {
                        if ($parm_debug_on)
                            fputs( $stdlog, "Unlinking Old File [$parm_call_dir/$out[$i]]\n" );
                        
                        unlink( "$parm_call_dir/$out[$i]" );
                    }
                }
                
                // I've noticed that the other WAKEUP example has a different format.  This worked for me
                // Here is where we either make the call to the Extension or the Channel.  Extension
                // is the better way to go, but required the caller ID information.  Where Channel
                // should always get you back to where you were called from, provided its on your system
                if ( $parm_chan_ext )
                    fputs( $wuc, "channel: $chan/$sta\n" );
                else
                    fputs( $wuc, "channel: Local/$cidn@$agivar[agi_context]\n" );
                
                fputs( $wuc, "maxretries: $parm_maxretries\n");
                fputs( $wuc, "retrytime: $parm_retrytime\n");
                fputs( $wuc, "waittime: $parm_waittime\n");
                fputs( $wuc, "callerid: $parm_wakeupcallerid\n");

				fputs( $wuc, "application: $parm_application\n");
				fputs( $wuc, "data: $parm_data\n");
                
                fclose( $wuc );
		
				$w = getdate();

				$w['hours']   = substr( $wtime, 0, 2 );
				$w['minutes'] = substr( $wtime, 2, 2 );
				
				$time_wakeup = mktime( substr( $wtime, 0, 2 ), substr( $wtime, 2, 2 ), 0, $w[mon], $w[mday], $w[year] );

				$time_now = time( );

                if ($parm_debug_on)
                    fputs( $stdlog, 'time_wakeup=' . date('l dS \of F Y h:i:s A', $time_wakeup) . " ($time_wakeup) | time_now=" . date('l dS \of F Y h:i:s A',$time_now) . " ($time_now)\n" );

				if ( $time_wakeup <= $time_now )
					$time_wakeup += 86400; // Add One Day on

                if ($parm_debug_on)
                    fputs( $stdlog, 'Setting WAKEUP file to =' . date('l dS \of F Y h:i:s A', $time_wakeup) . " - $time_wakeup\n" );


				touch( $wakefile, $time_wakeup, $time_wakeup );

				rename( $wakefile, $callfile );
				
            }
            else
            {
                // Couldn't open the file.  Make sure you created the /var/lib/asterisk/wakeups directory
                if ($parm_debug_on)
                    fputs( $stdlog, "Error opening file [$wakefile]\n" );
                
                $rc = execute_agi( "STREAM FILE something-terribly-wrong \"\" ");
                if ( !$rc[result] )
                    $rc = execute_agi( "STREAM FILE goodbye \"\" ");
                if ( !$rc[result] )
                    $rc = execute_agi( "HANGUP");
                exit;
            }
            
            //  If we have a caller ID  and waking up by extension say the extension number
            if ( $cidn && $parm_chan_ext == 0 )
            {
                $rc = execute_agi( "STREAM FILE for \"\" ");
                
                if ( !$rc[result] )
                    $rc = execute_agi( "STREAM FILE extension \"\" ");
                
                                    
                if ( !$rc[result] )
                    $rc = execute_agi( "SAY DIGITS $cidn \"\" ");
                
            }
            
            say_wakeup( $wtime );
            $rc[result] = 0;
            
        }
        break;
        
        // This is the END Of a new wakeup call
        
        
        case '50':	// Pressed 2 - Delete old wakeup calls
            {
                // Go through the list of old files and unlink/delete them
                for ($i=0; $i < $outc; $i++ )
                {
                    if( file_exists( "$parm_call_dir/$out[$i]" ) )
                    {
                        if ($parm_debug_on)
                            fputs( $stdlog, "Unlinking Wakeup File [$parm_call_dir/$out[$i]]\n" );
                        
                        unlink( "$parm_call_dir/$out[$i]" );
                    }
                }
                
                // If Caller ID and recording by Extension then say the extension
                if ( $cidn && $parm_chan_ext == 0 )
                {
                    $rc = execute_agi( "STREAM FILE for \"\" ");
                    
                    if ( !$rc[result] )
                        $rc = execute_agi( "STREAM FILE extension \"\" ");
                    
                    
                    $L = strlen( $cidn );
                    
                    for( $i = 0; $i < $L && !$rc[result]; $i++ )
                    {
                        $cid_digits = substr( $cidn, $i, 1 );
                        
                        if ( !$rc[result] )
                            $rc = execute_agi( "SAY NUMBER $cid_digits \"\" ");
                    }
                }
                
                $rc = execute_agi( "STREAM FILE wakeup-call-cancelled \"\" ");
                
                
            }
            break;
            
    }
    
    if ( !$rc[result] )
        $rc = execute_agi( "STREAM FILE goodbye \"\" ");
    if ( !$rc[result] )
        $rc = execute_agi( "HANGUP");
    if ($parm_debug_on)
        fclose($stdlog);
    exit;
}


// ----------------------------------------------
// This will say military time in AM/PM format
// ----------------------------------------------
function say_wakeup( $wtime )
{
    GLOBAL	$stdin, $stdout, $stdlog, $parm_debug_on;
    
    $pm = 0;
    
    if ($wtime > 1159 )
    {
        $wtime -=1200;
        $pm = 1;
    }
    
    if ($wtime <= 59 )
        $wtime += 1200;
    
    if ( strlen( $wtime ) == 3 )
        $wtime = '0' . $wtime;
    
    
    $h = substr( $wtime, 0, 2 );
    $h1 = substr( $wtime, 0, 1 );
    $h2 = substr( $wtime, 1, 1 );
    $m = substr( $wtime, 2, 2 );
    $m1 = substr( $wtime, 2, 1);
    $m2 = substr( $wtime, 3, 1);
    
    
    if ($parm_debug_on)
        fputs( $stdlog, "Wakeup time is set to $wtime\n" );
    
    $rc = execute_agi( "STREAM FILE rqsted-wakeup-for \"\" ");
    
    if ( !$rc[result] )
    {
        if ( $h1 == 0 ) 
            $rc = execute_agi( "SAY NUMBER $h2 \"\"");
        else
            $rc = execute_agi( "SAY NUMBER $h \"\"");
        
        if ( !$rc[result] )
        {
            if ($m == 0 )
                $rc = execute_agi( "STREAM FILE digits/oclock \"\" ");
            else
            {		
                if ( $m1 == 0 ) 
                {
                    $rc = execute_agi( "STREAM FILE digits/oh \"\" ");
                    $rc = execute_agi( "SAY NUMBER $m2 \"\" ");
                }
                else
                    $rc = execute_agi( "SAY NUMBER $m \"\"");
            }
            if ( !$rc[result] )
            {
                if ( $pm )
                    $rc = execute_agi( "STREAM FILE digits/p-m \"\" ");
                else
                    $rc = execute_agi( "STREAM FILE digits/a-m \"\" ");
            }
        }
    }	
}


//--------------------------------------------------
// This function will send out the command and get 
//	the response back
//--------------------------------------------------
function execute_agi( $command )
{
    GLOBAL	$stdin, $stdout, $stdlog, $parm_debug_on;
    
    fputs( $stdout, $command . "\n" );
    fflush( $stdout );
    if ($parm_debug_on)
        fputs( $stdlog, $command . "\n" );
    
    $resp = fgets( $stdin, 4096 );
    
    if ($parm_debug_on)
        fputs( $stdlog, $resp );
    
    if ( preg_match("/^([0-9]{1,3}) (.*)/", $resp, $matches) ) 
    {
        if (preg_match('/result=([-0-9a-zA-Z]*)(.*)/', $matches[2], $match)) 
        {
            $arr['code'] = $matches[1];
            $arr['result'] = $match[1];
            if (isset($match[3]) && $match[3])
                $arr['data'] = $match[3];
            return $arr;
        } 
        else 
        {
            if ($parm_debug_on)
                fputs( $stdlog, "Couldn't figure out returned string, Returning code=$matches[1] result=0\n" );	
            $arr['code'] = $matches[1];
            $arr['result'] = 0;
            return $arr;
        }
   	} 
    else 
    {
        if ($parm_debug_on)
            fputs( $stdlog, "Could not process string, Returning -1\n" );
        $arr['code'] = -1;
        $arr['result'] = -1;
        return $arr;
    }
} 
?>
