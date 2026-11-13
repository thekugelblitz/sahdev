<?php
namespace WHMCS\User;
/** Test double for WHMCS's native permission API, backed by the disposable fixture. */
class Admin
{
    public static array $permissions = [];
    public static function find(int $id): ?self { return \Illuminate\Support\Facades\DB::table('tbladmins')->where('id',$id)->exists() ? new self() : null; }
    public function hasPermission(string $name): bool { return in_array($name,self::$permissions,true); }
}
