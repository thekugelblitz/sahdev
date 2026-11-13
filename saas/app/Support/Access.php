<?php
namespace App\Support;
use App\Models\User;
use Illuminate\Support\Facades\DB;
final class Access
{
    public static function membership(User $user, string $org): object
    {
        return DB::table('memberships')->where('organization_id',$org)->where('user_id',$user->id)->first() ?? abort(403);
    }
    public static function requireRole(User $user, string $org, array $roles): object
    {
        $member = self::membership($user,$org);
        abort_unless(in_array($member->role,$roles,true),403);
        return $member;
    }
    public static function brands(User $user, string $org)
    {
        $member = self::membership($user,$org);
        $query = DB::table('brands')->where('organization_id',$org);
        if ($member->role === 'member') $query->whereIn('id', DB::table('brand_memberships')->where('user_id',$user->id)->select('brand_id'));
        return $query;
    }
    public static function brand(User $user, string $org, string $id, bool $operate = false): object
    {
        $brand = self::brands($user,$org)->where('id',$id)->first() ?? abort(404);
        $member = self::membership($user,$org);
        if ($operate && !in_array($member->role,['owner','admin'],true)) {
            abort_unless($member->role === 'member' && DB::table('brand_memberships')->where('brand_id',$id)->where('user_id',$user->id)->where('role','operator')->exists(),403);
        }
        return $brand;
    }
}
