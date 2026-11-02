<?php
namespace Sahdev\Lib;

final class CopilotPresentation
{
    public static function assets(): string
    {
        static $included = false;
        if ($included) return '';
        $included = true;
        return '<style>' . file_get_contents(dirname(__DIR__) . '/ui/copilot.css') . '</style><script>' . file_get_contents(dirname(__DIR__) . '/ui/copilot.js') . '</script>';
    }
}
