<?php

/*
*
* @package yariko
*
*/

namespace Mhc\Inc\Base;

class Deactivate{

    public static function deactivate(){
        flush_rewrite_rules();
        // Clear scheduled queue processor
        $timestamp = wp_next_scheduled('mhc_qb_process_queue_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mhc_qb_process_queue_cron');
        }
    }
}
