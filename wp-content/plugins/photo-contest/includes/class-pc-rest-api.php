<?php defined( 'ABSPATH' ) || exit;
class PC_REST_API {
    private static ?self $instance = null;
    public static function get_instance(): self { self::$instance ??= new self(); return self::$instance; }
    private function __construct() {}
}
