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
    }
}
