<?php
/*
Plugin Name: WordPress Background Worker
Description: Aysinchrounous Background Worker for WordPress
Author: todiadiyatmo
Author URI: http://todiadiyatmo.com/
Version: 0.3
Text Domain: wordpress-importer
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

/**
 * Run Pheanstalkd Queue.
 *
 * Returns an error if the option didn't exist.
 *
 * ## OPTIONS
 *
 * <listen>
 * : Listen mode.
 *
 * ## EXAMPLES
 *
 *     $ wp background-worker
 */
	
define('BG_WORKER_DB_VERSION',6);
define('BG_WORKER_DB_NAME','bg_jobs');

if( !defined( 'WP_BACKGROUND_WORKER_QUEUE_NAME' ) )
	define( 'WP_BACKGROUND_WORKER_QUEUE_NAME', 'default' );

$installed_version = intval( get_option('BG_WORKER_DB_VERSION') );


if( $installed_version < BG_WORKER_DB_VERSION) {
	// drop and re create
	if( $installed_version <= 5 ) {
		global $wpdb;
  		$db_name = $wpdb->prefix."jobs";

		$sql = "DROP TABLE ".$db_name.";";
		$wpdb->query($sql);
 
		wp_background_worker_install_db();
	}
}


update_option('BG_WORKER_DB_VERSION', BG_WORKER_DB_VERSION);

function wp_background_worker_install_db() {
   	global $wpdb;
  	$db_name = $wpdb->prefix.BG_WORKER_DB_NAME;
 
	if($wpdb->get_var("show tables like '$db_name'") != $db_name) 
	{
		$sql = "CREATE TABLE ".$db_name." 
				( `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, 
				  `queue` varchar(512) COLLATE utf8_unicode_ci NOT NULL, 
				  `payload` longtext COLLATE utf8_unicode_ci NOT NULL, 
				  `attempts` tinyint(3) UNSIGNED NOT NULL,
				  PRIMARY KEY(`id`) );";
 
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
 
}
// run the install scripts upon plugin activation
register_activation_hook(__FILE__,'wp_background_worker_install_db');

add_action( "admin_menu", "background_worker_menu_page" );
function background_worker_menu_page() {
	add_management_page( __('Background Worker'), __('Background Worker'), 'manage_options', 'background_worker_log', 'background_worker_log_page_handler' );
}


function background_worker_log_page_handler() { ?>
	<div id="" class="wrap">
	
		<h1>Background Worker Log</h1>
		
		<div class="progress">
			<?php 
					
				if ( !defined('BACKGROUND_WORKER_LOG') || !BACKGROUND_WORKER_LOG ) {
					$content = 'No Log to diplay please define BACKGROUND_WORKER_LOG file in your wp-config.php';
				} elseif( !function_exists('file_get_contents')) {
					$content = 'Cannot read log file_get_contents() function did not exists.';
				}
				else {
					$filearray = file_get_contents(BACKGROUND_WORKER_LOG);
					$filearray = explode("\n",$filearray);


					$filearray = array_slice($filearray, -100);

					$filearray = array_reverse($filearray);

					$content = '';
					if ( $filearray ) {
						foreach ($filearray as $key => $value) {
							$content .= $value . "\n";
						}
					if ( $content == '')
						$content = "Nothing to read, permission problem maybe ? ";
					
					} else {
						$content = 'No Log';
					}
				} 
			?>
			<textarea class="log-text" rows="20" style='width:1000px'><?php echo $content; ?></textarea>
		</div>
	</div>
	<?php 
}

if( !defined( 'WP_CLI' ) ) {
	return;
}

function wp_background_add_job( $job, $queue = WP_BACKGROUND_WORKER_QUEUE_NAME ) {
	global $wpdb;

	// Serialize class
	$job_data = serialize($job);

	$wpdb->insert( 
		$wpdb->prefix.BG_WORKER_DB_NAME, 
			array( 
				'queue' => $queue, 
				'payload' => $job_data,
				'attempts' => 0 
			)
		);
}

function wp_background_worker_execute_job($queue = WP_BACKGROUND_WORKER_QUEUE_NAME) {
	global $wpdb;

	$job = $wpdb->get_row( "SELECT * FROM ".$wpdb->prefix.BG_WORKER_DB_NAME." WHERE attempts <= 2 AND queue='$queue' ORDER BY id ASC" );

	// No Job
	if( !$job ) {		
		wp_background_worker_debug("No job available..");
		return;
	}

    $job_data = unserialize(@$job->payload);

    if(!$job_data) {

    	wp_background_worker_debug("Delete malformated job..");

    	$wpdb->delete( 
			$wpdb->prefix.BG_WORKER_DB_NAME, 
			array( 'id' => $job->id  )
		);

    	return;
    }

    wp_background_worker_debug("Working on job ID = {$job->id} ");

	$wpdb->update( 
		$wpdb->prefix.BG_WORKER_DB_NAME, 
		array( 
			'attempts' => $job->attempts+1,	
		), 
		array( 'id' => $job->id  )
	);

    try{	
	    $function = $job_data->function;  
    	$data = is_null($job_data->user_data) ? false : $job_data->user_data ;

    	if( is_callable($function) ) 
    		$function($data);
    	else 
    		call_user_func_array($function,$data);

    	//delete data
    	$wpdb->delete( 
			$wpdb->prefix.BG_WORKER_DB_NAME, 
			array( 'id' => $job->id  )
		);
    }
    catch (Exception $e){
    	 WP_CLI::error( "Caught exception: ".$e->getMessage() );
    }

}

/**
 * Run background worker listener.
 *
 * listen = Running the listener, WordPress is reboot after each job exectuion
 * listen-loop = Running the listener in daemon mode, WordPress is not reboot after each job execution
 */

$background_worker_cmd = function( $args = array() ) { 


	if(  ( isset( $args[0] ) && 'listen' === $args[0] ) )
		$listen = true;
	else
		$listen = false;
	
	if( $listen && !function_exists('exec') ) {
		wp_background_worker_debug( "Cannot run WordPress background worker on `listen` mode, please use `listen-loop` instead" );
	}
		
	if(  isset( $args[0] ) && 'listen-loop' === $args[0])
		$listen_loop = true;
	else
		$listen_loop = false;
	
	// listen-loop mode
	if( $listen_loop ) {
	    
	    while( true ) {
	    	wp_background_worker_check_memory();
	        
	        usleep(250000);
	        wp_background_worker_execute_job();
	    }

	}
	else if ( $listen) {
		// start daemon
		while(true) {

			$output = array();

			wp_background_worker_check_memory();
			$args = array();

			usleep(250000);
			wp_background_worker_debug("Spawn next worker");

			$_ = $_SERVER['argv'][0];  // or full path to php binary
		    
		    array_unshift($args,'background-worker');
		    	
		    if( posix_geteuid() == 0 && !in_array('--allow-root', $args) )
			    array_unshift($args,'--allow-root');

			$args = implode(" ", $args);
			$cmd = $_." ".$args." 2>&1";

			exec( $cmd ,$output);

			foreach ( $output as $echo) {
				WP_CLI::log($echo);
			}

			wp_background_worker_output_buffer_check();
	
		}
	}
	else {

		wp_background_worker_execute_job();
	}

	wp_background_worker_output_buffer_check();
	exit();
};

function wp_background_worker_check_memory() {

	if( WP_DEBUG ) {
		$usage = memory_get_usage() / 1024 / 1024;
		wp_background_worker_debug(  "Memory Usage : ".round( $usage, 2 )."MB" );		
	}

 	if ( ( memory_get_usage() / 1024 / 1024) >= WP_MEMORY_LIMIT ) {
        wp_background_worker_debug( "Memory limit execeed" );
        exit();
    }

}

WP_CLI::add_command( 'background-worker', $background_worker_cmd );
 
function wp_background_worker_debug( $msg ) {

	if( WP_DEBUG ) {
		WP_CLI::log($msg);
	}
}

function wp_background_worker_output_buffer_check() {

    @ob_flush();
    @flush();
}


