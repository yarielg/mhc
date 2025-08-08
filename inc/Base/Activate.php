<?php

/*
*
* @package yariko
*
*/
namespace Mhc\Inc\Base;

class Activate{

    public static function activate(){
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_name1 = $wpdb->prefix . 'cbf_recipes_cookbooks';
        $table_name2 = $wpdb->prefix . 'cbf_users_collaborators';
	    $table_name3 = $wpdb->prefix . 'cbf_comments';

        $sql1 = "CREATE TABLE $table_name1 (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          recipe_id INT NOT NULL,
          cookbook_id INT NOT NULL,
          PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $table_name2 (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          user_id INT NOT NULL,
          collaborator_id INT NOT NULL,
          token varchar(22) NOT NULL,
          PRIMARY KEY  (id)
        ) $charset_collate;";

	    $sql3 = "CREATE TABLE $table_name3 (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          admin INT NOT NULL,
          cookbook_id INT NOT NULL,
          comment varchar(500) NOT NULL,
          created datetime NOT NULL,
          PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
    }
}
