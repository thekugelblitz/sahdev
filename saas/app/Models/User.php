<?php
namespace App\Models;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasUuids, Notifiable, MustVerifyEmail;
    protected $fillable = ['name','email','password'];
    protected $hidden = ['password','remember_token','totp_secret'];
    protected function casts(): array { return ['email_verified_at'=>'datetime','password'=>'hashed','is_operator'=>'boolean','totp_secret'=>'encrypted']; }
}
