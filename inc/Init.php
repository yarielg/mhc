<?php

namespace Mhc\Inc;

final class Init{

    public static function get_services(){

        $services = [
            Base\Enqueue::class,
            Base\Settings::class,
            Base\Shortcodes::class,
            Controllers\PatientsController::class,
            Controllers\WorkersController::class,
            Controllers\RolesController::class,
            Controllers\SpecialRatesController::class,
            Controllers\DashboardController::class,
            Controllers\PayrollController::class,
            Controllers\PayrollSegmentController::class,
            Controllers\PdfController::class,
            Controllers\ReportsController::class,
            Controllers\QuickBooksController::class,
            Controllers\InsurersController::class,
        ];

        // Destructive/dev-only tooling: dropping every plugin table and seeding fake
        // records must never be reachable on a live clinic. Opt in from wp-config.php
        // with: define('MHC_ENABLE_DEV_TOOLS', true);
        if (defined('MHC_ENABLE_DEV_TOOLS') && MHC_ENABLE_DEV_TOOLS) {
            $services[] = Base\Ajax::class;
            $services[] = Controllers\SeedController::class;
        }

        return $services;
    }

    public static function register_services(){

        foreach (self::get_services() as $class) {
            $service = self::instantiate($class);
            if(method_exists( $service , 'register')){
                $service->register();
            }
        }

    }

    private static function instantiate($class){

        $service = new $class();
        return $service;
    }

}
?>
