<?php
namespace SahdevConnector;
use WHMCS\Database\Capsule;

/** Local authority is WHMCS, never a browser-supplied actor or a cloud response. */
final class Access
{
    public static function admin(): object
    {
        $id = (int)($_SESSION['adminid'] ?? 0);
        $admin = $id > 0 ? Capsule::table('tbladmins')->where('id',$id)->where('disabled',0)->first() : null;
        $roles = explode(',',(string)Capsule::table('tbladdonmodules')->where('module','sahdev_cloud')->where('setting','access')->value('value'));
        if (!$admin || !in_array((string)$admin->roleid,$roles,true)) throw new \RuntimeException('Your WHMCS role does not have access to the Sahdev Cloud connector.',403);
        return $admin;
    }
    public static function ticket(int $ticketId, object $admin): object
    {
        self::permission($admin,'View Support Ticket');
        $ticket = Capsule::table('tbltickets')->where('id',$ticketId)->first(['id','did','userid','title','message','status','date','name']);
        // Empty/unknown department assignments fail closed. WHMCS role configuration owns these assignments.
        $departments = array_filter(array_map('intval',explode(',',(string)($admin->supportdepts ?? ''))));
        if (!$ticket || !in_array((int)$ticket->did,$departments,true)) throw new \RuntimeException('Ticket unavailable for your assigned support departments.',403);
        return $ticket;
    }
    public static function permission(object $admin, string $permission): void
    {
        $native = \WHMCS\User\Admin::find((int)$admin->id);
        if (!$native || !$native->hasPermission($permission)) throw new \RuntimeException('Required WHMCS permission: '.$permission.'.',403);
    }
    public static function token(): string
    {
        if (empty($_SESSION['sahdev_cloud_csrf'])) $_SESSION['sahdev_cloud_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['sahdev_cloud_csrf'];
    }
    public static function csrf(string $token): void
    {
        if (!isset($_SESSION['sahdev_cloud_csrf']) || !hash_equals($_SESSION['sahdev_cloud_csrf'],$token)) throw new \RuntimeException('Refresh the page before trying again.',419);
    }
}
