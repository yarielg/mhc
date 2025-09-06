<?php

namespace Mhc\Inc;

final class Init{

    public static function get_services(){

        return [
            Base\Enqueue::class,
            Base\Settings::class,
            Base\Ajax::class,
            Base\Shortcodes::class,
            Controllers\PatientsController::class,
            Controllers\WorkersController::class,
            Controllers\RolesController::class,
            Controllers\SpecialRatesController::class,
            Controllers\DashboardController::class,
            Controllers\PayrollController::class,
            Controllers\PayrollSegmentController::class,
            Controllers\PdfController::class,
            Controllers\SeedController::class,
            Controllers\ReportsController::class,

            //Base\Ajax::class,
        ];
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
